<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IFile
 * @package Files\Filter
 */
interface IFile extends InvokableService
{
    const UPDATE_FILTER = 'updateFileFilter';
    const GET_FILTER    = 'getFileFilter';
    const FILE_NAME     = 'fileName';
    const CONTENT       = 'content';
    const REVISION      = 'fileRevision';
    const DESCRIPTION   = 'description';
    const COMMENT       = 'comment';
    const ACTION        = 'action';
    const SUBMIT        = 'submit';
    const SHELVE        = 'shelve';
    const VALID_ACTIONS = [self::SUBMIT, self::SHELVE];
    const CONTENT_LINK  = 'contentLink';
    const CONTENT_TYPE  = 'contentType';
    const CHANGE_ID     = 'changeId';

    // Diff-specific constants
    const DIFFS   = 'diffs';   // array of diff hunk strings), also used as a paging sub-key
    const OFFSET  = 'offset';  // next offset - part of pagination
    const PAGING  = 'paging';  // key for encapsulating pagination data
    const SUMMARY = 'summary'; // key for encapsulating adds, deletes & updates
}
