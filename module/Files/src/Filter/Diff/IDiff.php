<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Filter\Diff;

use Application\Factory\InvokableService;

/**
 * Interface IDiff. Fields related to Diffs on a file
 * @package Reviews\Filter
 */
interface IDiff extends InvokableService
{
    // Name of service
    const NAME = 'fileDiffFilter';

    // Error key to use when displaying errors for the 'type' field
    const TYPE_ERROR_KEY = 'invalidSpecType';

    // Param names
    const FROM      = 'from';
    const TO        = 'to';
    const LINES     = 'lines';
    const IGNORE_WS = 'ignoreWs';
    const MAX_SIZE  = 'maxSize';
    const MAX_DIFFS = 'maxDiffs';
    const OFFSET    = 'offset';
    const TYPE      = 'type';
    const FROM_FILE = 'fromFile';
}
