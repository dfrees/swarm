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
 * Class TimePreferences defines values for user settings.
 * @package Users\Settings
 */
class TimePreferences
{
    const TIME_PREFERENCES = 'time_preferences';
    const DISPLAY          = 'display';
    const TIMEAGO          = 'Timeago';
    const TIMESTAMP        = 'Timestamp';


    const DISPLAY_TIME_IN_TIMESTAMP_OR_TIMEAGO = 'Display the time in';

    /**
     * Gets the review preferences for a user defaulting to global values if the user is
     * not set.
     * @param $globalConfig array the global config
     * @param $user User the user
     * @return array
     */
    public static function getTimePreferences($globalConfig, $user)
    {
        $preferences = $globalConfig['users'][ConfigManager::SETTINGS];
        if ($user && $user->getId()) {
            // We can assume any user with an id has config
            $userSettings = $user->getConfig()->getUserSettings();
            if (isset($userSettings[ConfigManager::SETTINGS][self::TIME_PREFERENCES])) {
                $preferences = $userSettings[ConfigManager::SETTINGS];
            }
        }
        return $preferences[self::TIME_PREFERENCES];
    }
}
