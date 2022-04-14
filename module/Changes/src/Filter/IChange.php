<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IChange
 * @package Changes\Filter
 */

interface IChange extends InvokableService
{
    // Services
    const GET_FILES_FILTER   = 'getFilesFilter';
    const GET_CHANGES_FILTER = 'getChangesFilter';

    // Constants
    const FROM_CHANGE_ID = 'fromChangeId';
    const PENDING        = 'pending';
    const ROOT_PATH      = 'rootPath';
    const USER           = 'user';
    const LAST_SEEN      = 'lastSeen';
}
