<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Mail;

use Application\Config\ConfigManager;
use Application\Model\IModelDAO;
use Application\Model\ServicesModelTrait;
use Application\Permissions\ConfigCheck;
use Notifications\Settings;
use P4\Exception;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Projects\Model\Project as ProjectModel;
use Users\Model\User;
use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use Laminas\EventManager\Event;
use Laminas\Mail\Message;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Part as MimePart;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\StringUtils;
use Laminas\Validator\EmailAddress;
use Laminas\View\Model\ViewModel;
use Laminas\View\Resolver\TemplatePathStack;

class Module
{
    public static function buildMessage(Event $event, ServiceManager $services)
    {
        $mail = $event->getParam('mail');
        if (!is_array($mail)) {
            return;
        }
        $logger   = $services->get('logger');
        $activity = $event->getParam('activity');
        // Activity will not be set for batched comments, so consider action to be undetermined
        $mailAction       = $activity && $activity->get('action') !== null
            ? $activity->get('action')
            : Settings::UNDETERMINED;
        $restrictByChange = $event->getParam('restrictByChange', $activity ? $activity->get('change') : null);
        $logger->info(
            "Mail:Looking for mail to send with " . $event->getName() . ' ( ' . $event->getParam('id') . ')'
        );

        // ignore 'quiet' events.
        $data  = (array) $event->getParam('data') + ['quiet' => null];
        $quiet = $event->getParam('quiet', $data['quiet']);
        if ($quiet === true || in_array('mail', (array) $quiet)) {
            $logger->info("Mail:Mail event is silent(notifications are being batched), returning.");
            return;
        }
        // normalize and validate message configuration
        $mail += [
            'author'       => null,
            'to'           => null,
            'toUsers'      => null,
            'review'       => null,
            'subject'      => null,
            'cropSubject'  => false,
            'fromAddress'  => null,
            'fromName'     => null,
            'fromUser'     => null,
            'messageId'    => null,
            'inReplyTo'    => null,
            'htmlTemplate' => null,
            'textTemplate' => null,
            'projects'     => [],
            'references'   => null
        ];
        // Information used to build x-swarm... headers
        $reviewAuthor = null;

        // detect bad templates, clear them (to avoid later errors) and log it
        $invalidTemplates = [];
        foreach (['htmlTemplate', 'textTemplate'] as $templateKey) {
            if ($mail[$templateKey] && !is_readable($mail[$templateKey])) {
                $invalidTemplates[] = $mail[$templateKey];
                $mail[$templateKey] = null;
            }
        }
        if (count($invalidTemplates)) {
            $logger->err(
                'Mail:Invalid mail template(s) specified: ' . implode(', ', $invalidTemplates)
            );
        }

        if (!$mail['htmlTemplate'] && !$mail['textTemplate']) {
            $logger->err("Mail:Cannot send mail. No valid templates specified.");
            // Add more diagnostics for this
            $logger->warn(
                'Mail-Diagnostics: Additional information for support when there are no valid mail templates. '
                . count($invalidTemplates) . " invalid template(s) found. "
                . "Mail parameters [" . str_replace(["\n", "\r"], '', var_export($mail, true)) . ']'
                . 'There are probably earlier ERR messages relating to constructing the mail data.'
            );
            foreach ($invalidTemplates as $invalidTemplate) {
                $logger->warn(
                    'Mail-Diagnostics: '
                    . "Template " . $invalidTemplate . ( is_readable($invalidTemplate) ? " readable" : " not readable" )
                );
            }
            return;
        }

        // normalize mail configuration, start by ensuring all of the keys are at least present
        $configs = $services->get('config') + ['mail' => []];
        $config  = $configs['mail'] +
            [
                'sender'         => null,
                'recipients'     => null,
                'subject_prefix' => null,
                'use_bcc'        => null,
                'use_replyto'    => true
            ];
        $change  = $event->getParam('change');
        // if we are configured not to email events involving restricted changes
        // and this event has a change to restrict by, dig into the associated change.
        // if the associated change ends up being restricted, bail.
        if ((!isset($configs['security']['email_restricted_changes'])
                || !$configs['security']['email_restricted_changes'])
            && $restrictByChange
        ) {
            // try and re-use the event's change if it has a matching id otherwise do a fetch
            if (!$change instanceof Change || $change->getId() != $restrictByChange) {
                try {
                    $change = Change::fetchById($restrictByChange, $services->get('p4_admin'));
                } catch (NotFoundException $e) {
                    // if we cannot fetch the change, we have to assume
                    // it's restricted and bail out of sending email
                    $logger->info(
                        "Mail: Email not sent. Change cannot fetch. Assuming it is restricted change."
                    );
                    return;
                }
            }

            // if the change is restricted, don't email just bail
            if ($change->getType() == Change::RESTRICTED_CHANGE) {
                $logger->info(
                    "Mail: Email not sent. It is a restricted change."
                );
                return;
            }
        }

        // if sender has no value use the default
        $config['sender'] = $config['sender'] ?: 'notifications@' . $configs['environment']['hostname'];
        $logger->debug("Mail:Using sender of " . $config['sender']);

        // if subject prefix was specified or is an empty string, use it.
        // for unspecified or null subject prefixes we use the default.
        $config['subject_prefix'] = $config['subject_prefix'] || $config['subject_prefix'] === ''
            ? $config['subject_prefix'] : '[Swarm]';

        // as a convenience, listeners may specify to/from as usernames
        // and we will resolve these into the appropriate email addresses.
        $to = (array) $mail['to'];
        $logger->trace(
            "Mail: mailTo list passed in: " . var_export($to, true)
        );
        $toUsers      = array_unique((array) $mail['toUsers']);
        $participants = [];
        $groups       = [];
        $users        = [];
        $seen         = [];
        // This will allow us to work out action roles later
        $expandedFromList = [];
        if (count($toUsers)) {
            $p4Admin = $services->get('p4_admin');

            // Expand users from groups that are not using a mailing list, including project members.
            $logger->debug(
                "Mail: To user list before expansion is [" . implode(", ", $toUsers) . ']. '
            );

            // We need this defined before we call Module::expandParticipants
            // since we will use it to stop the expansion of blacklisted groups
            $groupsBlacklist = ConfigManager::getValue($configs, ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST, []);

            $participants = Module::expandParticipants(
                $toUsers,
                $p4Admin,
                $groupsBlacklist,
                $groups,
                $expandedFromList,
                $seen,
                $logger
            );

            $usersBlacklist = ConfigManager::getValue($configs, ConfigManager::MENTIONS_USERS_EXCLUDE_LIST, []);

            if (!empty($usersBlacklist)) {
                $logger->debug("Mail: Removing blacklisted users [" . implode(", ", $usersBlacklist) . ']. ');
                $whitelist     = [];
                $caseSensitive = $p4Admin->isCaseSensitive();

                foreach ($participants as $participant) {
                    if (!ConfigCheck::isExcluded($participant, $usersBlacklist, $caseSensitive)) {
                        $whitelist[] = $participant;
                    }
                }

                $participants = $whitelist;
            }
            $logger->debug(
                "Mail: Participant list after expansion is [" . implode(", ", $participants) . ']. '
                . "Expansion list is [" . var_export($expandedFromList, true) . "]"
            );

            $userDAO = $services->get(IModelDAO::USER_DAO);
            // Get all of the user objects
            $users = $userDAO->fetchAll(
                [
                    User::FETCH_BY_NAME => array_unique(array_merge($participants, (array) $mail['fromUser']))
                ]
            );
        }

        if (is_array($participants)) {
            $logger->trace(
                "Mail: List of Participants is " . var_export($participants, true)
            );

            // Include the configured email validator options.
            $validator = new EmailAddress(
                isset($config['mail']['validator'])
                    ? $config['mail']['validator']['options']
                    : []
            );

            // make sure that it is ok to send an email to the given recipients
            foreach ($participants as $toUser) {
                // check if this participant is a group
                $isGroup = Group::isGroupName($toUser);
                // Get the participant data
                $participant = $isGroup
                    ? (isset($groups[$toUser]) ? $groups[$toUser] : '')
                    : (isset($users[$toUser]) ? $users[$toUser] : '');
                // If we have participant data move on to checking if they want email.
                if ($participant !== '') {
                    // Closure for getting participant email.
                    $email = function ($isGroup, $participant) {
                        return $isGroup ? $participant->getConfig()->get('emailAddress') : $participant->getEmail();
                    };
                    // Closure for getting participant notification settings.
                    $notifications = function ($isGroup, $participant) {
                        return $isGroup ? $participant->getConfig()->getNotificationSettings()
                            : $participant->getConfig()->getUserNotificationSettings();
                    };
                    // Closure to check if participant wants email or not based on settings.
                    $isMailEnabled = function ($isGroup, $settings, $mailAction, $notificationOptions) {
                        return $isGroup ?
                            $settings->isMailEnabledForGroup($mailAction, $notificationOptions)
                            : $settings->isMailEnabledForUser($mailAction, $notificationOptions);
                    };
                    // Moving the review object out of the getFilterToList
                    $review = $event->getParam('review');
                    if ($review) {
                        if ($review->isValidAuthor()) {
                            $authorUser   = $review->getAuthorObject();
                            $reviewAuthor = $authorUser->getId() . ' (' . $authorUser->getFullName() . ')';
                        } else {
                            // In case author has been deleted
                            $reviewAuthor = $review->getRawValue('author');
                        }
                    }

                    // Now run the email checking process.
                    $to = array_merge(
                        $to,
                        Module::getFilteredToList(
                            $participant,
                            $toUser,
                            $services,
                            $validator,
                            $event,
                            $email,
                            $users,
                            $expandedFromList,
                            $notifications,
                            $isMailEnabled,
                            $review,
                            $isGroup ? 'Group' : 'User',
                            $change
                        )
                    );
                    $logger->trace(
                        "Mail: The new merged To list: " . var_export($to, true)
                    );
                }
            }
        }
        if (isset($users[$mail['fromUser']])) {
            $fromUser            = $users[$mail['fromUser']];
            $mail['fromAddress'] = $fromUser->getEmail()    ?: $mail['fromAddress'];
            $mail['fromName']    = $fromUser->getFullName() ?: $mail['fromName'];
        }

        // remove any duplicate or empty recipient addresses
        $to = array_unique(array_filter($to, 'strlen'));

        // if we don't have any recipients, nothing more to do
        if (!$to && !$config['recipients']) {
            $logger->info("Mail:Not sending email, address list and config['recipients'] are empty");
            return;
        }

        // if explicit recipients have been configured (e.g. for testing),
        // log the computed list of recipients for debug purposes.
        if ($config['recipients']) {
            $logger->debug('Mail:Mail recipients: ' . implode(', ', $to));
        }

        // prepare view for rendering message template
        // customize view resolver to only look for the specific
        // templates we've been given (note we cloned view, so it's ok)
        $renderer = clone $services->get('ViewRenderer');
        $resolver = new TemplatePathStack;
        $resolver->addPaths([dirname($mail[ 'htmlTemplate']), dirname($mail[ 'textTemplate'])]);
        $renderer->setResolver($resolver);
        $viewModel = new ViewModel(
            [
                'services'  => $services,
                'event'     => $event,
                'activity'  => $activity
            ]
        );

        // message has up to two parts (html and plain-text)
        $parts = [];
        if ($mail['textTemplate']) {
            $viewModel->setTemplate(basename($mail['textTemplate']));
            $text       = new MimePart($renderer->render($viewModel));
            $text->type = 'text/plain; charset=UTF-8';
            $parts[]    = $text;
        }
        if ($mail['htmlTemplate']) {
            $viewModel->setTemplate(basename($mail['htmlTemplate']));
            $html       = new MimePart($renderer->render($viewModel));
            $html->type = 'text/html; charset=UTF-8';
            $parts[]    = $html;
        }

        // prepare subject by applying prefix, collapsing whitespace,
        // trimming whitespace or dashes and optionally cropping
        $subject = $config['subject_prefix'] . ' ' . $mail['subject'];
        $subject = trim($subject, "- \t\n\r\0\x0B");
        if ($mail['cropSubject']) {
            $utility = StringUtils::getWrapper();
            $length  = strlen($subject);
            $logger->trace("Mail cropSubject is true, subject is: '$subject' the length is $length");
            $subject  = $utility->substr($subject, 0, (int) $mail['cropSubject']);
            $subject .= strlen($subject) < $length ? '...' : '';
            $logger->trace("Mail: final subject is: $subject");
        }
        $subject = preg_replace('/\s+/', " ", $subject);

        // Allow thread indexing to be disabled via the mail config
        $threadIndex = null;
        if (!isset($config['index-conversations']) || $config['index-conversations']) {
            // prepare thread-index header for outlook/exchange
            // - thread-index is 6-bytes of FILETIME followed by a 16-byte GUID
            // - time can vary between messages in a thread, but the GUID can't
            // - current time in FILETIME format is the number of 100 nanosecond
            //   intervals since the win32 epoch (January 1, 1601 UTC)
            // - GUID is inReplyTo header(or message id for a new thread) md5'd and packed into 16 bytes
            // - the time and GUID are then combined and base-64 encoded
            $fileTime = (time() + 11644473600) * 10000000;
            // Nn = unsigned long, unsigned short, big endian
            $fileTime = pack('Nn', $fileTime >> 32, $fileTime >> 16);
            // H* = hex string, high nibble first, all chars
            $guid        = pack('H*', md5($mail['inReplyTo'] ?: ($mail['messageId'])));
            $threadIndex = base64_encode($fileTime . $guid);
            $logger->debug(
                "Mail: file time[" . bin2hex($fileTime) . "] inReplyTo[" . $mail['inReplyTo']
                . "] messageID[" . $mail['messageId'] . "] md5[" . md5($mail['inReplyTo'] ?: ($mail['messageId']))
                . "] guid[" . bin2Hex($guid) . "], index[" . $threadIndex . "]"
            );
        }
        // build the mail message
        $body = new MimeMessage();
        $body->setParts($parts);
        $message    = new Message();
        $recipients = $config['recipients'] ?: $to;
        if ($config['use_bcc']) {
            $message->setTo($config['sender'], 'Unspecified Recipients');
            $message->addBcc($recipients);
        } else {
            $message->addTo($recipients);
        }
        $message->setSubject($subject);
        $message->setFrom($config['sender'], $mail['fromName']);
        if ($config['use_replyto']) {
            $message->addReplyTo($mail['fromAddress'] ?: $config['sender'], $mail['fromName']);
        } else {
            $message->addReplyTo('noreply@' . $configs['environment']['hostname'], 'No Reply');
        }
        $message->setBody($body);
        // setEncoding here only applies to message header metadata
        $message->setEncoding('UTF-8');
        $message->getHeaders()->addHeaders(
            array_filter(
                [
                    'Message-ID'            => $mail['messageId'],
                    'In-Reply-To'           => $mail['inReplyTo'],
                    'References'            => $mail['references'],
                    'Thread-Index'          => $threadIndex,
                    'Thread-Topic'          => mb_encode_mimeheader($subject, "UTF-8"),
                    'X-Swarm-Project'       => mb_encode_mimeheader(implode(",", $mail['projects']), "UTF-8"),
                    'X-Swarm-Host'          => $configs['environment']['hostname'],
                    'X-Swarm-Version'       => VERSION,
                    'X-Swarm-Review-Id'     => isset($review) && $review ? $review->getId() : null,
                    'X-Swarm-Review-Author' => mb_encode_mimeheader($reviewAuthor, "UTF-8"),
                    'X-Swarm-Action'        => $mailAction
                ]
            )
        );
        /*
         * The call to $message->setEncoding('UTF-8') causes all headers to be encoded in UTF-8, which can then
         * break rules for parsing mail headers. Selectively encoding headers which can only use ascii,
         * swarm key values etc., allows parsing to succeed, this may need further changes.
         * See https://jira.perforce.com:8443/browse/SW-5678
         */
        self::asciiEncodeHeaders(
            $message,
            [
                'Message-ID',
                'In-Reply-To',
                'Thread-Index',
                'X-Swarm-Version',
                'X-Swarm-Review-Id',
                'X-Swarm-Action',
                'X-Swarm-Host'
            ]
        );
        // set alternative multi-part if we have both html and text templates
        // so that the client knows to show one or the other, not both
        if ($mail['htmlTemplate'] && $mail['textTemplate']) {
            $message->getHeaders()->get('content-type')->setType('multipart/alternative');
        }

        return $message;
    }

