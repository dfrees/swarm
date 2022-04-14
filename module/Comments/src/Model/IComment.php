<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Model;

use Comments\Validator\TaskState;

/**
 * Interface IComment. Define values and responsibilities for a Comments
 * @package Comments\Model
 */
interface IComment
{
    const TOPIC_REVIEWS = 'reviews';
    const TOPIC_CHANGES = 'changes';
    const TOPIC_JOBS    = 'jobs';
    const TOPIC         = 'topic';
    const ID            = 'id';
    const COMMENT       = 'comment';
    const CURRENT       = 'current';
    const USER          = 'user';
    const PREVIOUS      = 'previous';
    const TIME          = 'time';
    const EDITED        = 'edited';
    const BATCHED       = 'batched';
    const CONTEXT       = 'context';
    const ATTACHMENTS   = 'attachments';
    const FLAGS         = 'flags';
    const TASK_STATE    = 'taskState';
    const LIKES         = 'likes';
    const UPDATED       = 'updated';
    const BODY          = 'body';
    const READ_BY       = 'readBy';
    const READ_BY_EVENT = 'readByEvent';
    const READ          = 'read';
    const UNREAD        = 'unread';
    const COUNT         = 'count';

    const FETCH_BY_TOPIC        = self::TOPIC;
    const FETCH_BY_TASK_STATE   = self::TASK_STATE;
    const FETCH_BY_USER         = self::USER;
    const FETCH_IGNORE_ARCHIVED = 'ignoreArchived';
    const FETCH_BY_CONTEXT      = self::CONTEXT;
    const FETCH_BATCHED         = self::BATCHED;
    const FETCH_BY_READ_BY      = self::READ_BY;
    const FETCH_BY_UNREAD_BY    = 'unReadBy';

    const ACTION_ADD          = 'add';
    const ACTION_EDIT         = 'edit';
    const ACTION_NONE         = 'none';
    const ACTION_STATE_CHANGE = 'state';
    const ACTION_LIKE         = 'like';
    const ACTION_UNLIKE       = 'unlike';
    const ACTION_ARCHIVE      = 'archive';
    const ARCHIVE_OPERATION   = 'operation';
    const ACTION_UNARCHIVE    = 'unarchive';
    const FLAG_CLOSED         = 'closed';

    const TASK_COMMENT          = TaskState::COMMENT;
    const TASK_OPEN             = TaskState::OPEN;
    const TASK_ADDRESSED        = TaskState::ADDRESSED;
    const TASK_VERIFIED         = TaskState::VERIFIED;
    const TASK_VERIFIED_ARCHIVE = TaskState::VERIFIED_ARCHIVE;

    const ROUTE_REVIEW = 'review';
    const ROUTE_CHANGE = 'change';
    const ROUTE_JOB    = 'job';
}
