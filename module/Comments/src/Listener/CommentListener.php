<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Listener;

use Activity\Model\Activity;
use Api\Controller\CommentsController;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Filter\Linkify;
use Application\Model\IModelDAO;
use Attachments\Model\Attachment;
use Comments\Model\Comment;
use Comments\Module;
use Events\Listener\AbstractEventListener;
use Exception;
use Groups\Model\Config;
use Groups\Model\Group;
use Mail\MailAction;
use P4\Spec\Change;
use P4\Spec\Job;
use Reviews\Model\Review;
use RuntimeException;
use Laminas\EventManager\Event;
use Mail\Module as Mail;

class CommentListener extends AbstractEventListener
{

    public function commentCreated(Event $event)
    {
        parent::log($event);
        $services = $this->services;
        $logger   = $services->get('logger');
        $p4Admin  = $services->get('p4_admin');
        $config   = $services->get('config');
        $keywords = $services->get('review_keywords');
        $id       = $event->getParam('id');
        $data     = $event->getParam('data') + [
                'user'                                         => null,
                'previous'                                     => [],
                'current'                                      => [],
                'sendComments'                                 => [],
                'quiet'                                        => null,
                CommentsController::SILENCE_NOTIFICATION_PARAM => false
            ];

        $logger->info('Processing ' . $event->getName() . ' for ' . $id);

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

        $quiet               = $event->getParam('quiet', $data['quiet']);
        $silenceNotification =
            $event->getParam(
                CommentsController::SILENCE_NOTIFICATION_PARAM,
                $data[CommentsController::SILENCE_NOTIFICATION_PARAM]
            );
        $sendBatch           = is_array($data['sendComments']) && count($data['sendComments']);

        try {
            $userDAO  = $services->get(IModelDAO::USER_DAO);
            $groupDAO = $services->get(IModelDAO::GROUP_DAO);
            // fetch comment record
            $comment = Comment::fetch($id, $p4Admin);
            $context = $comment->getFileContext();
            $event->setParam('comment', $comment);

            // there are several types of comment activity - compare new against old to see what happened
            $data['current'] = $data['current'] ?: $comment->get();
            $commentAction   = Comment::deriveAction($data['current'], $data['previous']);
            $event->setParam('commentAction', $commentAction);

            // exit early if the comment was not modified or it was unliked
            if (in_array($commentAction, [Comment::ACTION_NONE, Comment::ACTION_UNLIKE])) {
                // might still need to send delayed comments (user likely ended batch with no edits)
                if ($sendBatch) {
                    $services->get('queue')->addTask(
                        'comment.batch',
                        $comment->get('topic'),
                        ['sendComments' => $data[ 'sendComments']]
                    );
                }
                return;
            }
            $isDescriptionComment = $comment->isDescriptionComment();
            // determine the action to report
            $action    = $isDescriptionComment
                ? MailAction::DESCRIPTION_COMMENT_ADDED : MailAction::COMMENT_ADDED;
            $taskState = $data['current']['taskState'];
            if ($commentAction === Comment::ACTION_ADD && $taskState !== Comment::TASK_COMMENT) {
                $action = MailAction::REVIEW_OPENED_ISSUE;
            } elseif ($commentAction === Comment::ACTION_STATE_CHANGE) {
                $oldState    = isset($data['previous']['taskState'])
                    ? $data['previous']['taskState']
                    : null;
                $transitions = [
                    Comment::TASK_COMMENT   => 'cleared',
                    Comment::TASK_OPEN      => $oldState === Comment::TASK_COMMENT
                        ? 'opened'
                        : 'reopened',
                    Comment::TASK_ADDRESSED => 'addressed',
                    Comment::TASK_VERIFIED  => 'verified'
                ];

                $action = isset($transitions[$taskState])
                    ? $transitions[$taskState] . ' an issue on'
                    : 'changed task state on';
            } elseif ($commentAction === Comment::ACTION_EDIT) {
                $action = $isDescriptionComment
                    ? MailAction::DESCRIPTION_COMMENT_EDITED : MailAction::COMMENT_EDITED;
            } elseif ($commentAction === Comment::ACTION_LIKE) {
                $action = $isDescriptionComment
                    ? MailAction::DESCRIPTION_COMMENT_LIKED : MailAction::COMMENT_LIKED;
            } elseif ($commentAction === Comment::ACTION_ARCHIVE) {
                $action = MailAction::COMMENT_ARCHIVED;
            } elseif ($commentAction === Comment::ACTION_UNARCHIVE) {
                $action = MailAction::COMMENT_UNARCHIVED;
            } elseif ($comment->isReply() === true) {
                $action = MailAction::COMMENT_REPLY;
            }
            // prepare comment info for activity streams
            $activity = new Activity();
            $activity->set(
                [
                    'type'          => 'comment',
                    'user'          => $data['user'] ?: $comment->get('user'),
                    'action'        => $action,
                    'target'        => $comment->get('topic'),
                    'description'   => $comment->get('body'),
                    'topic'         => $comment->get('topic'),
                    'depotFile'     => $context['file'],
                    'time'          => $comment->get('updated')
                ]
            );
            $event->setParam('activity', $activity);

            // prepare attachment info for comment notification emails
            if ($comment->get('attachments')) {
                $event->setParam(
                    'attachments',
                    Attachment::fetchAll(
                        [Attachment::FETCH_BY_IDS => $comment->get('attachments')],
                        $p4Admin
                    )
                );
            }

            // default mail message subject is simply the topic name.
            $subject = $comment->get('topic');

            // enhance activity and mail info if we recognize the topic type
            $to    = [];
            $topic = $comment->get('topic');

            // setup default value of In-Reply-To header
            $inReplyTo = '<topic-' . $topic . '@' . Mail::getInstanceName($config['mail']) . '>';
            $type      = 'comment';


            // start by priming mentions with valid users in this new comment
            // later we'll also add in @mentions from other locations
            $mentions = Linkify::getCallouts($comment->get('body'));

            // handle change comments
            if (strpos($topic, 'changes/') === 0) {
                $change = $context['change'] ?: str_replace('changes/', '', $topic);
                $target = 'change ' . $change;
                $hash   = 'comments';
                if ($context['file']) {
                    $target .= " (" . $comment::getFileTarget($context) . ")";
                    $hash    = $context['md5'] . ',c' . $comment->getId();
                }

                $activity->set('target', $target);
                $activity->set('link', ['change', ['change' => $change, 'fragment' => $hash]]);

                try {
                    $change = Change::fetchById($change, $p4Admin);
                    $event->setParam('change', $change);
                    // set 'change' field on activity, we want to ensure its the change id
                    // in theory it might be different from $change in case the change was renumbered
                    // and we got it from the topic as topics keep reference to the original id
                    $activity->set('change', $change->getId());

                    $findAffected = $services->get(Services::AFFECTED_PROJECTS);
                    // change author, @mentions and project(s) should be notified
                    $to[]     = $change->getUser();
                    $mentions = array_merge($mentions, Linkify::getCallouts($change->getDescription()));
                    $activity->addFollowers($change->getUser());
                    $activity->addProjects($findAffected->findByChange($p4Admin, $change));

                    // enhance mail subject to use the change description (will be cropped)
                    $subject = 'Change @' . $change->getId() . ' - '
                        . $keywords->filter($change->getDescription());
                } catch (Exception $e) {
                    $logger->err($e);
                }
            }
            // handle review comments
            if (strpos($topic, 'reviews/') === 0) {
                $review  = $context['review'] ?: str_replace('reviews/', '', $topic);
                $target  = 'review ' . $review;
                $hash    = 'comments';
                $version = isset($context['version']) ? $context['version'] : null;
                if ($context['file']) {
                    if ($version) {
                        $target .= " (revision " . $version . ")";
                    }
                    $target .= " (" . $comment::getFileTarget($context) . ")";
                    $hash    = $context['md5'] . ',c' . $comment->getId();
                }
                $activity->set('target', $target);
                $activity->set(
                    'link',
                    ['review', ['review' => $review, 'fragment' => $hash, 'version' => $version]]
                );

                try {
                    $review = Review::fetch($review, $p4Admin);
                    $event->setParam('review', $review);

                    // associate activity with review's head change so we can filter for restricted changes
                    $activity->set('change', $review->getHeadChange());

                    // add any folks that were @*mentioned as required reviewers
                    $review->addRequired(
                        $userDAO->filter(
                            Linkify::getCallouts($comment->get('body'), true),
                            $p4Admin,
                            $usersBlacklist
                        )
                    );
                    // add any groups that were @@*mentioned as required reviewers
                    $review->addRequired(
                        $groupDAO->filter(
                            Linkify::getCallouts($comment->get('body'), true),
                            $p4Admin,
                            $groupsBlacklist
                        )
                    );
                    // Add any groups that where @@!mentions as require one.
                    $review->addRequired(
                        $groupDAO->filter(
                            Linkify::getCallouts($comment->get('body'), false, true),
                            $p4Admin,
                            $groupsBlacklist
                        ),
                        "1"
                    );

                    // comment author and, valid, @mentioned users should be participants
                    $review->addParticipant($comment->get('user'))
                        ->addParticipant($userDAO->filter($mentions, $p4Admin, $usersBlacklist))
                        ->addParticipant($groupDAO->filter($mentions, $p4Admin, $groupsBlacklist))
                        ->save();

                    // review comments should appear on the review stream
                    $activity->addStream('review-' . $review->getId());

                    // all review participants excluding those with notifications disabled should be notified
                    $disabled = $review->getParticipantsData('notificationsDisabled');
                    $to       = array_diff($review->getParticipants(), array_keys($disabled));
                    $activity->addFollowers($review->getParticipants());
                    $activity->addProjects($review->getProjects());

                    if (isset($context['comment'])) {
                        // The comment is a reply - add the author of the replied to comment so they should
                        // be notified as long as they haven't disabled notifications for this review
                        $parentComment = Comment::fetch($context['comment'], $p4Admin);
                        if ($parentComment) {
                            $parentAuthor = $parentComment->get('user');
                            if ($parentAuthor && !array_key_exists($parentAuthor, array_keys($disabled))) {
                                $to[] = $parentAuthor;
                            }
                        }
                    }

                    // enhance mail subject to use the review description (will be cropped)
                    $subject = 'Review @' . $review->getId() . ' - '
                        . $keywords->filter($review->get('description'));
                } catch (Exception $e) {
                    $logger->err($e);
                }
            }

            // handle job comments
            if (strpos($topic, 'jobs/') === 0) {
                $job    = str_replace('jobs/', '', $topic);
                $target = $job;
                $hash   = 'comments';

                $activity->set('target', $target);
                $activity->set('link', ['job', ['job' => $job, 'fragment' => $hash]]);

                try {
                    $job = Job::fetchById($job, $p4Admin);

                    // add author, modifier and possibly others to the email recipients list
                    // we find users by looping through all job's defined fields and looking
                    // for default value of '$user'
                    $fields = $job->getSpecDefinition()->getFields();
                    foreach ($fields as $key => $field) {
                        if (isset($field['default']) && $field['default'] === '$user') {
                            $to[] = $job->get($key);
                        }
                    }

                    // notify users mentioned in job's description
                    $to = array_merge($to, Linkify::getCallouts($job->getDescription()));

                    // associated change(s) users should also be notified
                    if (count($job->getChanges())) {
                        foreach ($job->getChangeObjects() as $change) {
                            $to[] = $change->getUser();
                        }
                    }

                    // enhance mail subject to use the job description (will be cropped)
                    $subject = $job->getId() . ' - ' . $job->getDescription();
                } catch (Exception $e) {
                    $logger->err($e);
                }
            }

            // every user that participates in this comment thread
            // should be notified of this activity (excluding author).
            try {
                // if the topic isn't a review we want to included any previous commenters and mentioned users.
                // if the topic is a review, we skip this step and simply rely on the review participants,
                // otherwise we might erroneously add back in removed reviewers
                if (strpos($topic, 'reviews/') !== 0) {
                    // examine every comment on this topic to include:
                    // - all users who posted a comment to the topic
                    // - all users who were mentioned in a comment on this topic
                    $comments = Comment::fetchAll(['topic' => $topic], $p4Admin);
                    $users    = [];
                    foreach ($comments as $entry) {
                        $users[]  = $entry->get('user');
                        $mentions = array_merge($mentions, Linkify::getCallouts($entry->get('body')));
                    }

                    $to = array_merge($to, $users);
                }

                // knock back the to list to only unique, valid ids
                $to = array_unique(array_merge($to, $mentions));
                // Get each group and ensure group and user exist and are real.
                $userTo  = $userDAO->filter($to, $p4Admin, $usersBlacklist);
                $groupTo = $groupDAO->filter($to, $p4Admin, $groupsBlacklist);
                // Allow the mail module to process anything we are unsure of
                $otherTo = array_diff($to, $userTo, $groupTo);
                // Gather up the potential recipients and make mail module friendly keys
                $to = array_merge(
                    $userTo,
                    array_map(
                        function ($group) {
                            return Config::KEY_PREFIX . Group::getGroupName($group);
                        },
                        $groupTo
                    ),
                    $otherTo
                );
                // if we're emailing you its activity stream worthy, add em
                $activity->addFollowers($to);

                // if receiving emails for own actions is not enabled
                // don't email the person who carried out the action
                $to = $config['mail']['notify_self']
                    ? $to
                    : array_diff($to, [$data[ 'user'] ?: $comment->get('user')]);

                // if it's a new like, just email the comment author
                if ($commentAction === Comment::ACTION_LIKE) {
                    // remove like author from the recipients
                    $to = array_diff($to, [$data[ 'user'] ?: $comment->get('user')]);

                    // only email the comment author if they are already a recipient
                    // this avoids emailing users who like their own comments
                    $to = array_intersect($to, [$comment->get('user')]);
                };

                // configure mail notification - only email for adds, edits and new likes
                $actionsToEmail = [
                    Comment::ACTION_ADD,
                    Comment::ACTION_EDIT,
                    Comment::ACTION_LIKE
                ];


                // setup default header values
                $createdMessageID =  $comment->createMessageId();
                $messageId        = '<' . $createdMessageID
                    . ( $commentAction !== Comment::ACTION_ADD
                        ? ( '-' . $commentAction . '-' . time() ) : "" )
                    . '@' . Mail::getInstanceName($config['mail']) . '>';
                $inReplyTo        = '<topic-' . $topic  . '@' . Mail::getInstanceName($config['mail']) . '>';

                // try to find previous comment in this topic, so that we can sub-thread comments
                $previousComment = $comment->getPreviousComment($p4Admin);
                if (null !== $previousComment) {
                    $inReplyTo = '<' . $previousComment->createMessageId()
                        . '@' . Mail::getInstanceName($config['mail']) . '>';
                }
                // Make comment likes replies to the original comment
                if (Comment::ACTION_LIKE === $commentAction) {
                    $inReplyTo = '<' . $createdMessageID . '@' . Mail::getInstanceName($config['mail']) . '>';
                }
                $logger->trace(
                    'Comment: Send a ' . $commentAction . '/' . $action . ' email for(' . $id . ').'
                    . 'Comment action in (' . implode(', ', $actionsToEmail) . ') = '
                    . (in_array($commentAction, $actionsToEmail) ? 'true' : 'false') . ', or '
                    . $action . ' is opened/reopened ' . strpos($action, MailAction::REVIEW_OPENED_ISSUE)
                );
                if ((in_array($commentAction, $actionsToEmail) ||
                    strpos($action, MailAction::REVIEW_OPENED_ISSUE) !== false) &&
                    $silenceNotification === false) {
                    $event->setParam(
                        'mail',
                        [
                            'author'        => $comment->get('user'),
                            'subject'       => $subject,
                            'cropSubject'   => 80,
                            'toUsers'       => $to,
                            'fromUser'      => $data['user'] ?: $comment->get('user'),
                            'messageId'     => $messageId,
                            'inReplyTo'     => $inReplyTo,
                            'projects'      => array_keys($activity->getProjects()),
                            'htmlTemplate'  => __DIR__ . '/../../view/mail/comment-html.phtml',
                            'textTemplate'  => __DIR__ . '/../../view/mail/comment-text.phtml',
                        ]
                    );
                    $logger->debug("Comment: Added email details to " . $event->getName() . ' for ' . $id);
                }

                // create batch task if we were instructed to send notification for delayed comments
                // we do it after this task has updated related records so the batch task can pull
                // out fresh data
                if ($sendBatch && $silenceNotification === false) {
                    $logger->debug('Comment: Queuing batch notification for ' . $id);
                    $services->get('queue')->addTask(
                        'comment.batch',
                        $topic,
                        ['sendComments' => $data[ 'sendComments']]
                    );

                    // silence email as the batch task will include this comment in the aggregated notification
                    if ($quiet !== true) {
                        $quiet = array_merge((array) $quiet, ['mail']);
                        $event->setParam('quiet', $quiet);
                    }
                    // 'quiet' is a little odd in that some callers set it to true to try and silence everything,
                    // whereas sometimes it will be an array with for example the words 'mail' or 'activity' to
                    // try and silence a particular action
                } elseif (($quiet === true
                        || in_array('mail', (array) $quiet)) && $silenceNotification === false) {
                    $delayTime = ConfigManager::getValue(
                        $config,
                        ConfigManager::COMMENT_NOTIFICATION_DELAY_TIME
                    );
                    $logger->trace(
                        "Comment:: Comment Id: " . $comment->getId() . " This is not batched " . $delayTime
                    );
                    // If it is set to zero don't delay comments.
                    if ($delayTime > 0) {
                        $now    = time();
                        $future = $now + $delayTime;
                        $logger->trace(
                            "Comment:: Comment Id: " . $comment->getId() . " Adding this to the future: "
                            . $future . " Time now is " . $now
                        );
                        // Get any existing future comment notification tasks for this topic and user and
                        // remove them
                        $hash = Module::getFutureCommentNotificationHash(
                            $topic,
                            $comment->get('user')
                        );

                        $deleted = $services->get('queue')->deleteTasksByHash($hash);
                        // Add the use that made this comment to be able to fetch that users
                        // comments out later.
                        $services->get('queue')->addTask(
                            'commentSendDelay',
                            $topic,
                            ['user' => $comment->get('user'), 'notificationDelayTime' => $delayTime],
                            $future,
                            $hash
                        );
                        $logger->debug(
                            "Comment: Add a Timer to queue for " . $id . ". This will trigger at " . $future
                            . ",time now is " . $now
                        );
                        if (sizeof($deleted) > 0) {
                            $logger->trace(
                                'Comment: Deleted the future tasks ' . var_export($deleted, true)
                            );
                        }
                    }
                }
                $logger->debug("Comment: Finished processing " . $event->getName() . " for " . $id);
            } catch (Exception $e) {
                $logger->err($e);
            }
        } catch (Exception $e) {
            $logger->err($e);
        }
    }

