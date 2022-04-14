<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IParameters. Interface for activity parameter filters
 * @package Activity\Filter
 */
interface IParameters extends InvokableService
{
    // Filter service names
    const ACTIVITY_PARAMETERS_FILTER        = "activityParametersFilter";
    const ACTIVITY_STREAM_PARAMETERS_FILTER = "activityStreamParametersFilter";
    // Parameter values
    const CHANGE   = "change";
    const FOLLOWED = "followed";
}
