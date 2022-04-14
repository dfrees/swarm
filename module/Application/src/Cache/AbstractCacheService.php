<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Cache;

use Application\Exception\NotImplementedException;

use Psr\SimpleCache\CacheInterface;

/**
 * The AbstractCacheService is to give a template for all cache services.
 *
 * @package Application\Cache
 */
abstract class AbstractCacheService implements CacheInterface
{
    // Alias for caching
    const CACHE_SERVICE = 'cache';

    /**
     * @inheritdoc
     * @param      $key
     * @param null $default
     * @throws NotImplementedException
     */
    public function get($key, $default = null)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param      $key
     * @param      $value
     * @param null $ttl
     * @throws NotImplementedException
     */
    public function set($key, $value, $ttl = null)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param $key
     * @param $options
     * @throws NotImplementedException
     */
    public function delete($key, array $options = [])
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @throws NotImplementedException
     */
    public function clear()
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param      $keys
     * @param null $default
     * @throws NotImplementedException
     */
    public function getMultiple($keys, $default = null)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param      $values
     * @param null $ttl
     * @throws NotImplementedException
     */
    public function setMultiple($values, $ttl = null)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param $keys
     * @throws NotImplementedException
     */
    public function deleteMultiple($keys)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }

    /**
     * @inheritdoc
     * @param $key
     * @throws NotImplementedException
     */
    public function has($key)
    {
        throw new NotImplementedException(NotImplementedException::NOT_IMPLEMENTED . __FUNCTION__);
    }
}
