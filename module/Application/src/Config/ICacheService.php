<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

use Application\Factory\InvokableService;
use Psr\SimpleCache\CacheInterface;

/**
 * Interface to describe the responsibility of a service to delete configuration cache files
 * @package Application\Config
 */
interface ICacheService extends InvokableService, IApplicationConfig, CacheInterface
{
    /**
     * Gets the path to the cached class map file
     * @return string the path to the cached class map file
     */
    public function getClassmapPath() : string;

    /**
     * Gets the path to the cached config file
     * @return string the path to the cached class map file
     */
    public function getConfigPath() : string;
}
