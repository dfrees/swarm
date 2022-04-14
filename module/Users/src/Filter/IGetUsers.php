<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IGetUsers. Fields related to getting users
 * @package Users\Filter
 */
interface IGetUsers extends InvokableService
{
    const IDS    = "ids";
    const AVATAR = "avatar";
    const ID     = "id";
    const USER   = "User";
}
