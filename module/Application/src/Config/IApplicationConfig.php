<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

/**
 * Defines some settings for application config. Strictly speaking these are Zend
 * values but we define them to have a single place to update in Swarm.
 * @package Application\Config
 */
interface IApplicationConfig
{
    const SERVICE                 = 'ApplicationConfig';
    const CONFIG_PREFIX           = 'module-config-cache';
    const CLASSMAP_PREFIX         = 'module-classmap-cache';
    const MODULE_LISTENER_OPTIONS = 'module_listener_options';
    const CACHE_DIR               = 'cache_dir';
    const MODULE_MAP_CACHE_KEY    = 'module_map_cache_key';
    const CONFIG_CACHE_KEY        = 'config_cache_key';
}
