<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Listener;

use Activity\Model\Activity;
use Application\Config\Services;
use Application\Filter\Linkify;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Groups\Model\Config;
use Groups\Model\Group;
use Mail\MailAction;
use Notifications\Settings;
use P4\Connection\Exception\CommandException;
use Projects\Model\Project as ProjectModel;
use Reviews\Model\Review;
use Laminas\EventManager\Event;
use P4\Spec\Change;
use Mail\Module as Mail;

class CommitShelveListener extends AbstractEventListener
{
    public function activityAndMail(Event $event)
    {
        parent::log($event);
        $services     = $this->services;
        $logger       = $services->get('logger');
        $p4Admin      = $services->get('p4_admin');
        $keywords     = $services->get('review_keywords');
        $change       = $event->getParam('change');
        $groupDAO     = $services->get(IModelDAO::GROUP_DAO);
        $projectDAO   = $services->get(IModelDAO::PROJECT_DAO);
        $findAffected = $services->get(Services::AFFECTED_PROJECTS);
        $userDAO      = $services->get(IModelDAO::USER_DAO);
        try {
            // ignore invalid/pending changes.
            if (!$change instanceof Change || $change->getStatus() !== 'submitted') {
                return;
            }
            // prepare list of projects affected by the change
            $impacted = $findAffected->findByChange($p4Admin, $change);

            // prepare data model for activity streams
            $changeId = $change->getId();
            $activity = new Activity;
            $activity->set(
                [
                    'type'          => 'change',
                    'link'          => ['change', ['change' => $changeId]],
                    'user'          => $change->getUser(),
                    'action'        => MailAction::CHANGE_COMMITTED,
                    'target'        => 'change ' . $changeId,
                    'preposition'   => 'into',
                    'description'   => $keywords->filter($change->getDescription()),
                    'topic'         => 'changes/' . $change->getOriginalId(),
                    'time'          => $change->getTime(),
                    'projects'      => $impacted,
                    'change'        => $changeId
                ]
            );

            // ensure any @mention'ed users are included in both the activity and the email
            $callouts      = Linkify::getCallouts($change->getDescription());
            $userCallouts  = $userDAO->filter($callouts, $p4Admin);
            $groupCallouts = $groupDAO->filter($callouts, $p4Admin);
            $logger->trace(
                "Mail: callouts before filtering: " . var_export($callouts, true)
            );
            $logger->trace(
                "Mail: valid user callouts: "       . var_export($userCallouts, true)
            );
            $logger->trace(
                "Mail: valid group callouts: "      . var_export($groupCallouts, true)
            );
            $mentions = array_merge($userCallouts, $groupCallouts);
            $toUsers  = $mentions;
            $activity->addFollowers($userCallouts);

            // if this change has an author, include them and link the topic
            $review = $event->getParam('review');
            if ($review instanceof Review && $review->get('author')) {
                $activity->set('topic', 'reviews/' . $review->getId());
                $toUsers = array_merge($toUsers, [$review->get('author')]);
                $logger->info("Change/Module(task.commit): Adding Author to receive an email.");
            }
            $allGroups = $groupDAO->fetchAll([], $p4Admin)->toArray(true);
            // notify members, moderators and followers of affected projects via activity and email
            if ($impacted) {
                $projects = $projectDAO->fetchAll([ProjectModel::FETCH_BY_IDS => array_keys($impacted)], $p4Admin);
                foreach ($projects as $projectId => $project) {
                    $members    = $project->getAllMembers(false, $allGroups);
                    $followers  = $project->getFollowers($members);
                    $branches   = isset($impacted[$projectId]) ? $impacted[$projectId] : [];
                    $moderators = $branches ? $project->getModerators($branches, $allGroups) : null;

                    $activity->addFollowers($members);
                    $activity->addFollowers($moderators);
                    $activity->addFollowers($followers);

                    $changeEmailFlag = $project->getEmailFlag('change_email_project_users');
                    // Legacy projects may not have the flag so null is considered enabled, otherwise
                    // the value stored is '1' or '0' where '1' is enabled
                    if ($changeEmailFlag === null || $changeEmailFlag === '1') {
                        $toUsers = array_merge(
                            $toUsers,
                            [ProjectModel::KEY_PREFIX.$project->getId()],
                            $followers
                        );
                        // Now that groups have notification preferences, we need to email groups moderators too
                        if ($branches) {
                            $moderatorsAndGroups = $project->getModeratorsWithGroups($branches);
                            // Build a mailing list of users and groups (prefixed swarm-group-)
                            $toUsers = array_merge(
                                $toUsers,
                                $moderatorsAndGroups['Users'],
                                array_map(
                                    function ($group) {
                                        return Config::KEY_PREFIX.Group::getGroupName($group);
                                    },
                                    $moderatorsAndGroups['Groups']
                                )
                            );
                        }
                    }
                }
            }

            // notify members of groups the author is a member of (if the group is configured for it)
            $groups = $groupDAO->fetchAll(
                [
                    Group::FETCH_BY_USER      => $change->getUser(),
                    Group::FETCH_INDIRECT     => true,
                ],
                $p4Admin
            );
            $logger->debug(
                'Change/Module(task.commit): Authors is in [' . count($groups) . '] groups.'
            );
            foreach ($groups as $group) {
                $sendCommitEmails = $group->getConfig()->getEmailFlag('commits');
                $logger->debug(
                    'Change/Module(task.commit): Group ' . $group->getId()
                    . ' wants emails(' . ($sendCommitEmails ? "yes" : "no") . ")."
                );
                if ($sendCommitEmails) {
                    // Just add the group to the list of recipients, mali/module deals with mailing list stuff
                    $toUsers[] = Config::KEY_PREFIX . $group->getId();

                    // get all members - using the cache this time as it's fast for this case
                    $members = $groupDAO->fetchAllMembers($group->getId(), false, $allGroups, null, $p4Admin);
                    $logger->debug(
                        'Change/Module(task.commit): Members are [' . implode(', ', $members) . '],'
                    );
                    $activity->addFollowers($members);
                }
            }

            // if change was renumbered, update 'change' field on related activity records
            if ($changeId !== $change->getOriginalId()) {
                $options = [Activity::FETCH_BY_CHANGE => $change->getOriginalId()];
                foreach (Activity::fetchAll($options, $p4Admin) as $record) {
                    $record->set('change', $changeId)->save();
                }
            }

            $event->setParam('activity', $activity);
            $logger->debug(
                'Change/Module(task.commit): to list is [' . implode(', ', $toUsers) . ']'
            );

            $event->setParam('mail', ['toUsers' => $toUsers]);
        } catch (\Exception $e) {
            $logger->err($e);
        }
    }

