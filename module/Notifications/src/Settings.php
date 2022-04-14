<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Notifications;

use Application\Config\Setting;
use Projects\Model\Project as ProjectModel;
use Mail\MailAction;
use P4\Log\Logger;

/**
 * Class to manage notification settings for Swarm. Global configuration describes the conditions in the form:
 * 'notifications' => array(
 *     'review_new' => array(
 *         'is_author' => ForcedEnabled,
 *         'is_member' => Enabled
 *     )
 *     etc...
 * )
 * @package Notifications
 */
class Settings
{
    // Define the locator for the start of the global settings.
    const NOTIFICATIONS = 'notifications';

    // Notification types
    const REVIEW_CHANGELIST_COMMIT = 'review_changelist_commit';
    const REVIEW_COMMENT_LIKED     = 'review_comment_liked';
    const REVIEW_COMMENT_NEW       = 'review_comment_new';
    const REVIEW_COMMENT_UPDATE    = 'review_comment_update';
    const REVIEW_OPENED_ISSUE      = 'review_opened_issue';
    const REVIEW_FILES             = 'review_files';
    const REVIEW_NEW               = 'review_new';
    const REVIEW_STATE             = 'review_state';
    const REVIEW_TESTS             = 'review_tests';
    const REVIEW_VOTE              = 'review_vote';
    const REVIEW_REQUIRED_VOTE     = 'review_required_vote';
    const REVIEW_OPTIONAL_VOTE     = 'review_optional_vote';
    const REVIEW_JOIN_LEAVE        = 'review_join_leave';
    const CHANGE_COMMIT            = 'change_commit'; // Legacy to cater for user spec review path
    const UNDETERMINED             = 'undetermined';  // The type is not know - always treat as enabled

    // Notification roles
    const IS_AUTHOR    = 'is_author';
    const IS_COMMENTER = 'is_commenter';
    const IS_MEMBER    = 'is_member';
    const IS_MODERATOR = 'is_moderator';
    const IS_REVIEWER  = 'is_reviewer';
    const IS_SELF      = 'is_self';
    const IS_FOLLOWER  = 'is_follower';

    // Legacy configuration from Changes brought into this module that we still support
    const HONOUR_P4_REVIEWS     = 'honor_p4_reviews';
    const OPT_IN_JOB_PATH       = 'opt_in_job_path';
    const OPT_IN_REVIEW_PATH    = 'opt_in_review_path';
    const DISABLE_CHANGE_EMAILS = 'disable_change_emails';

    // Keys for options settings
    const ROLES_OPTION   = 'roles';
    const USER_OPTION    = 'userOptions';
    const PROJECT_OPTION = 'project';
    const BRANCH_OPTION  = 'branch';
    const GROUP_OPTION   = 'group';

    // Prefix for log messages
    const LOG_PREFIX = 'Settings: ';

    // Global config
    protected $config = null;
    protected $logger = null;

    /**
     * Settings constructor.
     *
     * @param      $config the configuration
     * @param null $logger
     * @throws \P4\Exception
     */
    public function __construct($config, $logger = null)
    {
        $this->setConfig($config);
        $this->logger = $logger ? $logger : Logger::getLogger();
    }

    /**
     * Determines if the state is present in the notification settings
     * @param $notificationType the type, for example REVIEW_NEW
     * @param $notificationRoles array of roles, for example array(IS_AUTHOR)
     * @param $state the state to find, for example FORCED_ENABLED. If null the presence of the settings
     * for any state will be evaluated.
     * @param $notificationConfig the config to search (will be either global or user which should
     * be in the same format)
     * @return bool
     */
    private function hasState($notificationType, $notificationRoles, $notificationConfig, $state = null)
    {
        $hasState = false;
        $roles    = is_array($notificationRoles) ? $notificationRoles : [];
        if (isset($notificationConfig[static::NOTIFICATIONS][$notificationType])) {
            $configForType = $notificationConfig[static::NOTIFICATIONS][$notificationType];
            $this->log(
                'Testing state "' . $state .
                '" against config ' . var_export($configForType, true)
            );
            $this->trace('Roles in hasState are ' . var_export($notificationRoles, true));
            foreach ($roles as $notificationRole) {
                $this->log('Testing hasState for role ' . $notificationRole);
                if (isset($configForType[$notificationRole])) {
                    if ($state === null) {
                        $this->trace('hasState true as state not provided');
                        $hasState = true;
                        break;
                    } else {
                        if ($configForType[$notificationRole] == $state) {
                            $this->trace('hasState true as match found');
                            $hasState = true;
                            break;
                        }
                    }
                }
            }
        }
        $this->log('State found is ' . ($hasState ? 'true' : 'false'));
        return $hasState;
    }

