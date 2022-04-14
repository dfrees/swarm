<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Config\ConfigManager;

return [
    ConfigManager::JIRA => [
        ConfigManager::API_HOST             => '',
        ConfigManager::HOST                 => '',
        ConfigManager::USER                 => '',
        ConfigManager::PASSWORD             => '',
        ConfigManager::JOB_FIELD            => '',
        ConfigManager::LINK_TO_JOBS         => false,
        ConfigManager::DELAY_JOB_LINKS      => 60,
        ConfigManager::RELATIONSHIP         => 'links to',
        ConfigManager::MAX_JOB_FIXES        => -1, // Maximum linkages to update when a job changes.
                                                   // -1 indicates no limit,
                                                   // 0 means no old links are updated,
                                                   // > 0 will limit updates to a maximum of that number
    ]
];
