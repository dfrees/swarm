<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Listener;

use Activity\Model\Activity;
use Application\Config\ConfigManager;
use Application\Permissions\Exception\ForbiddenException;
use Events\Listener\AbstractEventListener;
use P4\Spec\Change;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Lock\Lock;
use Reviews\Filter\GitInfo;
use Reviews\Model\GitReview;
use Reviews\Model\Review as ReviewModel;
use Workflow\Model\IWorkflow;
use Laminas\EventManager\Event;
use P4\Exception;

// attach listeners to:
// - create/update review when change is shelved or committed
// - process review when its created or updated
class ShelveCommit extends AbstractEventListener
{
    /**
     * Process the shelve and commit tasks to determine whether it creates or updates any review.
     * We use the advisory locking for the whole process to avoid potential race condition where
     * another process tries to do the same thing.
     * @param Event $event
     * @throws \Exception
     * @throws \P4\Connection\Exception\CommandException
     * @throws \Application\Config\ConfigException
     */
    public function lockThenProcess(Event $event)
    {
        parent::log($event);
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $change   = $event->getParam('change');

        // if we didn't get a change to work with, bail
        if (!$change instanceof Change) {
            return;
        }

        $lock = new Lock(ReviewModel::LOCK_CHANGE_PREFIX . $change->getId(), $p4Admin);
        $lock->lock();

        try {
            $this->processShelveCommit($event);
        } catch (\Exception $e) {
            // we handle this after unlocking
        }

        $lock->unlock();

        if (isset($e)) {
            throw $e;
        }
    }


    public function processGitShelve(Event $event)
    {
        // Deal with git-fusion initiated reviews before traditional p4 reviews.
        //
        // If the shelf is already a git initiated review we'll update it.
        //
        // If the shelf has git-fusion keywords indicating this is a new review
        // translate the existing shelf into a new git-review and process it.
        $p4Admin = $this->services->get('p4_admin');
        $queue   = $this->services->get('queue');
        $config  = $this->services->get('config');
        $change  = $event->getParam('change');

        // if we didn't get a pending change to work with, bail
        if (!$change instanceof Change || !$change->isPending()) {
            return;
        }

        // if the change is by a user that is ignored for the purpose of reviews, bail
        $ignored = isset($config['reviews']['ignored_users']) ? $config['reviews']['ignored_users'] : null;
        if ($p4Admin->stringMatches($change->getUser(), (array) $ignored)) {
            return;
        }

        // if this change doesn't have a valid looking git-fusion style review-id
        // there's no need to further examine it here, return
        $gitInfo = new GitInfo($change->getDescription());
        if ($gitInfo->get('review-id') != $change->getId()) {
            return;
        }

        try {
            // using the change id, verify if a git review already exists
            // note the review id and change id are the same for git-fusion reviews
            $review = ReviewModel::fetch($change->getId(), $p4Admin);

            // if we get a review but its the wrong type, we can't do anything with it
            // this really shouldn't happen but good to confirm all is well
            if ($review->getType() != 'git') {
                return;
            }
        } catch (RecordNotFoundException $e) {
            // couldn't fetch an existing review, create one!
            $review = GitReview::createFromChange($change, $p4Admin);
            $review->save();

            // ensure we pass along to the review event that this is an add
            $isAdd = true;
        }

        // put the fetched/created review on the existing event.
        // the presence of a review on the event will cause the traditional
        // shelf-commit handler to skip processing this change.
        $event->setParam('review', $review);

        // push the new review into queue for further processing, always replace for git reviews.
        $queue->addTask(
            'review',
            $review->getId(),
            [
                'user'                       => $change->getUser(),
                'updateFromChange'           => $change->getId(),
                ReviewModel::ADD_CHANGE_MODE => ReviewModel::REPLACE_MODE,
                'isAdd'                      => isset($isAdd) && $isAdd
            ]
        );
    }

