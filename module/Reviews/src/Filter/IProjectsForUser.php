<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IProjectsForUser
 * @package Reviews\Filter
 */
interface IProjectsForUser extends InvokableService
{
    const PROJECTS_FOR_USER_VALUE = "projectsForUser";
    const PROJECTS_FOR_USER       = 'projects-for-user:';
    const ROLES                   = 'roles';
}
