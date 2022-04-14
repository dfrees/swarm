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
use Application\Config\ConfigException;
use Application\Filter\Linkify;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use InvalidArgumentException;
use Mail\MailAction;
use Mail\Module as Mail;
use P4\Connection\Exception\CommandException;
use P4\Exception;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Projects\Model\Project as ProjectModel;
use Record\Exception\NotFoundException;
use Record\Lock\Lock;
use Reviews\Filter\GitInfo;
use Reviews\Model\Review as ReviewModel;
use TestIntegration\Filter\StatusValidator;
use TestIntegration\Service\ITestExecutor;
use Laminas\EventManager\Event;
use Application\Config\ConfigManager;
use Reviews\UpdateService;

class Review extends AbstractEventListener
{
    /**
     * Process the review. We use the advisory locking for the whole process to avoid potential
     * race condition where another process tries to do the same thing.
     *
     * @param   Event   $event
     * @return  void
     * @throws CommandException
     * @throws ConfigException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function lockThenProcess(Event $event)
    {
        parent::log($event);
        $id   = $event->getParam('id');
        $lock = new Lock(ReviewModel::LOCK_CHANGE_PREFIX . $id, $this->services->get('p4_admin'));
        $lock->lock();

        try {
            $this->processReview($event);
        } catch (\Exception $e) {
            // we handle this after unlocking
        }

        $lock->unlock();

        if (isset($e)) {
            $this->services->get('logger')->err($e);
        }
    }

    /**
     * Process the review, e.g. determine affected projects, prepare activity, etc.
     *
     * @param Event $event
     * @throws SpecNotFoundException
     * @throws ConfigException
     * @throws Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws NotFoundException
     */
    protected function processReview(Event $event)
    {
        $services = $this->services;
        $groupDAO = $services->get(IModelDAO::GROUP_DAO);
        $userDAO  = $services->get(IModelDAO::USER_DAO);
        $logger   = $services->get('logger');
        $p4Admin  = $services->get('p4_admin');
        $keywords = $services->get('review_keywords');
        $config   = $services->get('config');
        $id       = $event->getParam('id');
        $data     = $event->getParam('data') + [
                'user'                       => null,
                'isAdd'                      => false,
                'isStateChange'              => false,
                'isAuthorChange'             => false,
                'isReviewersChange'          => false,
                'isVote'                     => null,
                'isDescriptionChange'        => false,
                'updateFromChange'           => null,
                ReviewModel::ADD_CHANGE_MODE => null,
                'testStatus'                 => null,
                'description'                => null,
                'previous'                   => [],
                'quiet'                      => null,
                ReviewModel::DELFROMCHANGE   => null,
                ReviewModel::FILES           => null,
                ReviewModel::CLIENT          => null
            ];
        $quiet    = $event->getParam('quiet', $data['quiet']);
        $previous = $data['previous'] + [
            'testStatus'          => null,
            'description'         => null,
            'participantsData'    => null
            ];

        // fetch review record
        $review = ReviewModel::fetch($id, $p4Admin);
        $logger->notice('Review/Listener: Processing review id(' . $review->getId() .')');
        $event->setParam('review', $review);

        // we'll need the version before @mentions were added later on to
        // pull apart details about who was explicitly added/removed/etc.
        $fetchedParticipantsData = $review->getParticipantsData();

        // We only want to process 'affected by' once so when we do it keep it for use later
        $checkProjects = null;

        if ($data[ReviewModel::DELFROMCHANGE]) {
            $review->deleteFromChange($data);
            // next ensure the review knows about any newly affected projects
            $checkProjects   = UpdateService::checkAffectedProjects($services, $review);
            $currentProjects = $checkProjects[UpdateService::AFFECTED_PROJECTS];
            $review->setProjects($currentProjects);
        }

        $usersBlacklist = ConfigManager::getValue(
            $config,
            ConfigManager::MENTIONS_USERS_EXCLUDE_LIST,
            []
        );

        $groupsBlacklist = ConfigManager::getValue(
            $config,
            ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST,
            []
        );

        // update from change if the event data tells us to.
        if ($data['updateFromChange']) {
            // first update the review from change which will add participants
            // and copy-over or clear-out shelved files as needed.
            $oldVersions  = $review->getVersions();
            $updateChange = Change::fetchById($data['updateFromChange'], $p4Admin);

            // when you update the review or committing against it, we only want to honor
            // new @mentions. this prevents resurrecting deleted reviewers. note this
            // approach is imperfect (if we're being updated from two shelves in different
            // workspaces for example it doesn't work right) but for the happy paths it
            // should notably reduce the dead from arising and who doesn't appreciate that.
            $oldMentions = [];
            if (!$data['isAdd']) {
                try {
                    // note we allow for archive shelves when getting head change as they
                    // are more likely to have the 'original' description than the authoritative
                    // review change as the latter syncs to web based description edits.
                    $headChange  = Change::fetchById($review->getHeadChange(true), $p4Admin);
                    $oldMentions = Linkify::getCallouts($headChange->getDescription());
                } catch (SpecNotFoundException $e) {
                } catch (InvalidArgumentException $e) {
                }

                // in the unlikely event that we got an invalid id,
                // or change is missing, just log it and carry on
                if (isset($e)) {
                    $logger->err($e);
                    unset($e);
                }
            }

            $review->updateFromChange(
                $updateChange,
                isset($config['reviews']['unapprove_modified'])
                ? (bool) $config['reviews']['unapprove_modified']
                : true,
                $data[ReviewModel::ADD_CHANGE_MODE] === null
                    ? null : $data[ReviewModel::ADD_CHANGE_MODE] === ReviewModel::APPEND_MODE
            );

            // ensure any new @mentions or @*mentions in the change description are added
            // we only count new @mentions to avoid resurrecting removed/un-required reviewers.
            $mentions = array_diff(Linkify::getCallouts($updateChange->getDescription()), $oldMentions);
            $review->addParticipant(
                array_merge(
                    (array) $userDAO->filter($mentions, $p4Admin, $usersBlacklist),
                    (array) $groupDAO->filter($mentions, $p4Admin, $groupsBlacklist)
                )
            );
            $required = array_diff(Linkify::getCallouts($updateChange->getDescription(), true), $oldMentions);
            $review->addRequired(
                array_merge(
                    (array) $userDAO->filter($required, $p4Admin, $usersBlacklist),
                    (array) $groupDAO->filter($required, $p4Admin, $groupsBlacklist)
                )
            );
            $requiredOne = array_diff(Linkify::getCallouts($updateChange->getDescription(), false, true), $oldMentions);
            $review->addRequired($groupDAO->filter($requiredOne, $p4Admin, $groupsBlacklist), "1");

            // if update did not produce a new version (i.e. no diffs) quietly bail.
            // we are the boss of the review event and have determined this update is
            // not really an update (e.g. a race condition from both api and shelf events).
            // note git reviews are exempt - we don't currently track versions on them.
            if ($oldVersions == $review->getVersions() && $review->getType() !== 'git') {
                // only need to save if there was a change in the @mentions
                if ($mentions || $required) {
                    $review->save();
                }
                $event->setParam('quiet', true);
                return;
            }
            // next ensure the review knows about any newly affected projects
            $checkProjects   = UpdateService::checkAffectedProjects($services, $review);
            $currentProjects = $checkProjects[UpdateService::AFFECTED_PROJECTS];
            $review->setProjects($currentProjects);

            // associate review with groups that the author is a member of
            $groups = $groupDAO->fetchAll(
                [
                    Group::FETCH_BY_USER      => $review->get(ReviewModel::ROLE_AUTHOR),
                    Group::FETCH_INDIRECT     => true
                ],
                $p4Admin
            );

            // If there has been any changes to the affected projects/branches
            // as a result of this update then add in default reviewers for the
            // additions whether they are retained or not
            if (!empty($checkProjects[UpdateService::NEW_PROJECTS])) {
                $this->mergeDefaults($review, $checkProjects[UpdateService::NEW_PROJECTS]);
            }
            // For existing branches only update the default reviewers for retained
            // reviewers, also forcing any existing reviewers up to the retained level
            if (!empty($checkProjects[UpdateService::AFFECTED_PROJECTS])) {
                $this->mergeDefaults(
                    $review,
                    $checkProjects[UpdateService::AFFECTED_PROJECTS],
                    [UpdateService::ALWAYS_ADD_DEFAULT => false]
                );
            }
            $logger->debug(
                'Review/Listener: Reviewers for ' . $review->getId() . ' set to '
                . implode(', ', $review->getParticipants())
            );
            $review->setGroups($groups->invoke('getId'));

            $review->save();
        }

        // if this is an add or the description has changed ensure new @mentions are participants.
        // we only count new @mentions to avoid resurrecting removed reviewers.
        $oldMentions   = Linkify::getCallouts($previous['description']);
        $newMentions   = Linkify::getCallouts($review->get('description'));
        $newRequired   = Linkify::getCallouts($review->get('description'), true);
        $newRequireOne = Linkify::getCallouts($review->get('description'), false, true);

        $required    = array_merge(
            (array) $userDAO->filter(array_diff($newRequired, $oldMentions), $p4Admin, $usersBlacklist),
            (array) $groupDAO->filter(array_diff($newRequired, $oldMentions), $p4Admin, $groupsBlacklist)
        );
        $mentions    = array_merge(
            (array) $userDAO->filter(array_diff($newMentions, $oldMentions), $p4Admin, $usersBlacklist),
            (array) $groupDAO->filter(array_diff($newMentions, $oldMentions), $p4Admin, $groupsBlacklist)
        );
        $requiredOne = $groupDAO->filter(array_diff($newRequireOne, $oldMentions), $p4Admin, $groupsBlacklist);
        $mentions    = array_diff($mentions, $review->getParticipants());

        if (($data['isAdd'] || $data['isDescriptionChange']) && $mentions) {
            $review->addParticipant($mentions)->addRequired($required)
                ->addRequired($groupDAO->filter($requiredOne, $p4Admin, $groupsBlacklist), "1")->save();
        }

        // For a new review, merge the default reviewers into the participants
        if ($data['isAdd']) {
            // Need a variable for participants to avoid PHP Notice for references
            $participants = $review->getParticipantsData();
            $review->setParticipantsData(
                UpdateService::mergeDefaultReviewersForChange(
                    $updateChange,
                    $participants,
                    $p4Admin,
                    [UpdateService::FORCE_REQUIREMENT => true],
                    // Pass in the checked projects if they have already been determined
                    $checkProjects[UpdateService::AFFECTED_PROJECTS] ?? null
                )
            )->save();
            $logger->debug(
                'Review/Listener: Initial reviewers for ' . $updateChange->getId() . ' are '
                . implode(', ', $review->getParticipants())
            );
        }

        // fetch projects
        // we do this regardless of whether files were touched, but we want
        // to do it after we have had a chance to re-assess affected projects
        // (we need them to know which automated tests need to be triggered
        // and to notify all project members on newly created reviews)
        $projectDAO = $services->get(IModelDAO::PROJECT_DAO);
        $projects   = $projectDAO->fetchAll(
            [ProjectModel::FETCH_BY_IDS => array_keys($review->getProjects())],
            $p4Admin
        );

        // automated test integration
        // if files were touched, kick off automated tests.
        if ($this->shouldRunTests($data)) {
            $testExecutor = $services->get(ITestExecutor::NAME);
            $options      = [];
            if (isset($data[ReviewModel::DELFROMCHANGE])) {
                $options[ReviewModel::DELFROMCHANGE] = $data[ReviewModel::DELFROMCHANGE];
            }
            $testRunsData = $testExecutor->getReviewTests(
                $review,
                $currentProjects,
                $projects,
                $options
            );
            $event->setParam(
                ReviewTestRuns::EVENT_NAME, [
                    ReviewTestRuns::REVIEW_ID => $review->getId(),
                    ReviewTestRuns::DATA => $testRunsData
                ]
            );
        }

        // prepare review info for activity streams
        $activity       = new Activity;
        $currentVersion = $review->getHeadVersion();
        $activity->set(
            [
                'type'        => 'review',
                'link'        => ['review', ['review' => $id, 'version' => $currentVersion]],
                'user'        => $data['user'],
                'action'      => $data['isAdd'] ? 'requested' : 'updated',
                'target'      => 'review ' . $id . ' (revision ' . $currentVersion . ')',
                'description' => $keywords->filter($data['description'] ?: $review->get('description')),
                'topic'       => $review->getTopic(),
                'time'        => $review->get('updated'),
                'streams'     => ['review-' . $id],
                'change'      => $review->getHeadChange()
            ]
        );

        // we want to know about explicit 'primary action' reviewer join/leave/edit/required/optional
        // style changes so we can give specific notifications on these topics. note this excludes
        // @mention based additions and getting brought in due to editing/touching the review.
        $getUserData = function ($key, $values) {

            $output = [];
            foreach ((array) $values as $user => $data) {
                if (isset($data[$key]) && $data[$key]) {
                    $output[] = $user;
                }
            }
            return $output;
        };

        $fetchedRequired      = $getUserData('required', $fetchedParticipantsData);
        $previousRequired     = $getUserData('required', (array) $previous['participantsData']);
        $fetchedReviewers     = array_keys($fetchedParticipantsData);
        $previousReviewers    = array_keys((array) $previous['participantsData']);
        $fetchedUnsubscribed  = $getUserData('notificationsDisabled', $fetchedParticipantsData);
        $previousUnsubscribed = $getUserData('notificationsDisabled', (array) $previous['participantsData']);

        // figure out who was removed, added as required, added, made required, made optional
        $removedReviewers = array_diff($previousReviewers, $fetchedReviewers);
        $addedRequired    = array_diff($fetchedRequired,   $previousRequired,  $previousReviewers);
        $addedReviewers   = array_diff($fetchedReviewers,  $previousReviewers, $addedRequired);
        $madeRequired     = array_diff($fetchedRequired,   $previousRequired,  $addedRequired);
        $madeOptional     = array_diff($previousRequired,  $fetchedRequired,   $removedReviewers);

        // if this isn't a reviewers change event or we didn't get previous data clear our bunk data
        if (!$data['isReviewersChange'] || !is_array($previous['participantsData'])) {
            $removedReviewers = $addedRequired = $addedReviewers = $madeRequired = $madeOptional = [];
        }

        // calculate the number of changes we detected, this helps in tuning the action
        $reviewerChanges = !empty($removedReviewers) + !empty($addedReviewers) + !empty($addedRequired)
                           + !empty($madeRequired) + !empty($madeOptional);

        // calculate if we have changed number of unsubscribed users
        // only applicable if we also have previous review available
        $isNotificationChange = count($fetchedUnsubscribed) != count($previousUnsubscribed)
            && !empty($previous['participantsData']);

        // if this is a reviewer change, provide a better actions. we have a number of cases:
        // - this isn't a reviewers change so there's nothing to do
        // - the only change is the author added themselves
        // - only change is the author removed themselves
        // - that crazy author simply made themselves required or added themselves as required
        // - author just made themselves optional
        // - user has disabled/re-enabled notifications
        // - or lastly, its an 'edit' meaning they modified another user or multiple users
        if (!$data['isReviewersChange']) {
            // this isn't the review update you are looking for *hand-wave*
        } elseif (count($addedReviewers) == 1 && $reviewerChanges == 1
            && $data['user'] && in_array($data['user'], $addedReviewers)
        ) {
            $activity->set('action', 'joined');
        } elseif (count($removedReviewers) == 1 && $reviewerChanges == 1
            && $data['user'] && in_array($data['user'], $removedReviewers)
        ) {
            $activity->set('action', 'left');
        } elseif (count(array_merge($madeRequired, $addedRequired)) == 1 && $reviewerChanges == 1
            && $data['user'] && in_array($data['user'], array_merge($madeRequired, $addedRequired))
        ) {
            $activity->set('action', 'made their vote required on');
        } elseif (count($madeOptional) == 1 && $reviewerChanges == 1
            && $data['user'] && in_array($data['user'], $madeOptional)
        ) {
            $activity->set('action', 'made their vote optional on');
        } elseif ($isNotificationChange
        ) {
            if (count($fetchedUnsubscribed) > count($previousUnsubscribed)) {
                $activity->set('action', 'disabled notifications on');
            } else {
                $activity->set('action', 're-enabled notifications on');
            }
        } else {
            $activity->set('action', 'edited reviewers on');
            $activity->set(
                'details',
                [
                    'reviewers' => array_filter(
                        [
                            'addedOptional' => $addedReviewers,
                            'addedRequired' => $addedRequired,
                            'madeRequired'  => $madeRequired,
                            'madeOptional'  => $madeOptional,
                            'removed'       => $removedReviewers
                        ]
                    )
                ]
            );
        }

        // if this is a vote change, fine-tune the action.
        if ($data['isVote'] && (int) $data['isVote']) {
            $activity->set('action', 'voted ' . ($data['isVote'] > 0 ? 'up' : 'down'));
        } elseif (($data['isVote'] !== null && $data['isVote'] !== false) && (int) $data['isVote'] === 0) {
            $activity->set('action', 'cleared their vote on');
        }

        // if the state has changed, fine-tune the action.
        if ($data['isStateChange']) {
            switch ($review->get('state')) {
                case 'needsReview':
                    $activity->set('action', 'requested further review of');
                    $activity->set('target', $id);
                    break;
                case 'needsRevision':
                    $activity->set('action', 'requested revisions to');
                    break;
                default:
                    $activity->set('action', $review->get('state'));
            }
        }

        // if the author has changed, fine-tune the action.
        if ($data['isAuthorChange']) {
            $activity->set('action', 'changed author of');
            $activity->set(
                'details',
                [
                    'author' => array_filter(
                        [
                            'oldAuthor' => isset($previous['author']) ? $previous['author'] : '' ,
                            'newAuthor' => $review->getRawValue('author')
                        ]
                    )
                ]
            );
        }

        // if the description has changed, fine-tune the action.
        if ($data['isDescriptionChange']) {
            $activity->set('action', 'updated description of');
        }

        // if test status was updated, revise action and overload preposition.
        if ($data['testStatus']) {
            $logger->debug(sprintf("Test status callback with user [%s]", ($data['user'] ? $data['user'] : "unknown")));
            $activity->set('action',      $data['user'] ? MailAction::REVIEW_TESTS : MailAction::REVIEW_TESTS_NO_AUTH);
            $activity->set('target',      'review ' . $id);
            switch ($data['testStatus']) {
                case StatusValidator::STATUS_PASS:
                    $preposition = "passed tests for";
                    break;
                case StatusValidator::STATUS_FAIL:
                    $preposition = "failed tests for";
                    break;
                default:
                    $preposition = "running tests for";
                    break;
            }
            $activity->set('preposition', $preposition);
        }

        // if the review files were updated, revise action
        if (!$data['isAdd'] && $data['updateFromChange']) {
            $activity->set('action',      'updated files in');

            // normally just strip keywords from the update change however, if its the
            // authoritative change for a git review, strip git info instead though.
            $description = $keywords->filter($updateChange->getDescription());
            if ($review->getType() === 'git' && $updateChange->getId() == $review->getId()) {
                $gitInfo     = new GitInfo($updateChange->getDescription());
                $description = $gitInfo->getDescription();
            }

            $activity->set('description', $description);
        }

        // if the review files were committed, silence activity and email notifications
        // the changes module handles commit notifications and we don't want duplicates
        if (!$data['isAdd'] && $data['updateFromChange'] && $updateChange->isSubmitted()) {
            $event->setParam('quiet', $quiet = true);
        }

        // flag the activity event as affecting all projects impacted by the review
        $activity->addProjects($review->getProjects());

        // we made it this far, safe to record the activity.
        $event->setParam('activity', $activity);

        // touch-up old activity to ensure that all events related to this review
        // have their 'change' field value up-to-date (exclude change/job type events)
        // so we can filter for restricted changes
        if ($data['updateFromChange']) {
            $headChange = $review->getHeadChange();
            $options    = [Activity::FETCH_BY_STREAM => 'review-' . $id];
            foreach (Activity::fetchAll($options, $p4Admin) as $record) {
                if (!in_array($record->get('type'), ['change', 'job'])
                    && $record->get('change') !== $headChange
                ) {
                    $record->set('change', $headChange)->save();
                }
            }
        }

        // determine who to notify via activity and email
        // - always notify review participants (author, creator, reviewers, etc.)
        $to = $review->getParticipants();
        $activity->addFollowers($to);

        // if it's a new review, notify all members of associated groups (if the group is configured for it)
        if ($data['isAdd'] && isset($groups)) {
            foreach ($groups as $group) {
                $logger->debug(
                    'Review/Listener:Checking whether group ' . $group->getId() . ' wants new review emails.'
                );
                if ($group->getConfig()->getEmailFlag('reviews')) {
                    $logger->debug(
                        'Review/Listener: ' . $group->getId()
                        . ' wants new review emails.'
                    );
                    // Add this group to the list of participants, the mail/module will expand if necessary
                    $to[] = GroupConfig::KEY_PREFIX . $group->getId();
                    // Split out into group members to
                    $activity->addFollowers($groupDAO->fetchAllMembers($group->getId(), false, null, null, $p4Admin));
                }
            }
        }

        // if it's a new or updated review, notify all project members and moderators
        $branches = null;
        if ($data['isAdd'] || $data['updateFromChange']) {
            $impacted = $review->getProjects();
            foreach ($projects as $projectId => $project) {
                $branches = isset($impacted[$projectId]) ? $impacted[$projectId] : null;
                $logger->trace(
                    'Review/Listener: Branches are [' . implode(', ', $branches) . ']'
                );

                $moderators = $branches ? $project->getModerators($branches) : [];
                // Email recipients needs to be direct moderators/groups only now that groups can have email addresses
                $moderatorsAndGroups = $project->getModeratorsWithGroups($branches);
                $logger->trace(
                    'Review/Listener: Moderators are [' . implode(', ', $moderators) . ']'
                );

                if ($data['isAdd']) {
                    $emailMembers = $project->getEmailFlag('review_email_project_members');
                    $members      = $project->getAllMembers();
                    $logger->trace(
                        'Review/Listener: Members of project ' . $project->getId()
                        . ' are [' . implode(', ', $members) . ']'
                    );
                    $activity->addFollowers($members);
                    $activity->addFollowers($moderators);

                    // email notification can be disabled per project
                    $logger->debug(
                        'Review/Listener: Checking whether project ' . $project->getId() . ' wants new review emails('
                        . (($emailMembers || $emailMembers === null) ? "yes" : "no") . ').'
                    );
                    if ($emailMembers || $emailMembers === null) {
                        // Emailthe project and moderators when reviews are requested
                        $to = array_merge(
                            $to,
                            [ProjectModel::KEY_PREFIX.$project->getId()],
                            $moderatorsAndGroups['Users'],
                            array_map(
                                function ($group) {
                                    return GroupConfig::KEY_PREFIX.Group::getGroupName($group);
                                },
                                $moderatorsAndGroups['Groups']
                            )
                        );
                    }
                } else {
                    // Email moderators when reviews are updated
                    $to = array_merge(
                        $to,
                        $moderatorsAndGroups['Users'],
                        array_map(
                            function ($group) {
                                return GroupConfig::KEY_PREFIX.Group::getGroupName($group);
                            },
                            $moderatorsAndGroups['Groups']
                        )
                    );
                }

                $logger->debug(
                    'Review/Listener: After processing project ' . $project->getId()
                    . ', email recipients are[' . implode(', ', $to) . ']'
                );
            }
        }

        // include any removed reviewers this one last time so they know it happened
        $activity->addFollowers($removedReviewers);
        $to = array_merge($to, $removedReviewers);

        // remove any unsubscribed users from recipients list
        $to = array_diff($to, $fetchedUnsubscribed);

        // set the appropriate headers for the email
        $withoutPrepositions = str_replace([' in', ' on', ' of', ' to'], '', $activity->get('action'));
        $messageId           =
            '<action-' . str_replace(' ', '_', $withoutPrepositions)
            . '-' . $review->getTopic() . '-' . time()
            . '@' . Mail::getInstanceName($config['mail']) . '>';
        $inReplyTo           = '<topic-'  . $review->getTopic() . '@' . Mail::getInstanceName($config['mail']) . '>';

        if ($data['isAdd']) {
            // if we added a new review - skip the in-reply-to header
            $messageId = '<topic-' . $review->getTopic() . '@' . Mail::getInstanceName($config['mail']) . '>';
        }

        // add all projects to an array to pass to email header
        $reviewProjects = [];
        foreach ($projects as $project) {
            $reviewProjects[$project->getId()] = $project;
        }

        // if we have any private projects, send their emails separately
        $privateProjects = array_filter(
            $reviewProjects,
            function ($project) {
                return $project->isPrivate();
            }
        );
        $publicProjects = array_filter(
            $reviewProjects,
            function ($project) {
                return !$project->isPrivate();
            }
        );
        $tasks = [];

        $publicEmailTo = array_merge($to, $review->getReviewerGroups());

        $logger->debug(
            'Review/Listener: Applying private/public project split; to list is [' . implode(', ', $to) . ']'
        );
        if ($privateProjects) {
            // if we have private projects their emails should all go separately
            foreach ($privateProjects as $project) {
                // re-calculate recipients to only be members of the project
                $projectMembers = $project->getAllMembers();
                // Pander to php5 syntax limitations
                $projectModeratorsWithGroups = $project->getModeratorsWithGroups();
                // Private project Moderators could be users or groups or group members
                $projectModerators = array_merge(
                    $project->getModerators($branches),
                    $projectModeratorsWithGroups['Groups']
                );
                // Private projects could also be on the to-list
                $projectKey = in_array(ProjectModel::KEY_PREFIX.$project->getId(), $to)
                    ? [ProjectModel::KEY_PREFIX.$project->getId()] : [];
                // Extract public recipients for later
                $publicEmailTo = array_diff($publicEmailTo, $projectMembers, $projectModerators, $projectKey);

                // Honour the project level email flag if this is an add (a new review)
                $send = true;

                $emailInterestedProjectMembers = [];
                if ($data['isAdd']) {
                    $send         = false;
                    $emailMembers = $project->getEmailFlag('review_email_project_members');
                    if ($emailMembers || $emailMembers === null) {
                        $send = true;
                        // This is all the project members and moderators.
                        $emailInterestedProjectMembers = array_merge($projectKey, $projectMembers, $projectModerators);
                    } else {
                        // these are the users that are a participant of the review and are a member of the project.
                        $emailInterestedProjectMembers = array_intersect($projectMembers, $to);
                    }
                    $logger->debug(
                        sprintf(
                            'New review for private project [%s] an email %s be sent',
                            $project->getId(),
                            ($send ? 'will' : 'will not')
                        )
                    );
                } else {
                    // these are the users that are a participant of the review and are a member of the project.
                    $emailInterestedProjectMembers = array_intersect($projectMembers, $to);
                }
                // We only want to change the sending to true if it isn't already true.
                if ($send === false && count($emailInterestedProjectMembers) > 0) {
                    $send = true;
                }

                if ($send) {
                    // create an email event
                    $privateEvent = clone $event;
                    $privateEvent->setParam(
                        'mail',
                        [
                            'author' => $review->get('author'),
                            'subject' => 'Review @' . $review->getId() . ' - '
                                . $keywords->filter($review->get('description')),
                            'cropSubject' => 80,
                            'toUsers' => $emailInterestedProjectMembers,
                            'fromUser' => $data['user'],
                            'messageId' => $messageId,
                            'inReplyTo' => $inReplyTo,
                            'projects' => [$project->getId()],
                            'htmlTemplate' => __DIR__ . '/../../view/mail/review-html.phtml',
                            'textTemplate' => __DIR__ . '/../../view/mail/review-text.phtml',
                        ]
                    );
                    $this->checkSilenceMail($data, $review, $privateEvent, $quiet, $isNotificationChange);
                    $tasks[] = $privateEvent;
                }
            }
        }
        if ($publicProjects) {
            $projects = [];
            foreach ($publicProjects as $project) {
                $projects[] = $project->getId();
            }
            $publicEvent = clone $event;
            $publicEvent->setParam(
                'mail',
                [
                        'author'        => $review->get('author'),
                        'subject'       => 'Review @' . $review->getId() . ' - '
                            .  $keywords->filter($review->get('description')),
                        'cropSubject'   => 80,
                        'toUsers'       => $publicEmailTo,
                        'fromUser'      => $data['user'],
                        'messageId'     => $messageId,
                        'inReplyTo'     => $inReplyTo,
                        'projects'      => array_values($projects),
                        'htmlTemplate'  => __DIR__ . '/../../view/mail/review-html.phtml',
                        'textTemplate'  => __DIR__ . '/../../view/mail/review-text.phtml',
                ]
            );
            $this->checkSilenceMail($data, $review, $publicEvent, $quiet, $isNotificationChange);
            $tasks[] = $publicEvent;
        }

        if (!$publicProjects && !$privateProjects) {
            // it does not belong to a project
            $generalEvent = clone $event;
            $generalEvent->setParam(
                'mail',
                [
                    'author'        => $review->get('author'),
                    'subject'       => 'Review @' . $review->getId() . ' - '
                        .  $keywords->filter($review->get('description')),
                    'cropSubject'   => 80,
                    'toUsers'       => $publicEmailTo,
                    'fromUser'      => $data['user'],
                    'messageId'     => $messageId,
                    'inReplyTo'     => $inReplyTo,
                    'htmlTemplate'  => __DIR__ . '/../../view/mail/review-html.phtml',
                    'textTemplate'  => __DIR__ . '/../../view/mail/review-text.phtml',
                ]
            );
            $this->checkSilenceMail($data, $review, $generalEvent, $quiet, $isNotificationChange);
            $tasks[] = $generalEvent;
        }

        $queue  = $this->services->get('queue');
        $events = $queue->getEventManager();

        foreach ($tasks as $task) {
            // for each task trigger a mail if needed
            $events->trigger('task.mail', null, $task->getParams());
        }
    }

