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
 * Interface IVersion, providing common values to deal with review version information
 * @package Reviews\Filter
 */
interface IVersion extends InvokableService
{
    const VERSION_FILTER = 'versionFilter';
    const MAX_VERSION    = 'maxVersion';
    const MAX_FROM       = 'maxFrom';
    const FROM           = 'from';
    const TO             = 'to';
}
