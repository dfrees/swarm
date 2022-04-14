<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\View\Helper;

use Comments\Model\Comment;
use P4\Model\Fielded\Iterator;
use Application\View\Helper\AbstractHelper;

class Comments extends AbstractHelper
{
    protected $defaultTemplate = 'comments.phtml';

    private static $COMMENT_CACHE = [];

    /**
     * If called without arguments, return instance of this class, otherwise
     * render comments.
     * @param $topic topic to render comments for
     * @param null $template optional - template to use for rendering
     * @param null $version the version
     * @param null $parentComment the parent comment
     * @param bool $canAttach whether there is permission for attachments
     * @param int $level level
     * @return $this|string
     * @throws \Exception
     */
    public function __invoke(
        $topic = null,
        $template = null,
        $version = null,
        $parentComment = null,
        $canAttach = false,
        $level = 0
    ) {
        if ($topic === null && $template === null) {
            return $this;
        }

        return $this->render($topic, $template, $version, $parentComment, $canAttach, $level);
    }

    /**
     * Render comments for a given topic.
     * @param $topic topic to render comments for
     * @param null $template optional - template to use for rendering
     * @param null $version the version
     * @param null $parentComment the parent comment
     * @param bool $canAttach whether there is permission for attachments
     * @param int $level level
     * @return string
     * @throws \Exception
     */
    public function render(
        $topic = null,
        $template = null,
        $version = null,
        $parentComment = null,
        $canAttach = false,
        $level = 0
    ) {
        $view         = $this->getView();
        $services     = $this->services;
        $config       = $services->get('config');
        $maxSize      = $config['attachments']['max_file_size'];
        $mentionsMode = $config['mentions']['mode'];
        $p4Admin      = $services->get('p4_admin');
        $ipProtects   = $services->get('ip_protects');
        $options      = [
            Comment::FETCH_BY_TOPIC => $topic
        ];

        // If this a new call or there is no cached value, fetch data
        if ($parentComment === null || !isset(Comments::$COMMENT_CACHE[$topic])) {
            Comments::$COMMENT_CACHE[$topic]['comments']    = Comment::fetchAll($options, $p4Admin, $ipProtects);
            Comments::$COMMENT_CACHE[$topic]['attachments'] =
                Comment::fetchAttachmentsByComments(Comments::$COMMENT_CACHE[$topic]['comments'], $p4Admin);
        }
        $comments    = new Iterator(iterator_to_array(Comments::$COMMENT_CACHE[$topic]['comments']));
        $attachments = Comments::$COMMENT_CACHE[$topic]['attachments'];

        // check mentions settings, can be one of:
        // - disabled
        // - enabled for all users and all groups in all review comments
        // - enabled only for project users and groups in review that has a project (default)
        $mentions = [];
        switch ($mentionsMode) {
            case 'disabled':
            case 'global':
                break;
            default:
                $mentions = Comment::getPossibleMentions($topic, $config, $p4Admin);
        }

        // if a version has been provided and this is a review topic,
        // filter out any comments that don't have a matching version
        if ($version && strpos($topic, 'reviews/') === 0) {
            $comments->filterByCallback(
                function (Comment $comment) use ($version) {
                    // return comment if we don't provided a numbered version.
                    if ($version === true) {
                        return true;
                    }
                    $context          = $comment->getContext();
                    $commentVersion   = isset($context['version']) ? $context['version'] : null;
                    $commentAttribute = isset($context['attribute']) ? $context['attribute'] : null;
                    return ($commentVersion == $version || $version === false) || $commentAttribute === 'description';
                }
            );
        }

        // Filter out comments that are not in this context
        $comments->filterByCallback(
            function (Comment $comment) use ($parentComment) {
                $context = $comment->getContext();
                return $parentComment
                    ? (isset($context['comment']) && $context['comment'] === $parentComment)
                    : (!isset($context['comment']) || empty($context['comment']));
            }
        );

        return $view->render(
            $template ?: $this->defaultTemplate,
            [
                'topic'       => $topic,
                'level'       => $level,
                'maxSize'     => $maxSize,
                'comments'    => $comments,
                'attachments' => $attachments,
                'canAttach'   => $canAttach,
                'mentions'    => $mentions,
                'mode'        => $mentionsMode
            ]
        );
    }

    /**
     * Return number of open comments for a given topic.
     *
     * @param   string  $topic  topic to get a count for
     * @return  int     number of comments for a given topic
     */
    public function count($topic)
    {
        $p4Admin = $this->services->get('p4_admin');
        return current(current(Comment::countByTopic($topic, $p4Admin)));
    }
}