    public function postActivityAndMail(Event $event)
    {
        parent::log($event);
        $p4Admin      = $this->services->get('p4_admin');
        $logger       = $this->services->get('logger');
        $config       = $this->services->get('config');
        $keywords     = $this->services->get('review_keywords');
        $change       = $event->getParam('change');
        $activity     = $event->getParam('activity');
        $findAffected = $this->services->get(Services::AFFECTED_PROJECTS);
        // if no change or no activity, nothing to do
        if (!$change instanceof Change || !$activity instanceof Activity) {
            return;
        }

        // normalize notifications config
        $notifications  = isset($config[Settings::NOTIFICATIONS]) ? $config[Settings::NOTIFICATIONS] : [];
        $notifications += [
            Settings::HONOUR_P4_REVIEWS     => false,
            Settings::OPT_IN_REVIEW_PATH    => null,
            Settings::DISABLE_CHANGE_EMAILS => false
        ];

        // if sending change emails is disabled, nothing to do
        if ($notifications[Settings::DISABLE_CHANGE_EMAILS]) {
            // Set the mail to null, so we don't fail with missing mail template.
            $logger->trace('task.commit -100: returning no mail.');
            $event->setParam('mail', null);
            return;
        }

        try {
            // determine who to send email notifications to:
            // - start with the users already set up in the prior task (where the activity was created)
            // - include users subscribed to review files if that option is explicitly enabled in config
            // - exclude users who don't review the 'opt_in_review_path' (if set)
            $mail    = $event->getParam('mail');
            $toUsers = isset($mail['toUsers']) ? $mail['toUsers'] : [];
            // Keep the original users from the previous task.commit that determined who was interested in
            // the project
            $toUsersFromProjectImpact = $toUsers;

            $reviewPath = $notifications[Settings::OPT_IN_REVIEW_PATH];
            if ($notifications[Settings::HONOUR_P4_REVIEWS]) {
                $data    = $p4Admin->run('reviews', ['-c', $change->getId()])->getData();
                $toUsers = array_merge($toUsers, array_map('current', $data));
                $logger->debug(
                    'Changes: The users with "Reviews" that match the filepaths for change #' . $change->getId()
                    . ' are [' . str_replace(["\n", "\r"], '', var_export($data, true)) . ']'
                );
            }
            if ($reviewPath && is_string($reviewPath)) {
                $data    = $p4Admin->run('reviews', [$reviewPath])->getData();
                $toUsers = array_intersect($toUsers, array_map('current', $data));
                $logger->debug(
                    'Changes: The users with "Reviews" that match the filepaths for opt_in_review_path['
                    . $reviewPath . '] are ['
                    . str_replace(["\n", "\r"], '', var_export($data, true)) . ']'
                );
            }

            // Check if the change contains a stream spec. If it does check if someone is interested in the spec itself
            // not the full stream. If you are interested in //jam/main/... but don't have //jam/main you won't
            // get an email for the spec change only commits. You must have both //jam/main to get them emails.
            $streamSpec = $this->services->get(Services::CHANGE_SERVICE)->getStream($p4Admin, $change);
            if ($streamSpec) {
                $data    = $p4Admin->run('reviews', [$streamSpec])->getData();
                $toUsers = array_merge($toUsers, array_map('current', $data));
                $logger->debug(
                    'Changes: The users with interest in the Stream spec for change #' . $change->getId()
                    . ' are [' . str_replace(["\n", "\r"], '', var_export($data, true)) . ']'
                );
            }

            // After we have determined interest from honour and opt_in make sure we also include users
            // interested in commits on the project
            $toUsers = array_merge($toUsers, $toUsersFromProjectImpact);
            $logger->debug(
                'Changes: After processing the reviews commands responses, the toUsers are now ['
                . str_replace(["\n", "\r"], '', var_export($toUsers, true)) . '].'
            );

            // collapse multiple occurrences of certain characters (e.g. ascii lines) for the subject
            $subject = preg_replace('/([=_+@#%^*-])\1+/', '\1', $keywords->filter($change->getDescription()));

            // check if we have affected projects
            $projects = array_keys($findAffected->findByChange($p4Admin, $change));

            try {
                // if this change is being committed on behalf of someone else, include them and link the topic
                $review = $event->getParam('review');
                if ($review instanceof Review && $review->get('author')) {
                    $toUsers = array_merge($toUsers, [$review->get('author')]);
                    $logger->info("Changes: Adding Author to receive an email.");
                }
            } catch (\Exception $ee) {
                $logger->info(
                    "Changes: Couldn't to get review data when trying to determine author."
                    . $ee->getMessage()
                );
            }

            // configure a message for mail module to deliver
            $mailParams = [
                'author'        => $change->getUser(),
                'subject'       => 'Commit @' . $change->getId() . ' - ' . $subject,
                'cropSubject'   => 80,
                'toUsers'       => $toUsers,
                'fromUser'      => $activity->get('user') ?: $change->getUser(),
                'messageId'     =>
                    '<topic-changes/' . $change->getId()
                    . '@' . Mail::getInstanceName($config['mail']) . '>',
                'projects'      => $projects,
                'htmlTemplate'  => __DIR__ . '/../../view/mail/commit-html.phtml',
                'textTemplate'  => __DIR__ . '/../../view/mail/commit-text.phtml',
            ];
            // If the commit is for a review, align it with the thread
            $review = $event->getParam('review');
            if (null !== $review) {
                $mailParams['subject']   = 'Review @' . $review->getId() . ' - ' . $subject;
                $mailParams['inReplyTo'] = '<topic-reviews/'. $review->getId()
                    . '@' . Mail::getInstanceName($config['mail']) . '>';
            }
            $event->setParam('mail', $mailParams);
        } catch (\Exception $e) {
            $logger->err(
                'Changes: Failed to add users to recipient list using review daemon configurations. '
                . 'honor_p4_reviews [' . $notifications[Settings::HONOUR_P4_REVIEWS] . '], '
                . 'opt_in_review_path [' . $reviewPath . '], '
                . "\n$e"
            );
        }
    }

