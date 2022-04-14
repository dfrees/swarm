<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Record\Key;

use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator as P4Iterator;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Model\Connected\Iterator;
use Record\Exception\NotFoundException;

/**
 * Abstract class for key records that have simple caching. Sub classes should
 * define a const value CACHE_NAME
 * @package Record\Key
 */
abstract class CachedModel extends AbstractKey
{
    const FETCH_NO_CACHE = 'noCache';

    /**
     * Finds an item by its id. Uses the cache if it is available.
     * @param int|string $id the is to find
     * @param Connection $p4 the connection to use
     * @return \P4\Model\Connected\Model
     * @throws NotFoundException
     */
    public static function fetchById($id, Connection $p4 = null)
    {
        $p4 = $p4 ?: static::getDefaultConnection();
        return static::fetch($id, $p4);
    }

    /**
     * Fetch all items using optionally using the cache if available
     * @param array         $options    Fetch options. Currently supports
     *                                  FETCH_BY_IDS - Fetch all the workflows with the ids
     *                                  FETCH_NO_CACHE - if set the cache will be ignored
     * @param Connection    $p4         the connection to use
     * @return P4Iterator
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        $models = null;
        if (isset($options[static::FETCH_NO_CACHE])||isset($options[static::FETCH_BY_KEYWORDS])) {
            // No cache
            $models = parent::fetchAll($options, $p4);
        } else {
            $items = null;
            try {
                $items = static::getCached($p4);
            } catch (ServiceNotFoundException $e) {
                // No cache - nothing to invalidate
            }
            $fromCache = $items !== null;
            if (!$fromCache) {
                $items = parent::fetchAll($options, $p4);
            }
            // We need to take care of FETCH_BY_IDS option that is otherwise handled by parent
            // Do the filter before the cloning in case we can trim some out
            // Use FILTER_COPY to leave the actual cached iterator unmodified
            if (isset($options[static::FETCH_BY_IDS]) && is_array($options[static::FETCH_BY_IDS])) {
                $items = $items->filter('id', (array) $options[static::FETCH_BY_IDS], [Iterator::FILTER_COPY]);
            }
            if ($fromCache) {
                // Items from the cache need to be cloned and given a connection
                $models = new P4Iterator;
                foreach ($items as $key => $item) {
                    $models[$key] = clone $item;
                    $models[$key]->setConnection($p4);
                }
            } else {
                $models = $items;
            }
        }
        return $models;
    }

    /**
     * Always goes to Perforce for the data now that file caching is replaced
     * @param Connection $p4 the connection
     * @return null|P4Iterator
     */
    protected static function getCached(Connection $p4)
    {
        return parent::fetchAll(
            [
                static::FETCH_NO_CACHE => true
            ],
            $p4
        );
    }
}
