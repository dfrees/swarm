<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Cache;

use Application\Config\ConfigException;
use Exception;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Interface ISimpleCacheDecorator describes the responsibilities of the Swarm implementation of
 * Zends SimpleCacheDecorator.
 * @package Application\Cache
 */
interface ISimpleCacheDecorator extends SimpleCacheInterface
{
    /**
     * Calls setMultiple with batch size as specified in configuration, defaulting to not validating keys
     * @param array|iterable    $values         values to set
     * @param int|null          $ttl            ttl
     * @return bool
     * @throws InvalidArgumentException
     * @throws ConfigException
     * @throws Exception
     */
    public function populateMultiple($values, $ttl = null);
}
