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
 * Interface IFileReadUnRead, providing common values to deal with file read and unread operation
 * @package Reviews\Filter
 */
interface IFileReadUnRead extends InvokableService
{
    const VERSION                 = 'version';
    const PATH                    = 'path';
    const FILE_READ_UNREAD_FILTER = 'fileReadUnReadFilter';
    const READ                    = 'read';
    const UNREAD                  = 'unread';
}
