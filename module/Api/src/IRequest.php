<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api;

/**
 * Defines common param names and field names used in API Requests
 * @package Api
 */
interface IRequest
{
    const AFTER                = 'after';
    const CHANGE               = 'change';
    const CONTEXT              = 'context';
    const CURRENT              = 'current';
    const EXCLUDE_PROJECTS     = 'excludeProjects';
    const FIELDS               = 'fields';
    const FORMAT               = 'format';
    const GROUP                = 'group';
    const IGNORE_ARCHIVED      = 'ignoreArchived';
    const IGNORE_BLACKLIST     = 'ignoreBlacklist';
    const IGNORE_EXCLUDE_LIST  = 'ignoreExcludeList';
    const KEYWORDS             = 'keywords';
    const MAX                  = 'max';
    const NO_CACHE             = 'noCache';
    const PROJECT              = 'project';
    const SORT                 = 'sort';
    const STREAM               = 'stream';
    const TASKS_ONLY           = 'tasksOnly';
    const TOPIC                = 'topic';
    const TYPE                 = 'type';
    const UP_VOTERS            = 'upVoters';
    const USERS                = 'users';
    const USER                 = 'user';
    const VERSION              = 'version';
    const WORKFLOW             = 'workflow';
    const METADATA             = 'metadata';
    const FILES                = 'files';
    const ROOT                 = 'root';
    const FILE_CHANGES         = 'fileChanges';
    const LIMITED              = 'limited';
    const TESTDEFINITIONS      = 'testdefinitions';
    const UPGRADED_PARAMS      = [ self::IGNORE_EXCLUDE_LIST => self::IGNORE_BLACKLIST ];
    const RESULT_ORDER         = 'resultOrder';
    const RESULT_ORDER_CREATED = 'created';
    const RESULT_ORDER_UPDATED = 'updated';
    // This is only for the legacy API (v9 and earlier) that accepts taskStates as a query parameter that gets
    // converted to taskState in the IndexController
    const TASK_STATES = 'taskStates';
}