    public function configureMailReviewers(Event $event)
    {
        parent::log($event);
        // This event handler fires after the other task.commit handlers to add reviewers to a mailto list.
        // If we do not add reviewers here and a reviewer is not mentioned or part of the relevant project
        // then they will never be considered for an email.
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $change   = $event->getParam('change');
        $logger   = $services->get('logger');
        $groupDAO = $services->get(IModelDAO::GROUP_DAO);
        try {
            // ignore invalid/pending changes.
            if (!$change instanceof Change || $change->getStatus() !== 'submitted') {
                return;
            }
            $mail = $event->getParam('mail');
            // As in task.commit -100 we set mail to null, we should attempt to add anyone to the to list.
            if (is_null($mail)) {
                return;
            }
            $toUsers = isset($mail['toUsers']) ? $mail['toUsers'] : [];
            $reviews = Review::fetchAll([Review::FETCH_BY_CHANGE => $change->getOriginalId()], $p4Admin);
            foreach ($reviews as $review) {
                foreach ($review->getParticipants() as $participant) {
                    if (Group::isGroupName($participant) && $groupDAO->exists(Group::getGroupName($participant))) {
                        $toUsers[] = Config::KEY_PREFIX .
                            $groupDAO->fetchById(Group::getGroupName($participant), $p4Admin)->getId();
                    } else {
                        // If there is no valid author or the participant is not the author, add them in.
                        // Authors and handled later in the chain and we only want to deal with reviewers
                        // here
                        $authorId = $review->isValidAuthor() ? $review->getAuthorObject()->getId() : null;
                        if ($authorId === null || $authorId !== $participant) {
                            $toUsers[] = $participant;
                        }
                    }
                }
            }
            $mail['toUsers'] = array_unique($toUsers);
            $event->setParam('mail', $mail);
        } catch (\Exception $e) {
            $logger->err('Changes: Failed to add reviewers to recipient list for task.commit');
            $logger->err($e);
        }
    }