    /**
     * @param Event $event
     * @throws \Application\Config\ConfigException
     */
    public function shelveDelete(Event $event)
    {
        parent::log($event);
        $p4Admin = $this->services->get('p4_admin');
        $queue   = $this->services->get('queue');
        $config  = $this->services->get('config');
        $logger  = $this->services->get('logger');

        // We need to know who is running Swarm to exclude that user from
        // triggering this work
        $userRunningSwarm = isset($config['p4'][ReviewModel::USER])
            ? $config['p4'][ReviewModel::USER]
            : null;

        $dataValues = $event->getParam('data');
        $user       = isset($dataValues[ReviewModel::USER]) && !empty($dataValues[ReviewModel::USER])
            ? $dataValues[ReviewModel::USER]
            : null;
        // Swarm cleans its own changelist up a lot with the shelve -d so we want to
        // exclude the user in the config from firing this action. Exit as early as possible
        if ($user && $user === $userRunningSwarm) {
            return;
        }

        $id     = $event->getParam(ReviewModel::FIELD_ID);
        $client = isset($dataValues[ReviewModel::CLIENT]) && !empty($dataValues[ReviewModel::CLIENT])
            ? $dataValues[ReviewModel::CLIENT] : null;
        $cwd    = isset($dataValues[ReviewModel::CWD])    && !empty($dataValues[ReviewModel::CWD])
            ? $dataValues[ReviewModel::CWD]    : null;
        $files  = isset($dataValues[ReviewModel::FILES])  && !empty($dataValues[ReviewModel::FILES])
            ? $dataValues[ReviewModel::FILES]  : null;

        if (is_null($client) || is_null($cwd) || is_null($files)|| is_null($user)) {
            $message = sprintf(
                "%s Unable to process id[%s] for values client[%s], working directory[%s], files[%s], " .
                "user[%s]. This is not a problem if the delete was triggered by deleting the entire shelf.",
                ReviewModel::SHELVEDEL,
                $id,
                var_export($client, true),
                var_export($cwd, true),
                var_export($files, true),
                var_export($user, true)
            );
            // We can't process the delete if we don't have these required field.
            $logger->warn($message);
            return;
        }

        // Try and fetch the review for the given Change id.
        $review = ReviewModel::fetchAll(
            [
                ReviewModel::FETCH_BY_CHANGE => $id
            ],
            $p4Admin
        )->first();

        if ($review === null) {
            // Calling 'first' on the iterator above will result in null being returned when there are no
            // items. This is a valid scenario if the change has no review
            $logger->trace(sprintf('%s No review found to process for change [%s]', ReviewModel::SHELVEDEL, $id));
        } else {
            // if we get a review but its the git type, ignore it as we don't want
            // handle them.
            if ($review->getType() === 'git') {
                $logger->trace(ReviewModel::SHELVEDEL . 'We will not process shelvedel for a git review.');
                return;
            }
            // Get the states that we can process.
            $defaultExcludedStates = [ReviewModel::STATE_ARCHIVED, ReviewModel::STATE_APPROVED_COMMIT];
            $configStates          = ConfigManager::getValue(
                $config,
                ConfigManager::REVIEWS_PROCESS_SHELF_DELETE_WHEN
            );


            // If the current review state isn't a state we allowed to process end now.
            // By default we don't allow review States archived or approved:commit
            if (!in_array($review->getState(), $configStates)
                || in_array($review->getState(), $defaultExcludedStates)) {
                $logger->trace(ReviewModel::SHELVEDEL . 'We have not found an allowed state to be processed.');
                return;
            }
            // Now we need to go off and check which files this event is trying to delete.
            $depotFiles = $this->getFileDepotPath($this->services, $client, $cwd, $files);

            // Only raise a review task if we have some depot files to process. Atomic
            // delete of a shelf passing no files should not update the review
            if ($depotFiles && sizeof($depotFiles) > 0) {
                // put the fetched review on the existing event.
                $event->setParam('review', $review);

                // push the task into queue for further processing.
                $queue->addTask(
                    'review',
                    $review->getId(),
                    [
                        ReviewModel::USER => $user,
                        ReviewModel::DELFROMCHANGE => $review->getId(),
                        ReviewModel::FILES => $depotFiles,
                        ReviewModel::CLIENT => $client
                    ]
                );
            }
        }
    }

