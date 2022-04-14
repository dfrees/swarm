<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments;

use Application\Config\ConfigManager;
use Comments\Model\Comment;
use Users\Model\User;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Module
{
    /**
     * Builds a hash value for the user and topic to be used in the construction of a future task file name
     * @param $topic string the topic
     * @param $user string the user
     * @return string the md5 hash
     */
    public static function getFutureCommentNotificationHash($topic, $user)
    {
        // salt the hash with 'future-comment-notification' in case topic and user hash wants to be used for
        // some other purpose later
        return hash('md5', 'future-comment-notification' . $topic . $user, false);
    }

    /**
     * Does the work to check if there are any delayed comments. If there is
     * it will process them and pass it onto the next queue for 'comment.batch'.
     *
     * @param   ServiceLocator  $services   The service locator
     * @param   User            $user       The user object
     * @param   string          $topic      The topic this is related to
     * @param   bool            $force      If we should force the action instead of waiting for future time
     * @param   bool            $preview    For previewing the delayed comments. (development purpose only.)
     * @return  array         To be sent back for the API request.
     * @throws \Application\Config\ConfigException
     * @throws \Exception
     * @throws \Record\Exception\NotFoundException
     */
    public static function sendDelayedComments(
        ServiceLocator $services,
        $user,
        $topic,
        $force = false,
        $preview = false
    ) {
        $logger     = $services->get('logger');
        $queue      = $services->get('queue');
        $p4Admin    = $services->get('p4_admin');
        $translator = $services->get('translator');
        $config     = $services->get('config');
        $delayTime  = ConfigManager::getValue($config, ConfigManager::COMMENT_NOTIFICATION_DELAY_TIME);

        $userConfig      = $user->getConfig(); // Now get this users config.
        $delayedComments = $userConfig->getDelayedComments($topic);
        // Now we have the user config we can see how many comments this user has delayed for this given topic.

        $totalComments = count($delayedComments);
        $message       =
            $totalComments === 0
                ? $translator->t('No comment notifications to send')
                : $translator->tp(
                    "Sending a notification for %s comment",
                    "Sending a notification for %s comments",
                    $totalComments,
                    [$totalComments]
                );
        // Pre build the return array as we only want to do it once.
        $returnArray = [
            'isValid'   => true,
            'message'   => $message,
            'code'      => 200
        ];
        // If we are in preview mode add that to the return data for the api.
        // Due to this coming from API this can be a string true.
        if ($preview == true) {
            $returnArray['preview'] = true;
        }

        $highestFutureTime = 0;
        // Now we need to find out which comment has the highest future time.
        foreach ($delayedComments as $comment) {
            $future = $comment + $delayTime;
            if ($future > $highestFutureTime) {
                $highestFutureTime = $future;
            }
        }

        $timeIs = time();
        $logger->trace("Comment:: Time is: " . $timeIs . " Highest Time is: " . $highestFutureTime);
        // If there are no comment for this given topic than their is nothing to do here.
        if ($totalComments > 0 && ($highestFutureTime <= $timeIs || $force === true)) {
            if ($preview == true) {
                return $returnArray;
            }
            $logger->debug("Comment: Delay comment timer has expired, sending all comments for " . $topic);
            // Set the array pointer to the last comment and get that comment data.
            end($delayedComments);
            $commentID = key($delayedComments);
            $comment   = Comment::fetch($commentID, $p4Admin);
            // Now save that comment batched field to false to finish batching comments.
            $comment->set('batched', false)->save();
            // Now remove the delayed comments record for this user.
            $userConfig->setDelayedComments($topic, null)->save();
            $logger->debug(
                "CommentAPI: Removed all " . $totalComments . " delayed comments from topic " . $topic
                . " for user" . $user->getId()
            );
            $logger->trace("Comment:: Sending to comment batch queue.");
            // Pass the processing back to the batch comments queue.
            $queue->addTask(
                'comment.batch',
                $topic,
                ['sendComments' => $delayedComments]
            );
        } else {
            if ($preview != true) {
                $logger->debug("Comment: No comments delayed for " . $topic);
            }
        }

        return $returnArray;
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
