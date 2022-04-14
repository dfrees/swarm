<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Checker;

/**
 * Interface IPermissions. Define behaviour for Permissions
 * @package Application\Permissions
 */
interface IPermissions
{
    const PERMISSIONS   = 'permissions';
    const AUTHENTICATED = 'authenticated';
    // Convenient checker name for authenticated only
    const AUTHENTICATED_CHECKER = [Checker::NAME => self::AUTHENTICATED];
}
