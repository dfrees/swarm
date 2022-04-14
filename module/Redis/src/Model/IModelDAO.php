<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

/**
 * Interface for all Swarm DAOs to implement
 * @package Application\Model
 */

interface IModelDAO extends \Application\Model\IModelDAO
{
    /**
     * Populate the cache with the objects returned from the Perforce Server. Ensuring that any cache items for the
     * model is removed before we populate to ensure we do not leave random id within the cache.
     */
    public function populate();

    /**
     * Remove all keys for the namespace and prefix configured for this DAO.
     */
    public function invalidate();
}