    /**
     * Process the event to determine whether we should update/create review etc.
     *
     * We examine the change description to see if it contains a configured review pattern.
     * If the change contains a review pattern that includes an existing review id we simply
     * push it through to a 'review' task to carry out the work of updating the shelved files,
     * participants, etc.
     *
     * For changes with a review pattern with no id (so its a 'start review') a new review record
     * will be created and the original change's description is updated to include the id. We then
     * push the change through to the 'review' task much like an update to take care of shelve
     * transfer, etc.
     *
     * For more information on review patterns, see the review_keywords service.
     *
     * @param  Event    $event
     * @return void
     * @throws \Application\Config\ConfigException
     */
    protected function processShelveCommit(Event $event)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $queue    = $services->get('queue');
        $keywords = $services->get('review_keywords');
        $config   = $services->get('config');
        $change   = $event->getParam('change');
        $data     = (array) $event->getParam('data') + ['review' => null];

        // if a review is already present on the event, someone has done the work for us
        // most likely, this means it was a git-fusion review
        if ($event->getParam('review') instanceof ReviewModel) {
            return;
        }

        // if the change is by a user that is ignored for the purpose of reviews, bail
        $ignored = isset($config['reviews']['ignored_users']) ? $config['reviews']['ignored_users'] : null;
        if ($p4Admin->stringMatches($change->getUser(), (array) $ignored)) {
            return;
        }

        // when we update the swarm managed change it feeds back around
        // to here and we need to ignore the event.
        if (ReviewModel::exists($change->getOriginalId(), $p4Admin)) {
            return;
        }

        // we have to determine if this change is already in a review. if it is we:
        // - ensure the change updates that review (even if #review-123 isn't present)
        // - block starting/updating any additional reviews
        // - ignore the change if it is a new archive/version of the review
        // - if change is in the midst of being committed against a specific review,
        //   use that review
        $reviews = ReviewModel::fetchAll([ReviewModel::FETCH_BY_CHANGE => $change->getOriginalId()], $p4Admin);

        // if the change is a new archive/version of the review, ignore event altogether.
        // note: we use the raw versions value to avoid tickling on-the-fly upgrade code
        foreach ($reviews as $review) {
            $versions = (array) $review->getRawValue('versions');
            foreach ($versions as $version) {
                $version += ['change' => null, 'archiveChange' => null, 'pending' => null];
                if (($version['change'] == $change->getId() || $version['archiveChange'] == $change->getId())
                    && $version['pending']
                ) {
                    return;
                }
            }
        }

        // check for a review keyword in the description
        $matches = $keywords->getMatches($change->getDescription());

        // if this change is associated to a review; ignore the keyword and use
        // the review id we're already associated with.
        // we don't expect multiple reviews but should that occur use the first.
        if ($reviews->count()) {
            $matches['id'] = $reviews->first()->getId();
        }

        // if the change is in the midst of being committed against a review,
        // that review's id should be used (even if it isn't the first review)
        foreach ($reviews as $review) {
            if ($review->getCommitStatus('change') == $change->getOriginalId()) {
                $matches['id'] = $review->getId();
                break;
            }
        }

        // if an id was passed in data 'review' it always wins
        if (strlen($data['review'])) {
            $matches['id'] = $data['review'];
        }

        // if no review details could be located; nothing to do
        if (!$matches) {
            return;
        }

        // normalize matches now that we know we should be processing
        $matches += ['id' => null];

        // don't allow a change to be in more than one review
        // - if the change is in a review, block adding another review
        // - if the change is in a review, only allow updates to that review
        // largely unnecessary but does protect us in the data['review'] case.
        if ($reviews->count()) {
            if (!strlen($matches['id'])) {
                return;
            }
            if (!in_array($matches['id'], $reviews->invoke('getId'))) {
                return;
            }
        }