    /**
     * Gets the value for notifying for changes set up in the user spec.
     * If DISABLE_CHANGE_EMAILS is false then we always return disabled.
     * If HONOUR_P4_REVIEWS is true then review path must not be null in order to be enabled.
     *
     * @param ProjectModel $project if project has a value the global configuration is not checked and we
     *                              look at the 'change_email_project_users' flag on the project to determine if
     *                              emails are enabled (only if we have roles). If there are no roles the project
     *                              setting is ignored
     * @param $notificationRoles array of roles, for example array(IS_AUTHOR)
     * @return string
     */
    private function getChangeCommitState(ProjectModel $project = null, $notificationRoles)
    {
        $enabled = true;
        if ($project && $notificationRoles !== null && !empty($notificationRoles)) {
            $changeEmailFlag = $project->getEmailFlag('change_email_project_users');
            // Legacy projects may not have the flag so null is considered enabled, otherwise
            // the value stored is '1' or '0' where '1' is enabled
            $enabled = $changeEmailFlag === null || $changeEmailFlag === '1';
            $this->log('Project is set, change_email_project_users found to be '. ($enabled ? 'true' : 'false'));
        } else {
            $disableChange = isset($this->config[static::NOTIFICATIONS][static::DISABLE_CHANGE_EMAILS])
                ? $this->config[static::NOTIFICATIONS][static::DISABLE_CHANGE_EMAILS] : false;

            $reviewPath = isset($this->config[static::NOTIFICATIONS][static::OPT_IN_REVIEW_PATH])
                ? $this->config[static::NOTIFICATIONS][static::OPT_IN_REVIEW_PATH] : null;
            $this->log(
                static::DISABLE_CHANGE_EMAILS . ' is ' . ($disableChange ? 'true' : 'false')
            );
            if ($disableChange) {
                $enabled = false;
            } else {
                $honourReviews = isset($this->config[static::NOTIFICATIONS][static::HONOUR_P4_REVIEWS])
                    ? $this->config[static::NOTIFICATIONS][static::HONOUR_P4_REVIEWS] : false;
                $this->log(
                    static::HONOUR_P4_REVIEWS . ' is ' . ($honourReviews ? 'true' : 'false')
                );
                $this->log(
                    static::OPT_IN_REVIEW_PATH . ' is ' . ($reviewPath ? $reviewPath : 'empty')
                );
                // If we do not honour we are enabled, if we do honour review path must have a value
                $enabled = !$honourReviews || ($honourReviews && $reviewPath);
            }
        }
        $this->log('Change commit state is ' . ($enabled ? Setting::ENABLED : Setting::DISABLED));
        return $enabled ? Setting::ENABLED : Setting::DISABLED;
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking user settings.
     * @param $notificationType the type, for example REVIEW_NEW
     * @param $options array of options. Can contain ROLES_OPTION, USER_OPTION, PROJECT_OPTION, BRANCH_OPTION
     * @return bool
     */
    public function isEnabledForUser($notificationType, $options)
    {
        return $this->isEnabledForParticipant($notificationType, $options);
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking user settings.
     * @param $mailAction the mail action (or array of mail actions, defined in MailAction).
     * Each will be converted to a notification type. In the case of an array an 'or' is performed
     * @param $options array of options. Can contain ROLES_OPTION, USER_OPTION, PROJECT_OPTION, BRANCH_OPTION
     * @return bool
     */
    public function isMailEnabledForUser($mailAction, $options)
    {
        return $this->isMailEnabledForParticipant($mailAction, $options);
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking group settings.
     * @param $notificationType the type, for example REVIEW_NEW
     * @param $options array of options. Can contain ROLES_OPTION, GROUP_OPTION, PROJECT_OPTION, BRANCH_OPTION
     * @return bool
     */
    public function isEnabledForGroup($notificationType, $options)
    {
        return $this->isEnabledForParticipant($notificationType, $options);
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking group settings.
     * @param $mailAction the mail action (or array of mail actions, defined in MailAction).
     * Each will be converted to a notification type. In the case of an array an 'or' is performed
     * @param $options array of options. Can contain ROLES_OPTION, GROUP_OPTION, PROJECT_OPTION, BRANCH_OPTION
     * @return bool
     */
    public function isMailEnabledForGroup($mailAction, $options)
    {
        return $this->isMailEnabledForParticipant($mailAction, $options);
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking user/group settings.
     * @param $notificationType the type, for example REVIEW_NEW
     * @param $options array of options. Can contain ROLES_OPTION, USER_OPTION, PROJECT_OPTION, BRANCH_OPTION,
     * GROUP_OPTION
     * @return bool
     */
    private function isEnabledForParticipant($notificationType, $options)
    {
        $notificationRoles  = isset($options[static::ROLES_OPTION])       ? $options[static::ROLES_OPTION]       : null;
        $project            = isset($options[static::PROJECT_OPTION])     ? $options[static::PROJECT_OPTION]     : null;
        $branch             = isset($options[static::BRANCH_OPTION])      ? $options[static::BRANCH_OPTION]      : null;
        $participantOptions = null;

        if (isset($options[static::USER_OPTION])) {
            $participantOptions = [static::NOTIFICATIONS => $options[ static::USER_OPTION]];
        } elseif (isset($options[static::GROUP_OPTION])) {
            $participantOptions = [static::NOTIFICATIONS => $options[ static::GROUP_OPTION]];
        }

        return $this->getEnabledForParticipant(
            $notificationType,
            $notificationRoles,
            $project,
            $branch,
            $participantOptions
        );
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking user/group settings.
     * @param $mailAction the mail action (or array of mail actions, defined in MailAction).
     * Each will be converted to a notification type. In the case of an array an 'or' is performed
     * @param $options array of options. Can contain ROLES_OPTION, GROUP_OPTION, PROJECT_OPTION, BRANCH_OPTION,
     * GROUP_OPTION
     * @return bool
     */
    private function isMailEnabledForParticipant($mailAction, $options)
    {
        $enabled = false;

        if (is_array($mailAction)) {
            // Do an 'or' for each action breaking on the first positive
            foreach ($mailAction as $action) {
                $enabled = $this->isEnabledForParticipant(Settings::mailActionToNotificationType($action), $options);
                if ($enabled) {
                    break;
                }
            }
        } else {
            $enabled = $this->isEnabledForParticipant(Settings::mailActionToNotificationType($mailAction), $options);
        }
        return $enabled;
    }

    /**
     * Converts a mail action a settings notification type
     * @param $mailAction the mail action
     * @return string the type, UNDETERMINED if not found
     */
    public static function mailActionToNotificationType($mailAction)
    {
        $notificationType = static::UNDETERMINED;
        switch ($mailAction) {
            case MailAction::COMMENT_EDITED:
            case MailAction::DESCRIPTION_COMMENT_EDITED:
                $notificationType = static::REVIEW_COMMENT_UPDATE;
                break;
            case MailAction::COMMENT_REPLY:
            case MailAction::COMMENT_ADDED:
            case MailAction::DESCRIPTION_COMMENT_ADDED:
                $notificationType = static::REVIEW_COMMENT_NEW;
                break;
            case MailAction::COMMENT_LIKED:
            case MailAction::DESCRIPTION_COMMENT_LIKED:
                $notificationType = static::REVIEW_COMMENT_LIKED;
                break;
            case MailAction::REVIEW_REQUESTED:
                $notificationType = static::REVIEW_NEW;
                break;
            case MailAction::REVIEW_REJECTED:
            case MailAction::REVIEW_NEEDS_REVIEW:
            case MailAction::REVIEW_NEEDS_REVISION:
            case MailAction::REVIEW_APPROVED:
            case MailAction::REVIEW_ARCHIVED:
                $notificationType = static::REVIEW_STATE;
                break;
            case MailAction::REVIEW_UPDATED_FILES:
                $notificationType = static::REVIEW_FILES;
                break;
            case MailAction::REVIEW_VOTED_UP:
            case MailAction::REVIEW_VOTED_DOWN:
            case MailAction::REVIEW_CLEARED_VOTE:
                $notificationType = static::REVIEW_VOTE;
                break;
            case MailAction::REVIEW_OPENED_ISSUE:
                $notificationType = static::REVIEW_OPENED_ISSUE;
                break;
            case MailAction::REVIEW_MAKE_REQUIRED_VOTE:
                $notificationType = static::REVIEW_REQUIRED_VOTE;
                break;
            case MailAction::REVIEW_MAKE_OPTIONAL_VOTE:
                $notificationType = static::REVIEW_OPTIONAL_VOTE;
                break;
            case MailAction::REVIEW_LEFT:
            case MailAction::REVIEW_JOINED:
            case MailAction::REVIEW_EDITED_REVIEWERS:
                $notificationType = static::REVIEW_JOIN_LEAVE;
                break;
            case MailAction::CHANGE_COMMITTED:
                $notificationType = static::CHANGE_COMMIT;
                break;
            case MailAction::REVIEW_TESTS:
            case MailAction::REVIEW_TESTS_NO_AUTH:
                $notificationType = static::REVIEW_TESTS;
                break;
        }
        if ($notificationType === static::UNDETERMINED) {
            Logger::log(6, "Mail: Undetermined action for: |" . $mailAction . "|");
        }
        return $notificationType;
    }

    /**
     * Logs the message with a module prefix at the debug level.
     * @param $message
     */
    private function log($message)
    {
        $this->logger->debug(static::LOG_PREFIX . $message);
    }

    /**
     * Logs the message with a module prefix at the trace level.
     * @param $message
     */
    private function trace($message)
    {
        $this->logger->trace(static::LOG_PREFIX . $message);
    }

    /**
     * Checks global and user based settings to determine if mail should be sent according to type and roles.
     * @param $notificationType the type, for example REVIEW_NEW
     * @param $notificationRoles array of roles, for example array(IS_AUTHOR)
     * @param null $userOptions the user options for user specific settings
     * @param $defaultState a default to return if matches are not found.
     * @return string
     */
    private function getRoleBasedState(
        $notificationType,
        $notificationRoles,
        $userOptions,
        $defaultState
    ) {
        $state = $defaultState;
        if ($this->hasState($notificationType, $notificationRoles, $this->config, Setting::FORCED_ENABLED)) {
            $state = Setting::FORCED_ENABLED;
        } elseif ($this->hasState($notificationType, $notificationRoles, $this->config, Setting::ENABLED)) {
            $state = Setting::ENABLED;
            // Check to see if user has overridden
            if ($this->hasState($notificationType, $notificationRoles, $userOptions) &&
                !$this->hasState($notificationType, $notificationRoles, $userOptions, Setting::ENABLED)) {
                // State is present in user options but none are disabled
                $state = Setting::DISABLED;
            }
        } elseif ($this->hasState(
            $notificationType,
            $notificationRoles,
            $this->config,
            Setting::FORCED_DISABLED
        )) {
            $state = Setting::FORCED_DISABLED;
        } elseif ($this->hasState($notificationType, $notificationRoles, $this->config, Setting::DISABLED)) {
            $state = Setting::DISABLED;
            // Check to see if user has overridden with enabled
            if ($this->hasState($notificationType, $notificationRoles, $userOptions) &&
                $this->hasState($notificationType, $notificationRoles, $userOptions, Setting::ENABLED)) {
                // State is present in user options and we found an enabled
                $state = Setting::ENABLED;
            }
        }
        return $state;
    }

    /**
     * Determines if notifications should be sent by looking at the global configuration and if that is
     * not forced checking participant options that are provided.
     *
     * @param                   $notificationType   the type, for example REVIEW_NEW
     * @param                   $notificationRoles  array of roles, for example array(IS_AUTHOR)
     * @param ProjectModel|null $project            the project for project specific settings
     * @param null              $branch             the branch for branch specific settings
     * @param null              $participantOptions the participant options for user specific settings
     * @return bool
     */
    private function getEnabledForParticipant(
        $notificationType,
        $notificationRoles = null,
        ProjectModel $project = null,
        $branch = null,
        $participantOptions = null
    ) {
        if ($notificationType    !== static::UNDETERMINED
            && $notificationType !== static::CHANGE_COMMIT
            && empty($notificationRoles)
            && $project) {
            $this->log("User has no role in this project.");
            return false;
        }
        $enabled = true;
        $state   = Setting::ENABLED;
        $this->log('Notification type of ' . $notificationType);
        // Roles may have conflicting settings but the order for who wins is
        // FORCED_ENABLED, ENABLED, FORCED_DISABLED, DISABLED
        // CHANGE_COMMIT is a special case - it is driven by the user spec but can be overridden
        // if the correct roles are provided
        switch ($notificationType) {
            case static::CHANGE_COMMIT:
                $state = $this->getChangeCommitState($project, $notificationRoles);
                $state = $this->getRoleBasedState(
                    Settings::REVIEW_CHANGELIST_COMMIT,
                    $notificationRoles,
                    $participantOptions,
                    $state
                );
                break;
            case static::UNDETERMINED:
                // Doing nothing here will result in enabled of true always
                break;
            default:
                $state = $this->getRoleBasedState(
                    $notificationType,
                    $notificationRoles,
                    $participantOptions,
                    Setting::ENABLED
                );
                break;
        }
        $this->log('State of "' . $state);
        switch ($state) {
            case Setting::ENABLED:
            case Setting::FORCED_ENABLED:
                $enabled = true;
                break;
            case Setting::DISABLED:
            case Setting::FORCED_DISABLED:
                // Other specific settings are irrelevant
                $enabled = false;
                break;
            default:
                // Should never really get here but if we do default to noisy
                $enabled = true;
                break;
        }
        return $enabled;
    }

    /**
     * Sets the config
     * @param $config
     * @return $this
     */
    public function setConfig($config)
    {
        $this->config = $config;
        return $this;
    }
}