    /**
     * Force ASCII encoding in the metadata on the the list of headers provided if they are set in the message headers
     * so that headers we know will not be UTF-8 are not encoded as such
     * @param Message $message
     * @param array $headers
     */
    protected static function asciiEncodeHeaders(Message &$message, array $headers)
    {
        $messageHeaders = $message->getHeaders();
        foreach ($headers as $header) {
            if ($messageHeaders->get($header)) {
                $messageHeaders->get($header)->setEncoding('ASCII');
            }
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public static function getActionRoles(
        $logger,
        $mailAction,
        $review,
        $participant,
        $expandedFromList,
        User $activityUser,
        $author,
        $type,
        ProjectModel $project = null,
        $change = null
    ) {
        // List of roles that relate this user to the activity being notified
        $roleOptions   = [];
        $participantID = $type === 'Group' ? GroupConfig::KEY_PREFIX.$participant->getId() : $participant->getId();
        $logger->trace(
            'Mail:getActionRoles: participantId [' . $participantID . ']. '
            . 'Expanded[' . (isset($expandedFromList[$participantID]) ? $expandedFromList[$participantID] : '') . ']'
        );

        // Firstly deal with things outside the scope of projects and actions
        // Is the email going to a follower of the user that carried out the action?
        if ($activityUser->getConfig()->isFollower($participantID)) {
            $roleOptions[] = Settings::IS_FOLLOWER; // Should probably introduce IS_USER_FOLLOWER as a new role
        }

        // Set is_self if the activity user and current user are the same.
        if ($activityUser->getId() === $participantID) {
            $roleOptions[] = Settings::IS_SELF;
        }

        // Is the recipient the author of the item which triggered the notification?
        if ($review && $review->isValidAuthor() && $review->getAuthorObject()->getId() == $participantID) {
            $roleOptions[] = MailAction::COMMENT_LIKED === $mailAction
                ? Settings::IS_COMMENTER
                : Settings::IS_AUTHOR;
        } elseif ($author === $participantID) {
            // As comment_liked only has role of is_commenter we don't need to assign author as well, as this could
            // enable emails when is_commenter is disabled.
            $roleOptions[] = MailAction::COMMENT_LIKED === $mailAction
                ? Settings::IS_COMMENTER
                : Settings::IS_AUTHOR;
        }

        // Unpack the project data
        $members    = [];
        $moderators = [];
        if ($project) {
            $members    = $project->getUsersAndSubgroups();
            $moderators = $project->getModeratorsWithGroups();
            $logger->trace(
                'Mail:getActionRoles: '.$project->getId().' Members [' . var_export($members, true)
                . '] Project Moderators [' . var_export($moderators, true)
                . ']'
            );
            // Is this user a follower of this project
            if ($project && $project->isFollowing($participantID)) {
                $roleOptions[] = Settings::IS_FOLLOWER;
            }
        }

        // Next deal with things that relate to the action being carried out
        switch ($mailAction) {
            // Comment liked action
            case MailAction::COMMENT_LIKED:
            case MailAction::DESCRIPTION_COMMENT_LIKED:
                break;
            // Comment related actions.
            case MailAction::COMMENT_ADDED:
            case MailAction::COMMENT_REPLY:
            case MailAction::COMMENT_EDITED:
            case MailAction::DESCRIPTION_COMMENT_ADDED:
            case MailAction::DESCRIPTION_COMMENT_EDITED:
                // Reviewer
                $reviewers = $review ? $review->getReviewers() : [];
                $logger->trace('Mail:getActionRoles: reviewers [' . implode(", ", $reviewers) . ']');
                // If it's a change and not a review we let the change author know about comments
                if ($change
                    && !$review
                    && $participantID === $change->getUser()
                    && !in_array(Settings::IS_SELF, $roleOptions)) {
                    // We have a change (no review) and the person being assessed is the author of the change and it
                    // is not themselves that made/edited the comment
                    $roleOptions[] = Settings::IS_AUTHOR;
                } elseif ($review && in_array($participantID, $reviewers)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                } elseif (isset($expandedFromList[$participantID])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList);
                    $logger->trace(
                        'Mail:getActionRoles: participantId [' . $participantID
                        . '], inherited from [' . $inheritedId . '] '
                        . ( Group::isGroupName($inheritedId)
                            ? "Group in reviewers list(".(in_array($inheritedId, $reviewers) ? "true" : "false").")"
                            : "not a group" )
                    );
                    if (Group::isGroupName($inheritedId) && Module::isGroupInArray($inheritedId, $reviewers)) {
                        // The original group was a reviewer, so I must be
                        $roleOptions[] = Settings::IS_REVIEWER;
                    }
                }
                break;
            // Review related actions
            case MailAction::REVIEW_APPROVED:
            case MailAction::REVIEW_ARCHIVED:
            case MailAction::REVIEW_REQUESTED:
            case MailAction::REVIEW_REJECTED:
            case MailAction::REVIEW_NEEDS_REVIEW:
            case MailAction::REVIEW_NEEDS_REVISION:
            case MailAction::REVIEW_UPDATED_FILES:
            case MailAction::REVIEW_VOTED_UP:
            case MailAction::REVIEW_VOTED_DOWN:
            case MailAction::REVIEW_CLEARED_VOTE:
            case MailAction::REVIEW_JOINED:
            case MailAction::REVIEW_LEFT:
            case MailAction::REVIEW_TESTS:
            case MailAction::REVIEW_TESTS_NO_AUTH:
            case MailAction::CHANGE_COMMITTED:
            case MailAction::REVIEW_OPENED_ISSUE:
            case MailAction::REVIEW_MAKE_REQUIRED_VOTE:
            case MailAction::REVIEW_MAKE_OPTIONAL_VOTE:
            case MailAction::REVIEW_EDITED_REVIEWERS:
                // Moderator of the project
                $logger->trace(
                    'Mail:getActionRoles: moderators users ['
                    . implode(", ", isset($moderators['Users'])?$moderators['Users']: []) . '], '
                    . ' groups [' . implode(", ", isset($moderators['Groups'])?$moderators['Groups']: []) . ']'
                );
                $logger->trace(
                    'Mail:getActionRoles: Moderator role checking ['.$participantID.'] is in Expanded list ['
                    . (isset($expandedFromList[$participantID]) ? 'true' : 'false') . '] | Are there moderator Groups ['
                    . (isset($moderators['Groups']) ? 'true' : 'false') . '] | is a Group ['
                    . (Group::isGroupName($participantID) ? 'true' : 'false') . ']'
                );
                if (in_array($participantID, isset($moderators['Users'])?$moderators['Users']: [])) {
                    $roleOptions[] = Settings::IS_MODERATOR;
                } elseif (isset($expandedFromList[$participantID]) && isset($moderators['Groups'])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $moderators['Groups']);
                    $logger->trace(
                        'Mail:getActionRoles: participantId [' . $participantID
                        . '], inherited from [' . $inheritedId . '] '
                        . ( Group::isGroupName($inheritedId)
                            ? "Group in moderators list("
                                . (in_array($inheritedId, $moderators['Groups']) ? "true" : "false") . ")"
                            : "not a group" )
                    );
                    if (Group::isGroupName($inheritedId)
                        && (Module::isGroupInArray($inheritedId, $moderators['Groups']))) {
                        // The original group was a moderator, so I must be
                        $roleOptions[] = Settings::IS_MODERATOR;
                    } elseif (Group::isGroupName($participantID)
                        && Module::isGroupInArray($participantID, $moderators['Groups'])) {
                        // If a group has been inherited from a project, check that the group is not in the
                        // moderators group list
                        $roleOptions[] = Settings::IS_MODERATOR;
                    }
                } elseif (Group::isGroupName($participantID)
                    && Module::isGroupInArray(
                        $participantID,
                        isset($moderators['Groups'])? $moderators['Groups'] : []
                    )
                ) {
                    $roleOptions[] = Settings::IS_MODERATOR;
                }
                // Reviewer
                $reviewers = $review ? $review->getReviewers() : [];
                if ($review && in_array($participantID, $reviewers)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                } elseif (isset($expandedFromList[$participantID])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $reviewers);
                    if (Group::isGroupName($inheritedId) && Module::isGroupInArray($inheritedId, $reviewers)) {
                        // The original group was a reviewer, so I must be
                        $roleOptions[] = Settings::IS_REVIEWER;
                    }
                }
                // Member of the project
                if (in_array($participant->getId(), isset($members['Users'])?$members['Users']: [])) {
                    // We don't want is member for tests, new tasks and vote change.
                    if (!in_array(
                        $mailAction,
                        [
                            MailAction::REVIEW_TESTS,
                            MailAction::REVIEW_TESTS_NO_AUTH,
                            MailAction::REVIEW_OPENED_ISSUE,
                            MailAction::REVIEW_MAKE_REQUIRED_VOTE,
                            MailAction::REVIEW_MAKE_OPTIONAL_VOTE
                        ]
                    )) {
                        $roleOptions[] = Settings::IS_MEMBER;
                    }
                } elseif (isset($expandedFromList[$participantID]) && isset($members['Groups'])) {
                    // This participant was expanded from a group (or a project)
                    $inheritedId = Module::getInheritedId($participantID, $expandedFromList, $members['Groups']);
                    if (Module::isGroupInArray($inheritedId, $members['Groups']) ||
                        strpos($inheritedId, ProjectModel::KEY_PREFIX) !== false) {
                        // The original group was a member, so I must be
                        $roleOptions[] = Settings::IS_MEMBER;
                    } elseif (Group::isGroupName($participantID)
                        && Module::isGroupInArray($participantID, $members['Groups'])) {
                        // If a group has been inherited from a project, check that the group is not in the
                        // members group list
                        $roleOptions[] = Settings::IS_MEMBER;
                    }
                } elseif (Group::isGroupName($participantID)
                    && Module::isGroupInArray(
                        $participantID,
                        isset($members['Groups'])?$members['Groups']: []
                    )
                ) {
                    $roleOptions[] = Settings::IS_MEMBER;
                }
                // If someone leaves or is removed from a review they don't have a role.
                // Due to this assign them the is_reviewer role as they where a reviewer
                // before being removed.
                if ($mailAction === MailAction::REVIEW_LEFT && empty($roleOptions)) {
                    $roleOptions[] = Settings::IS_REVIEWER;
                }
                break;
            default:
                // Other actions, as roles are used as a filter, no value means send anyway
                break;
        }
        return $roleOptions;
    }

    /**
     * Tests if the needle is in the array as a plain group name or
     * as a swarm-group- name.
     * @param string        $needle     the value to find
     * @param array         $haystack   the array to search
     * @return bool true if found
     */
    private static function isGroupInArray($needle, $haystack)
    {
        return in_array(Group::getGroupName($needle), $haystack) || in_array($needle, $haystack);
    }

    /**
     * Flatten an array into a printable string, for debug
     * @param $name    - the name of the property
     * @param $printMe - the value to print, which may be an array
     * @return string  - a bracketed string representation of the value
     */
    private static function arrayDebugAsString($name, $printMe)
    {
        if (is_array($printMe)) {
            $result = "[$name";
            foreach ($printMe as $key => $value) {
                $result .= Module::arrayDebugAsString($key.":", $value);
            }
            $result .= "]";
        } else {
            $result = "[$name$printMe]";
        }
        return $result;
    }

    /**
     * Combine the configured instanve name with the server id for multiserver setup
     * @param $mailConfig
     * @return mixed
     */
    public static function getInstanceName($mailConfig)
    {
        return $mailConfig['instance_name'] . (null === P4_SERVER_ID ? '' :('-'.P4_SERVER_ID));
    }

    /**
     * Analyse the to list for an email and expand it using the rules:
     *
     *  - projects, get the immediate members (users and groups) and treat these as standard groups/users
     *    with a role of IS_MEMBER
     *  - groups, if the group is _not_ using a mailing list expand all users with a role of reviewer unless the group
     *    was derived from a project
     *  - users, simple case just add the to the list
     *
     * @param $toUsers                     - the original toUser array
     * @param $p4Admin                     -  a p4 connection
     * @param array $groupsBlacklist       - list of blacklisted groups
     * @param array $groups                - somewhere to stash group objects
     * @param array $expansionMap          - map expanded users to parent
     * @param array $seen                  - short term memory to prevent recursion
     * @param $logger                      - the logger
     * @return array the list of direct recipients
     */
    public static function expandParticipants(
        $toUsers,
        $p4Admin,
        $groupsBlacklist,
        &$groups = [],
        &$expansionMap = [],
        &$seen = [],
        $logger = null
    ) {
        $participants = [];

        $expandedUserMap = array_map(
            function ($participant) use ($p4Admin, $groupsBlacklist, &$groups, &$expansionMap, &$seen, $logger) {
                $projectDAO = ServicesModelTrait::getProjectDao();
                $groupDAO   = ServicesModelTrait::getGroupDao();
                // Am I a project?
                if (ProjectModel::isProjectName($participant) && !in_array($participant, $seen)) {
                    // Seen this one now, don't forget
                    $seen[] = $participant;
                    // Get the immediate project members
                    $projectName = ProjectModel::getProjectName($participant);
                    try {
                        $projectUsersAndSubgroups = $projectDAO->fetch(
                            $projectName,
                            $p4Admin
                        )->getUsersAndSubgroups();
                        // Flatten the Users/Groups into a list of Mail/Module friendly id values
                        $projectMembers = array_merge(
                            $projectUsersAndSubgroups['Users'],
                            array_map(
                                function ($memberId) {
                                    // Members of a project could potentially be another project
                                    if (ProjectModel::isProjectName($memberId)) {
                                        return $memberId;
                                    } else {
                                        return GroupConfig::KEY_PREFIX . $memberId;
                                    }
                                },
                                $projectUsersAndSubgroups['Groups']
                            )
                        );
                        // Remember that these were project members
                        $expansionMap += array_merge($expansionMap, array_fill_keys($projectMembers, $participant));
                        // Now expand the immediate participants
                        return Module::expandParticipants(
                            $projectMembers,
                            $p4Admin,
                            $groupsBlacklist,
                            $groups,
                            $expansionMap,
                            $seen,
                            $logger
                        );
                    } catch (RecordNotFoundException $e) {
                        if ($logger) {
                            $logger->warn("Project: $projectName was not found, notifications will not be sent.");
                        }
                    }
                }
                $id            = Group::getGroupName($participant);
                $caseSensitive = $p4Admin->isCaseSensitive();

                // Skip the expansion for blacklisted groups
                if (Group::isGroupName($participant)
                    && !ConfigCheck::isExcluded($id, $groupsBlacklist, $caseSensitive)
                    && $groupDAO->exists($id)
                ) {
                    $group = $groupDAO->fetchById($id, $p4Admin);
                    // Remember the group object for later
                    $groups[$participant] = $group;
                    if (!$group->getConfig()->get('useMailingList')) {
                        $groupMembers    = $groupDAO->fetchUsersAndSubgroups($id);
                        $subGroupMembers = [];
                        // Seen this one now, don't forget
                        $seen[] = GroupConfig::KEY_PREFIX.$id;
                        foreach ($groupMembers['Groups'] as $subgroup) {
                            $member = $subgroup;
                            if (!ProjectModel::isProjectName($member)) {
                                $member = GroupConfig::KEY_PREFIX.$subgroup;
                            }
                            if (!in_array($member, $seen)) {
                                // Remember that these were group members
                                $expansionMap[$member] = $participant;
                                // Map the subgroups separately
                                $subGroupMembers[] = Module::expandParticipants(
                                    [$member],
                                    $p4Admin,
                                    $groupsBlacklist,
                                    $groups,
                                    $expansionMap,
                                    $seen
                                );
                            }
                        }
                        // Remember that these were group members
                        $expansionMap += array_fill_keys($groupMembers['Users'], $participant);
                        return array_merge($groupMembers['Users'], $subGroupMembers);
                    }
                }
                // Ensure to blacklist the group, itself, in case it has a mailing list
                $isBlacklisted = ConfigCheck::isExcluded($id, $groupsBlacklist, $caseSensitive);
                return $isBlacklisted ? null : $participant;
            },
            $toUsers
        );
        // Now flatten any subgroup expansions into actual id values
        array_walk_recursive(
            $expandedUserMap,
            function ($v, $k) use (&$participants) {
                $participants[] = $v;
            }
        );
        return array_unique(array_values(array_filter($participants)));
    }

    /**
     * Traverse the inherited id map to follow a child back up to its ulitmate parent, which will be the id
     * that actually performed a role in an activity.
     *
     * @param       $participantId
     * @param       $expandedFromList
     * @param array $actors This will be Reviewers Moderators or Members
     * @return mixed
     */
    public static function getInheritedId($participantId, $expandedFromList, $actors = [])
    {
        $inheritedId = $participantId;
        while (isset($expandedFromList[$inheritedId]) && !in_array($inheritedId, $actors)) {
            $actors[]    = $inheritedId;
            $inheritedId = $expandedFromList[$inheritedId];
        }
        return $inheritedId;
    }

    /**
     * Apply the preference matrix to an email address returning a, possible empty, array depending upon
     * whether the email address is an appropriate candidate for this email.
     *
     * @param        $participant
     * @param        $toUser
     * @param        $services
     * @param        $validator
     * @param        $event
     * @param        $email
     * @param        $users
     * @param        $expandedFromList
     * @param        $notifications
     * @param        $isMailEnabled
     * @param        $review
     * @param string $type
     * @param mixed  $change            the change associated with the action, will be null if this is a review
     * @return array
     * @throws Exception
     */
    private static function getFilteredToList(
        $participant,
        $toUser,
        $services,
        $validator,
        $event,
        $email,
        $users,
        $expandedFromList,
        $notifications,
        $isMailEnabled,
        $review,
        $type = 'User',
        $change = null
    ) {
        $to         = [];
        $isGroup    = Group::isGroupName($toUser);
        $mail       = $event->getParam('mail');
        $activity   = $event->getParam('activity');
        $logger     = $services->get('logger');
        $configs    = $services->get('config') + ['mail' => []];
        $mailAction = $activity ? $activity->get('action') : Settings::UNDETERMINED;
        $projectDAO = $services->get(IModelDAO::PROJECT_DAO);

        // Activity will not be set for batched comments, so consider the from to be the initiator/author
        $activityUserId = $activity && $activity->get('user')
            ? $activity->get('user')
            : (isset($mail['fromUser'])
                ? $mail['fromUser']
                : $mail['author']);

        // Protect against not being able to find a user
        $activityUser = isset($users[$activityUserId])
            ? $users[$activityUserId] : new User();
        $toEmail      = call_user_func_array($email, [$isGroup, $participant]);
        if ($validator->isValid($toEmail)) {
            // Email address is in an acceptable format, check notification settings
            $logger->debug("Mail: $toEmail is formatted correctly, checking settings");
            $wantsEmail  = false;
            $projectList = isset($mail['projects']) ? $mail['projects'] : [];
            $logger->debug("Mail: Projects attribute set to " . implode(', ', $projectList));
            $settings           = new Settings($configs, $logger);
            $participantOptions = call_user_func_array($notifications, [$isGroup, $participant]);
            $logger->debug(
                "Mail: $type preferences are set to "
                . Module::arrayDebugAsString("", $participantOptions)
            );
            $settingsOptions = $type === 'User' ? Settings::USER_OPTION : Settings::GROUP_OPTION;

            if (count($projectList) > 0) {
                // There are projects, so we need to take membership into account
                $projects = $projectDAO->fetchAll(
                    [ProjectModel::FETCH_BY_IDS => $projectList],
                    $services->get('p4_admin')
                );
                // Iterate through the projects, checking preferences for each one
                foreach ($projects as $project) {
                    $logger->debug(
                        "Mail: Checking whether $toUser(" . $participant->getId() . ') wants an email for '
                        . 'project[' . $project->getName() . '], '
                        . ($review ? ('review[' . $review->getId() . '], ') : '')
                        . 'action[' . $mailAction . '/' . $activityUser->getId() . '], '
                        . 'author['. $mail['author'] . ']'
                    );
                    $notificationOptions = [
                        $settingsOptions => $participantOptions,
                        Settings::ROLES_OPTION   => Module::getActionRoles(
                            $logger,
                            $mailAction,
                            $review,
                            $participant,
                            $expandedFromList,
                            $activityUser,
                            $mail['author'],
                            $type,
                            $project,
                            $change
                        ),
                        Settings::PROJECT_OPTION => $project
                    ];
                    $logger->debug(
                        "Mail: Options are $type"
                        . Module::arrayDebugAsString("", $participantOptions) . ", "
                        . "Roles[" . implode(", ", $notificationOptions[Settings::ROLES_OPTION]) . "], "
                        . "Project[" . $notificationOptions[Settings::PROJECT_OPTION]->getName() . "]"
                    );

                    if ($wantsEmail = true ===
                        call_user_func_array(
                            $isMailEnabled,
                            [
                                $isGroup,
                                $settings,
                                $mailAction,
                                $notificationOptions
                            ]
                        )
                    ) {
                        // Once we know that an email is being sent, processing can move on
                        $logger->debug(
                            "Mail: Found an enabled combination for $toUser, "
                            . $mailAction . " and "
                            . $project->getName() .", moving on"
                        );
                        break;
                    }
                }
            } else {
                // No projects, only check user settings
                $logger->debug(
                    "Mail: Message has no project scope, checking whether "
                    . "$toUser (" . $participant->getId() . ') wants an email for '
                    . ($review ? ('review[' . $review->getId() . '], ') : '')
                    . 'action[' . $mailAction . '/' . $activityUser->getId() . '], '
                    . 'author['. $mail['author'] . ']'
                );
                $notificationOptions = [
                    $settingsOptions => $participantOptions,
                    Settings::ROLES_OPTION   => Module::getActionRoles(
                        $logger,
                        $mailAction,
                        $event->getParam('review'),
                        $participant,
                        $expandedFromList,
                        $activityUser,
                        $mail['author'],
                        $type
                    )
                ];

                $logger->debug(
                    "Mail: Options are User["
                    . Module::arrayDebugAsString("", $participantOptions) . "], "
                    . "Roles[" . implode(", ", $notificationOptions[Settings::ROLES_OPTION]) . "]"
                );

                $wantsEmail = true ===
                    call_user_func_array(
                        $isMailEnabled,
                        [
                            $isGroup,
                            $settings,
                            $mailAction,
                            $notificationOptions
                        ]
                    );
            }

            if ($wantsEmail) {
                // Add the participant if when combination was true
                $logger->debug("Mail: $toUser does want an email for " . $mailAction . ".");
                $to[] = $toEmail;
            } else {
                $logger->debug("Mail: $toUser does not want this email.");
            }
        } else {
            $logger->warn(
                "Mail: Email cannot be sent to $toEmail : " .
                implode(".", $validator->getMessages())
            );
        }

        return $to;
    }
}
