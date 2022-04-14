<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Config\Setting;
use Notifications\Settings;

// Default settings for notifications
return [
    Settings::NOTIFICATIONS => [
        Settings::REVIEW_NEW => [
            Settings::IS_AUTHOR => Setting::ENABLED,
            Settings::IS_MEMBER => Setting::ENABLED
        ],
        Settings::REVIEW_FILES => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_VOTE => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_REQUIRED_VOTE => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_OPTIONAL_VOTE => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_STATE => [
            Settings::IS_SELF      => Setting::DISABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_TESTS => [
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_CHANGELIST_COMMIT => [
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MEMBER    => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_COMMENT_NEW => [
            Settings::IS_AUTHOR   => Setting::ENABLED,
            Settings::IS_REVIEWER => Setting::ENABLED
        ],
        Settings::REVIEW_COMMENT_UPDATE => [
            Settings::IS_AUTHOR   => Setting::ENABLED,
            Settings::IS_REVIEWER => Setting::ENABLED
        ],
        Settings::REVIEW_COMMENT_LIKED => [
            Settings::IS_COMMENTER => Setting::ENABLED
        ],
        Settings::REVIEW_OPENED_ISSUE => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::REVIEW_JOIN_LEAVE => [
            Settings::IS_SELF      => Setting::ENABLED,
            Settings::IS_AUTHOR    => Setting::ENABLED,
            Settings::IS_REVIEWER  => Setting::ENABLED,
            Settings::IS_MODERATOR => Setting::ENABLED
        ],
        Settings::HONOUR_P4_REVIEWS     => false,
        Settings::OPT_IN_REVIEW_PATH    => null,
        Settings::OPT_IN_JOB_PATH       => null,
        Settings::DISABLE_CHANGE_EMAILS => false
    ]
];
