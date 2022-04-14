<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Application\Config\ConfigManager;

/**
 * Interface ICacheController defines options for the API controller for caching.
 * @package Api\Controller
 */
interface ICacheController
{
    // Constant to define the 'config' cache in the API path.
    const CONFIG = 'config';
    // Suffix for service alias lookups
    const ALIAS_SUFFIX = '-cache';
    // Convenience for use in module.config.php files
    const CONFIG_CACHE = self::CONFIG . self::ALIAS_SUFFIX;
    // Constant to define the path for redis API calls
    const REDIS_CACHE = ConfigManager::REDIS . self::ALIAS_SUFFIX;
}
