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
 * Interface IParameters
 * @package Comments\Filter
 */
interface IParameters extends InvokableService
{
    const COMMENTS_PARAMETERS_FILTER      = 'commentsParameters';
    const EDIT_COMMENTS_PARAMETERS_FILTER = 'editCommentsParameters';
}