    public function commentBatch(Event $event)
    {
        parent::log($event);
        $services = $this->services;
        $logger   = $services->get('logger');
        $config   = $services->get('config');
        $p4Admin  = $services->get('p4_admin');
        $keywords = $services->get('review_keywords');
        $topic    = $event->getParam('id');
        $data     = $event->getParam('data') + ['sendComments' => null, 'quiet' => null];
        $logger->debug("Processing " . $event->getName() . " for " . $topic);

        try {
            // we need the review model - bail if we can't fetch it
            if (strpos($topic, 'reviews/') !== 0) {
                throw new RuntimeException("Unexpected topic for comment batch ($topic).");
            }
            $review = Review::fetch(str_replace('reviews/', '', $topic), $p4Admin);

            // we need the comment records - bail if we have none
            $commentIds = (array) array_keys($data['sendComments']);
            $comments   = Comment::fetchAll(
                [Comment::FETCH_BY_IDS => $commentIds],
                $p4Admin
            );
            $totalCount = $comments->count();
            if (!$totalCount) {
                throw new RuntimeException("No valid comments in comment batch.");
            }

            // preserve the order that the comments appear in the batch
            $comments->sortBy('id', [$comments::SORT_FIXED => $commentIds]);

            // set event parameters for use in the templates
            $attachments = Comment::fetchAttachmentsByComments($comments, $p4Admin);
            $event->setParam('attachments',  $attachments);
            $event->setParam('comments',     $comments);
            $event->setParam('review',       $review);
            $event->setParam('sendComments', $data['sendComments']);

            // Determine if all the comments are edited so we can set the appropriate action
            $editedCount = 0;
            foreach ($comments as $comment) {
                if ($comment->get('edited')) {
                    $editedCount++;
                } else {
                    // break early if we find any that are not edited
                    break;
                }
            }

            $activity = new Activity;
            $activity->set(
                [
                    'type'          => 'comment',
                    'user'          => '',
                    // If all are edited comments the action should be edit, otherwise treat as added
                    'action'        =>
                        $editedCount === $totalCount ? MailAction::COMMENT_EDITED : MailAction::COMMENT_ADDED,
                    'target'        => '',
                    'description'   => '',
                    'topic'         => '',
                    'depotFile'     => '',
                    'time'          => ''
                ]
            );
            // Set the activity ensuring that the type and action is set. We are making the activity quiet
            // as it will have already been created when the comment was initially made. We still need an
            // activity so that the mail module has an action to work with but making it quiet will mean
            // it does not get saved in Activity/Module event processing
            $event->setParam('activity', $activity);
            $quiet = $event->getParam('quiet', $data['quiet']);
            if ($quiet !== true) {
                // Specify that activity is quiet as it is not turned on for everything
                $quiet = array_merge((array) $quiet, ['activity']);
                $event->setParam('quiet', $quiet);
            }
            // since all comments are on the same review, we set 'restrictByChange'
            // to the review's head change to enable filtering by restricted changes
            $event->setParam('restrictByChange', $review->getHeadChange());
            $user = $comments->first()->get('user');
            $comments->filterByCallback(
                function ($comment) {
                    return !in_array('closed', $comment->getFlags());
                }
            );
            // Check to see if there are still comments after filtering
            if ($comments->first()) {
                // notify all review participants excluding the comment author
                // Add the participants to the to list.
                $to = $review->getParticipants();

                // if receiving emails for own actions is not enabled
                // don't email the person who carried out the action
                $to = $config['mail']['notify_self'] ? $to : array_diff($to, [$user]);

                // set parameters for the mail listener
                $subject = 'Review @' . $review->getId() . ' - '
                    . $keywords->filter($review->get('description'));
                $event->setParam(
                    'mail',
                    [
                        'author' => $comments->first()->get('user'),
                        'subject' => $subject,
                        'cropSubject' => 80,
                        'toUsers' => $to,
                        'fromUser' => $user,
                        'projects' => array_keys($review->getProjects()),
                        'messageId' =>
                            '<comments-batch-' . $topic . '-'
                            . time() . '@' . Mail::getInstanceName($config['mail']) . '>',
                        'inReplyTo' => '<topic-' . $topic . '@' . Mail::getInstanceName($config['mail']) . '>',
                        'htmlTemplate' => __DIR__ . '/../../view/mail/batch-comments-html.phtml',
                        'textTemplate' => __DIR__ . '/../../view/mail/batch-comments-text.phtml',
                    ]
                );
                $logger->debug("Added email details to " . $event->getName() . ' for ' . $topic);
                $logger->debug("Finished processing " . $event->getName() . " for " . $topic);
            } else {
                $logger->debug(
                    "All comments are closed, an email will not be sent for event " .
                    $event->getName() . " and topic " . $topic
                );
            }
            // Remove any future tasks for the topic and user
            $deleted = $services->get('queue')->deleteTasksByHash(
                Module::getFutureCommentNotificationHash($topic, $user)
            );
            if (sizeof($deleted) > 0) {
                $logger->trace(
                    'Comment: Sending batched, deleted the future tasks ' . var_export($deleted, true)
                );
            }
        } catch (Exception $e) {
            $logger->err($e);
        }
    }

    public function commentSendDelay(Event $event)
    {
        parent::log($event);
        $services = $this->services;
        $logger   = $services->get('logger');
        $p4Admin  = $services->get('p4_admin');
        $topic    = $event->getParam('id');
        $data     = $event->getParam('data'); // Get the data from the event.
        $userId   = $data['user']; // Then get the user that made the comment.
        $logger->debug("Comment: Processing Delayed " . $event->getName() . " for " . $topic);
        // Fetch the user that made the comment.
        $user = $services->get(IModelDAO::USER_DAO)->fetchById($userId, $p4Admin);
        Module::sendDelayedComments($services, $user, $topic);
    }
}