    /**
     * Merges default reviewers for cases of review update
     * @param ReviewModel           $review             the review
     * @param array                 $affectedProjects   the affected projects
     * @param array|null            $options            options to pass to the merge
     * @throws Exception
     * @throws NotFoundException
     */
    private function mergeDefaults(ReviewModel $review, array $affectedProjects, array $options = null)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        // Need a variable for participants to avoid PHP Notice for references
        $participants = $review->getParticipantsData();
        $review->setParticipantsData(
            UpdateService::mergeDefaultReviewersForProjects(
                $affectedProjects,
                $participants,
                $p4Admin,
                $options
            )
        );
    }

    /**
     * Check if mail should be silenced. A mail silencing parameter is added if we are not already quiet overall and
     *
     * - notification is disabled
     * or
     * - this is a description change
     * or
     * - tests status was provided but it was not important (change is only important if the overall status has changed
     *   from 'pass' -> 'fail', or 'fail' -> 'pass'
     * @param mixed     $data                   the current data
     * @param mixed     $review                 the current review
     * @param mixed     $event                  the event
     * @param mixed     $quiet                  whether we are already quiet
     * @param mixed     $isNotificationChange   notification change
     */
    protected function checkSilenceMail($data, $review, &$event, $quiet, $isNotificationChange)
    {
        $logger              = $this->services->get(SwarmLogger::SERVICE);
        $currentStatus       = $data[ReviewModel::FIELD_TEST_STATUS] ?? null;
        $notifyForTestStatus = ReviewModel::isNotableTestStatusChange(
            $review->getPreviousTestStatus() ?? '',
            $data[ReviewModel::FIELD_TEST_STATUS] ?? ''
        );
        $logger->debug(sprintf("%s: notifyForTestStatus value is [%s]", Review::class, $notifyForTestStatus));
        $testDataProvided = $currentStatus !== null;

        // If there was test data and the change wasn't important
        // OR
        // description change was set
        // OR
        // notification change was set
        // AND we are not already quiet, merge in to quiet mail
        if ((($testDataProvided && !$notifyForTestStatus)
            || (isset($data[IReviewTask::IS_DESCRIPTION_CHANGE]) && $data[IReviewTask::IS_DESCRIPTION_CHANGE])
            || $isNotificationChange)
            && $quiet !== true) {
            $quiet = array_merge((array) $quiet, ['mail']);
            $event->setParam(IReviewTask::QUIET, $quiet);
            $logger->debug(sprintf("%s: Mail is silenced", Review::class));
        }
    }

    /**
     * Determine whether tests should run based on the new state.
     * @param $data array data
     * @return bool true if tests should run
     */
    private function shouldRunTests(array $data): bool
    {
        $logger   = $this->services->get(SwarmLogger::SERVICE);
        $runTests = false;

        if ($data['updateFromChange'] || $data[ReviewModel::DELFROMCHANGE]) {
            $runTests = true;
        }
        $logger->notice('Review/Listener: Tests ' . ($runTests ? 'will ' : 'will not ') . 'run.');
        return $runTests;
    }
}