    public function onCommitShelve(Event $event)
    {
        parent::log($event);
        // subscribe very early to simply fetch the change and verify its worth processing at this time.
        //
        // we delay processing changes owned by 'git-fusion-user'. git-fusion commits changes as itself but
        // re-credits them to the author or pusher. There is a window where changes are still owned by
        // git-fusion-user and we don't want to process them during this period.
        //
        // further, we stop processing for changes against the .git-fusion depot. swarm cannot presently
        // show diffs effectively for the light weight branch work done by git fusion and we also want
        // to hide the changes related to git objects and other git-fusion infrastructure work.
        //
        // the below listener gets in very early (prior to even the impacted projects being calculated) and
        // requeues changes in this state into the future.
        $p4Admin             = $this->services->get('p4_admin');
        $id                  = $event->getParam('id');
        $data                = (array) $event->getParam('data') + ['retries' => null];
        $config              = $this->services->get('config') + ['git_fusion' => []];
        $gitConfig           = $config['git_fusion'];
        $gitConfig          += ['user' => null, 'depot' => null, 'reown' => []];
        $gitConfig['reown'] += ['retries' => null, 'max_wait' => null];
        $logger              = $this->services->get('logger');

        try {
            $change = Change::fetchById($id, $p4Admin);
            $event->setParam('change', $change);

            // if we don't know where the git-fusion depot is, just process as-is
            if (!$gitConfig['depot']) {
                return;
            }

            // if the change is under the .git-fusion depot, we don't want activity for it, abort processing
            try {
                $flags = ['-e', $id, '-TdepotFile', '-m1', '//' . trim($gitConfig[ 'depot'], '/') . '/...'];
                $flags = array_merge($change->isPending() ? ['-Rs'] : [], $flags);
                $path  = $p4Admin->run('fstat', $flags)->getData(0, 'depotFile');

                // if we got a hit this is a .git-fusion depot change and we want to ignore it
                // stop the event to prevent activity/email/etc from being created and return
                if ($path) {
                    $event->stopPropagation();
                    return;
                }
            } catch (CommandException $e) {
                // if this is a ".git-fusion depot doesn't exist" type exception just eat it otherwise rethrow
                if (strpos($e->getMessage(), 'must refer to client') === false) {
                    throw $e;
                }
            }

            // if we don't know who the git-fusion-user is, don't delay processing
            if (!$gitConfig['user']) {
                return;
            }

            // if the change isn't owned by the git-fusion-user, no need to delay just return
            if ($change->getUser() != $gitConfig['user']) {
                return;
            }

            // if we've already maxed out our retries, don't delay further just return
            if ($data['retries'] >= $gitConfig['reown']['retries']) {
                $logger->err('Max git-fusion reown retries/delay exceeded for change ' . $id);
                return;
            }

            // at this point we have established the change is owned by the git-fusion-user
            // and it isn't under the .git-fusion depot. we want to abort processing and
            // re-queue the event to be re-considered in the near future
            // our delay gets exponentially larger up to a max (by default 60 seconds)
            // by default, at most we'll re-queue 20 times for a delay of 16 minutes 2 seconds
            $data['retries'] += 1;
            $this->services->get('queue')->addTask(
                $event->getParam('type'),
                $event->getParam('id'),
                $data,
                time() + min(pow(2, $data['retries']), $gitConfig['reown']['max_wait'])
            );

            // stop further processing
            $event->stopPropagation();
        } catch (\Exception $e) {
            $logger->err($e);
        }
    }
}
