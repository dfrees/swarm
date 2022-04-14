<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IComment. Interface for comment filters to implement
 * @package Comments\Filter
 */
interface IComment extends InvokableService
{
    const COMMENTS_EDIT_FILTER   = "commentsEditFilter";
    const COMMENTS_CREATE_FILTER = "commentsCreateFilter";
}