        $isAdd = false;
        // if this is an update to an existing review, fetch it
        // otherwise create a new review.
        $services->get('logger')->debug(
            'Shelve/Commit: Processing matches data [' . implode(",", $matches) . ']'
        );
        if (strlen($matches['id'])) {
            // fetch to make sure it exists and to normalize edits/adds
            // when we push the queue event.
            try {
                $review = ReviewModel::fetch($matches['id'], $p4Admin);
            } catch (RecordNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            // nothing to update if they provided a bad id
            if (isset($e)) {
                // @todo inform user via email their id was bad?
                $services->get('logger')->debug(
                    'Shelve/Commit: Exception fetching review(' . $matches['id'] .') for [' . $e . ']'
                );
                return;
            }

            // perforce users can only commit against a git review, they are
            // not otherwise allowed to update it. if this is a git review
            // and not a commit based update, bail
            if ($review->getType() == 'git' && !$change->isSubmitted()) {
                // @todo inform user via email they cannot update git reviews?
                $services->get('logger')->debug(
                    'Shelve/Commit: Cannot update git review(' . $review->getId() .')'
                );
                return;
            }

            // if the review is mid-commit for another change, bail
            if ($review->isCommitting() && $review->getCommitStatus('change') != $change->getOriginalId()) {
                // @todo inform user via email their update was skipped due to ongoing approve & commit?
                $services->get('logger')->debug(
                    'Shelve/Commit: Review(' . $review->getId() .') is mid commit [' .
                    var_export($review->getCommitStatus(), true) . ']'
                );
                return;
            }

            // add the on behalf of information if the user committing this review is not the same as the
            // original author of it
            $committer = $review->getCommitStatus('change') == $change->getOriginalId()
                ? $review->getCommitStatus('committer')
                : $change->getUser();
            if ($committer
                && !$p4Admin->stringMatches($committer, $review->get('author'))
                && $event->getParam('activity') instanceof Activity
            ) {
                $activity = $event->getParam('activity');
                $activity->set('behalfOf', $review->get('author'));
                $activity->set('user', $committer);
            }
        } else {
            // create the review record
            $review = ReviewModel::createFromChange($change, $p4Admin);

            // strip off the review keyword(s) and save it
            $review->set('description', $keywords->filter($review->get('description')));
            $review->save();

            // ensure we pass along to the review event that this is an add
            $isAdd = true;

            // the change that started this review needs its description updated to include
            // the review id. this will give the user feedback we've handled it and make it
            // clear any future updates to shelved files on that change will impact the review.
            $change->setDescription(
                $keywords->update($change->getDescription(), ['id' => $review->getId()])
            );

            // saving won't work correctly without a valid client; grab one
            // and ensure its released even if exceptions should occur.
            try {
                $change->getConnection()->getService('clients')->grab();
                $change->save(true);
            } catch (\Exception $e) {
                // we're pretty committed to adding the review at this point so just log and carry on
                $services->get('logger')->err($e);
            }
            $change->getConnection()->getService('clients')->release();
        }

        // put the fetched/created review on the existing event in case anyone cares for it
        $event->setParam('review', $review);

        $addChangeMode = null;
        if (isset($matches['keyword']) &&
            isset($matches['id']) &&
            strlen($matches['keyword']) &&
            strlen($matches['id']) &&
            ($matches['keyword'] === ReviewModel::REPLACE_MODE || $matches['keyword'] === ReviewModel::APPEND_MODE)
        ) {
            $addChangeMode = $matches['keyword'];
        }

        try {
            $services->get('config_check')->enforce(IWorkflow::WORKFLOW);
        } catch (ForbiddenException $e) {
            // Workflows are disabled and they would normally handle checking of end state update rules so
            // we need to do a check here so that we do not regress the patch fix SW-5135 that sought to
            // prevent updates to reviews when in a particular state (SW-5616)

            // push the new review into queue for further processing, but only if the review state allows
            $blacklist = ConfigManager::getValue($config, ConfigManager::REVIEWS_END_STATES, []);
            // When the updating change is not already associated with the review and the state is in the blacklist
            if (!in_array($change->getId(), $review->getChanges()) && in_array(
                $review->getState(),
                array_map(
                    function ($entry) {
                        return strpos($entry, ":") !== false ? strstr($entry, ":", true) : $entry;
                    },
                    $blacklist
                )
            )) {
                // Allow for approved:commit being in the blacklist
                if (!in_array(ReviewModel::STATE_APPROVED_COMMIT, $blacklist) ||
                    !ReviewModel::STATE_APPROVED === $review->getState() ||
                    count($review->getCommits()) !== 0) {
                    $services->get('logger')->warn(
                        "Ignored update from change(" . $change->getId() . ") for review " . $review->getId() .
                        "(" . $review->getState() .")."
                    );
                    return;
                }
            }
        }

        // push the new review into queue for further processing.
        $services->get('logger')->debug(
            'Shelve/Commit: Queueing updateFromChange task for Review(' . $review->getId() .')'
        );
        $queue->addTask(
            'review',
            $review->getId(),
            [
                'user'                       => isset($committer) ? $committer : $review->get('author'),
                'updateFromChange'           => $change->getId(),
                'isAdd'                      => $isAdd === true,
                ReviewModel::ADD_CHANGE_MODE => $addChangeMode
            ]
        );
    }

