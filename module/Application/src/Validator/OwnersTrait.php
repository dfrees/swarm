<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Application\Model\ServicesModelTrait;
use Groups\Model\Group;
use P4\Connection\ConnectionInterface;

/**
 * Trait OwnersTrait. To provide common behaviour for model classes that have user and group owners
 * @package Application\Validator
 */
trait OwnersTrait
{
    /**
     * Determines if the user is an owner by checking the list of owners (including group membership if the list
     * contains groups)
     * @param ConnectionInterface   $connection p4 connection
     * @param string                $userId     user id to test for ownership. If the userId is an empty string then we
     *                                          assume not owned
     * @param array                 $owners     owners to search
     * @return bool true if the user is an individual owner or member of a group that is an owner
     */
    private function isUserAnOwner(ConnectionInterface $connection, string $userId, array $owners)
    {
        $owned = false;
        if (trim($userId)) {
            $groupDAO = ServicesModelTrait::getGroupDao();
            foreach ($owners as $owner) {
                if (Group::isGroupName($owner)) {
                    if ($groupDAO->isMember($userId, Group::getGroupName($owner), true, $connection)) {
                        $owned = true;
                        break;
                    }
                } elseif ($owner === $userId) {
                    $owned = true;
                    break;
                }
            }
        }
        return $owned;
    }
}
