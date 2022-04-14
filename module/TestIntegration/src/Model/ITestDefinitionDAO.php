<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration\Model;

use Application\Factory\InvokableService;

/**
 * Interface ITestDefinitionDAO for implementations of a test definition DAO
 * @package TestIntegration\Model
 */
interface ITestDefinitionDAO extends InvokableService
{
    const FETCH_BY_NAMES = 'names';
}
