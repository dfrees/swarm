<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Controller;

use Laminas\View\Model\JsonModel;

/**
 * Interface ICommentApi. Constants and contract for the comment API
 * @package Comments\Controller
 */
interface ICommentApi
{

    public const INVALID_PARAMETERS         = 'Parameters must include either body or taskState';
    public const INVALID_PERMISSION         = 'Only the comment author can edit the body of a comment';
    public const TOPIC_ID                   = 'topic_id';
    public const SILENCE_NOTIFICATION_PARAM = 'silenceNotification';
    public const QUIET                      = 'quiet';
    public const SEND                       = 'sendComments';

    /**
     * Get comments by topic and topic id
     * @return JsonModel
     */
    public function getCommentsByTopicIdAction() : JsonModel;

    /**
     * Edit an existing comment for Swarm
     * @return  JsonModel
     */
    public function editAction() : JsonModel;

    /**
     * Archive or un-archive a comment and all of its replies. The specific action is determined from the path.
     * @return JsonModel the comments affected by the action, for example
     * Success response:
     * {
     *      "error": null,
     *      "messages": [],
     *      "data": {
     *          "comments":
     *          {
     *              "id": 518,
     *              "topic": "reviews/12241",
     *              "context": {
     *                  "version": 1
     *              },
     *              "attachments": [],
     *              "flags": [],
     *              "taskState": "comment",
     *              "likes": [],
     *              "user": "bruno",
     *              "time": 1633615335,
     *              "updated": 1633615335,
     *              "edited": null,
     *              "body": "h",
     *              "readBy": [],
     *              "notify": "delayed"
     *          },
     *          ...
     *      }
     * }
     * If the comment cannot be found by its id a 404 error is returned.
     */
    public function archiveOrUnArchiveAction() : JsonModel;

    /**
     * Mark a comment as unread or read depending on topic.
     * @return  JsonModel
     */
    public function markCommentAsReadOrUnreadAction() : JsonModel;

    /**
     * Send all notification for a given topic and id. It will use the active user to send
     * any delayed comments for them.
     *
     * @return JsonModel the count of comments notifications to be sent.
     * Success response:
     * {
     *      "error": null,
     *      "messages": [],
     *      "data": {
     *          "count": 4
     *      }
     * }
     */
    public function sendNotificationAction() : JsonModel;
}
