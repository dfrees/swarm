<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Settings;

use Users\Model\Config;
use Users\Model\User;
use Application\Config\ConfigManager;

/**
 * Class ReviewPreferences defines values for user settings.
 * @package Users\Settings
 */
class ReviewPreferences
{
    const REVIEW_PREFERENCES      = 'review_preferences';
    const SHOW_COMMENTS_IN_FILES  = 'show_comments_in_files';
    const VIEW_DIFFS_SIDE_BY_SIDE = 'view_diffs_side_by_side';
    const SHOW_SPACE_AND_NEW_LINE = 'show_space_and_new_line_characters';
    const IGNORE_WHITESPACE       = 'ignore_whitespace';

    const SHOW_COMMENTS_IN_FILES_TEXT  = 'Show comments in files';
    const VIEW_DIFFS_SIDE_BY_SIDE_TEXT = 'View diffs side-by-side';
    const SHOW_SPACE_AND_NEW_LINE_TEXT = 'Show space and newline characters';
    const IGNORE_WHITESPACE_TEXT       = 'Ignore whitespace when calculating differences';

    /**
     * Gets the review preferences for a user defaulting to global values if the user is
     * not set.
     * @param $globalConfig array the global config
     * @param $user User the user
     * @return array
     */
    public static function getReviewPreferences($globalConfig, $user)
    {
        $preferences = $globalConfig['users'][ConfigManager::SETTINGS];
        if ($user && $user->getId()) {
            // We can assume any user with an id has config
            $userSettings = $user->getConfig()->getUserSettings();
            if (isset($userSettings[ConfigManager::SETTINGS][self::REVIEW_PREFERENCES])) {
                $preferences = $userSettings[ConfigManager::SETTINGS];
            }
        }
        return $preferences[self::REVIEW_PREFERENCES];
    }
}
