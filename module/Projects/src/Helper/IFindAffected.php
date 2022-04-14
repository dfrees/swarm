<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Helper;

use Application\Config\ConfigException;
use P4\Connection\ConnectionInterface as Connection;
use P4\Spec\Change;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Describes a service to find projects based on criteria such as changes, jobs and reviews
 * @package Projects\Helper
 */
interface IFindAffected
{
    /**
     * Determine which projects (and branches) are affected by the given change.
     *
     * @param Change              $change       the change to examine
     * @param Connection          $connection   the connection
     * @param array|null          $options      flags to pass to a 'p4 describe' command.
     * @param array|null          $projects     The list of projects to search
     * @return array a list of affected projects as keys with a list of affected branches
     * under those keys (as the value)
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    public function findByChange(Connection $connection, Change $change, $options = [], $projects = null);
}