    /**
     * This is given a list of files and relative path and we need to
     * build and work out the depot path.
     *
     * Data from trigger will be like this:
     * suzie-photon-test,/home/spenn/tmp/suzie-photon/depot,-c,145697,-d,b.txt,c.txt
     *
     * Which is broken down into:
     * Client
     * Relative path
     * -c
     * changelist
     * -d
     * files ... (the rest should be all the individual files.)
     *
     * @param $services This is the service locator.
     * @param $client   This is the client the files came from.
     * @param $path     This is the relative path for all files.
     * @param $files    This is the list of files.
     * @return mixed
     */
    public function getFileDepotPath($services, $client, $path, $files)
    {
        if (!is_array($files)) {
            $files = [$files];
        }
        $p4Admin = $services->get('p4_admin');
        $logger  = $services->get('logger');

        // Keep the host to be reset back later.
        $oldHost = $p4Admin->getHost();
        try {
            // Try and fetch the client the user is using.
            $clientData = $p4Admin->run('client', ['-o', $client]);
            $clientData = $clientData->getData(0);
            $host       = isset($clientData['Host']) ? $clientData['Host'] : $oldHost;
            // Set the p4admin connection to be able to use the users client.
            $p4Admin->setHost($host);
            $p4Admin->setClient($client);
        } catch (Exception $clientError) {
            $logger->err(
                ReviewModel::SHELVEDEL .
                "There was an setting up the client and host. Error: " . $clientError->getMessage()
            );
        }

        $depotFiles = [];
        foreach ($files as $file) {
            // If the file we have been provide is a depot path just accepted it.
            if (substr($file, 0, 2) === "//") {
                $depotFiles[] = $file;
            } else {
                //The relativePath or absolutePath will be given to us by the trigger.
                //Check for absolute or relative path. For relative path it will append
                //current working directory to the path.
                if (preg_match('#^[a-zA-Z]:\\\\#', $file) || $file[0] === '/' || $file[0] === '\\') {
                    //absolute path is given, don't append current working directory
                    $filePath = $file;
                } else {
                    //relative path is given, append current working directory
                    $filePath =  $path . '/' . $file;
                }
                // Know we have the users client and host set we can do a p4 where files
                // which will return us the depot path of them files.
                try {
                    // If the 'where' fails carry on with other files but just log and move on
                    // See SW-5250 for issue description
                    $depotLoc     = $p4Admin->run('where', $filePath);
                    $newData      = $depotLoc->getData(0);
                    $depotFiles[] = $newData['depotFile'];
                } catch (Exception $error) {
                    $logger->trace(
                        ReviewModel::SHELVEDEL .
                        "Failed to get where output for file: " . $error->getMessage()
                    );
                }
            }
        }

        // Set the old Host name back incase we have locked clients.
        $p4Admin->setHost($oldHost);

        return $depotFiles;
    }
}
