<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Permissions\Exception;

/**
 * This exception indicates you are not logged in and the requested
 * action is only available to authenticated users.
 */
class UnauthorizedException extends Exception
{
}
