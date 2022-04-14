<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Application\Response\CallbackResponse;
use Comments\Model\Comment;
use Comments\Module as CommentsModule;
use P4\Spec\Change;
use Reviews\Model\Review;
use Laminas\Http\Request;
use Laminas\View\Model\JsonModel;
use Laminas\Json\Json;

/**
 * Swarm Comments
 */
class CommentsController extends AbstractApiController
{
    // Define the value for silenceNotification
    const SILENCE_NOTIFICATION_PARAM = 'silenceNotification';


    /**
     * Get a list of comments, with pagination support, optionally filtered by context/version and task/states
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $topic   = $request->getQuery(self::TOPIC);
        $fields  = $request->getQuery(self::FIELDS);
        $context = $request->getQuery(self::CONTEXT);
        $query   = [
            'after'          => $request->getQuery(self::AFTER),
            'max'            => $request->getQuery(self::MAX, 100),
            'limitVersion'   => isset($context['version']),
            'context'        => isset($context['version']) ? ['version' => $context[ 'version']] : null,
            'ignoreArchived' => $request->getQuery(self::IGNORE_ARCHIVED),
            'tasksOnly'      => $request->getQuery(self::TASKS_ONLY),
            'taskStates'     => $request->getQuery(self::TASK_STATES),
        ];

        try {
            $result = $this->forward(
                \Comments\Controller\IndexController::class, 'index',
                ['topic' => $topic],
                $query
            );
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(200);
            $result = [
                'comments' => [],
                'lastSeen' => null,
            ];
        }

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }


    /**
     * Create a new Swarm commment
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function create($data)
    {
        $defaults = [
            'topic'                => '',
            'body'                 => '',
            'context'                         => '',
            'taskState'                       => 'comment',
            'flags'                           => [],
            Comment::READ_BY            => [],
            self::SILENCE_NOTIFICATION_PARAM  => false,
            'delayNotification'               => false
        ];

        try {
            $data = $this->filterCommentContext($data) + $defaults;
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(400);

            return new JsonModel(
                [
                    'error'   => 'Provided context could not be filtered.',
                    'details' => ['context' => $e->getMessage()]
                ]
            );
        }

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->services;
        $post     = [
            'bundleTopicComments' => false,
            'user'                => $services->get('user')->getId(),
            ] + array_intersect_key($data, $defaults);

        $this->setNotificationValues($post);

        $result = $this->forward(
            \Comments\Controller\IndexController::class,
            'add',
            null,
            null,
            $post
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }
    /**
     * Modify part of an existing comment
     * @param   int     $id
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function patch($id, $data)
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->services;
        $post     = [
                'bundleTopicComments' => false,
                'user'                => $services->get('user')->getId(),
            ] + array_intersect_key(
                $data,
                array_flip(
                    ['topic', 'body', 'taskState', 'flags', self::SILENCE_NOTIFICATION_PARAM, 'delayNotification']
                )
            );

        $this->setNotificationValues($post);

        $result = $this->forward(
            \Comments\Controller\IndexController::class,
            'edit',
            ['comment' => $id],
            null,
            $post
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Sets up silenceNotification and delayNotification values
     * @param $post
     */
    private function setNotificationValues(&$post)
    {
        $post[self::SILENCE_NOTIFICATION_PARAM] =
            (isset($post[self::SILENCE_NOTIFICATION_PARAM]) && $post[self::SILENCE_NOTIFICATION_PARAM] === 'true');
        $post['delayNotification']              =
            (isset($post['delayNotification']) && $post['delayNotification'] === 'true');

        // If we are being silent also set delay so not sent immediately
        if ($post[self::SILENCE_NOTIFICATION_PARAM] === true) {
            $post['delayNotification'] = true;
        }
    }

    /**
     * Mark all comments for the authenticated user as read for the given topic.
     * @return JsonModel
     */
    public function markAllReadAction()
    {
        return $this->markAllAction(true);
    }

    /**
     * Mark all comments for the authenticated user as unread for the given topic.
     * @return JsonModel
     */
    public function markAllUnReadAction()
    {
        return $this->markAllAction(false);
    }

    /**
     * Mark all comments for the authenticated user as either read or unread for the given topic.
     * @param $read
     * @return JsonModel
     */
    private function markAllAction($read)
    {
        try {
            $request = $this->getRequest();
            $data    = null;
            if ($this->requestHasContentType($request, self::CONTENT_TYPE_JSON)) {
                $data = Json::decode($request->getContent(), $this->jsonDecodeType);
            } else {
                $data = $request->getPost()->toArray();
            }
            $services   = $this->services;
            $translator = $services->get('translator');
            $services->get('permissions')->enforce('authenticated');
            $p4Admin = $services->get('p4_admin');
            $userId  = $services->get('user')->getId();
            if ($data && isset($data['topic'])) {
                $topic   = $data['topic'];
                $fetchBy = [Comment::FETCH_BY_TOPIC => $topic];
                // If we are marking as read only fetch the unread ones and vice-versa
                $fetchBy[$read ? Comment::FETCH_BY_UNREAD_BY : Comment::FETCH_BY_READ_BY] = [$userId];

                $comments = Comment::fetchAll($fetchBy, $p4Admin);
                foreach ($comments as $comment) {
                    $read ? $comment->addReadBy($userId) : $comment->removeReadBy($userId);
                    $comment->save();
                }
                $messages = $translator->t(
                    "All comments for topic '%s' marked as %s for user '%s'",
                    [$topic, $read ? 'read' : 'unread', $userId]
                );
                return new JsonModel(
                    [
                        'isValid'  => true,
                        'messages' => $messages,
                        'comment'  => ['readBy' => $read ? [$userId] : []]
                    ]
                );
            } else {
                $messages = $translator->t('Topic must be specified');
                return new JsonModel(
                    [
                        'isValid'  => false,
                        'messages' => $messages,
                    ]
                );
            }
        } catch (\Exception $e) {
            return new JsonModel(
                [
                    'isValid'  => false,
                    'messages' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Send any comment notifications that are currently grouped for delayed notification
     * @return JsonModel
     */
    public function sendAllMyDelayedCommentsAction()
    {
        $request = $this->getRequest();
        $topic   = $request->getPost('topic');
        $preview = $request->getPost('preview');

        $services   = $this->services;
        $logger     = $services->get('logger');
        $p4User     = $services->get('user');
        $translator = $services->get('translator');
        $logger->debug(
            "CommentAPI: Processing a notification for delayed comments for topic " .
            $topic . " for user " . $p4User->getId()
        );
        if (is_null($topic)) {
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(
                [
                    'isValid' => false,
                    'error'   => $translator->t('A topic is required to send a notification'),
                    'code'    => 404
                ]
            );
        }
        try {
            $returnedArray = CommentsModule::sendDelayedComments($services, $p4User, $topic, true, $preview);
        } catch (\Exception $e) {
            $logger->debug(
                "CommentAPI: Error Processing " . $topic . " for user" . $p4User->getId() . '\n' .
                $e->getTraceAsString()
            );
            $error = $e->getMessage();
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(
                [
                    'isValid' => false,
                    'error'   => $translator->t($error),
                    'code'    => 404
                ]
            );
        }
        return new JsonModel($returnedArray);
    }

    /**
     * Extends parent to provide special preparation of comment data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // clean up model to minimize superfluous data
        unset($model->messages);
        unset($model->taskTransitions);
        unset($model->topic);

        // make adjustments to 'comment' entity if present
        $comment = $model->getVariable('comment');
        if ($comment) {
            $model->setVariable('comment', $this->normalizeComment($comment, $limitEntityFields));
        }

        // if a list of comments is present, normalize each one
        $comments = $model->getVariable('comments');
        if ($comments) {
            $comments = array_values($comments);
            foreach ($comments as $key => $comment) {
                $comments[$key] = $this->normalizeComment($comment, $limitEntityFields);
            }

            $model->setVariable('comments', $comments);
        }

        return $model;
    }

    protected function normalizeComment($comment, $limitEntityFields = null)
    {
        return $this->limitEntityFields($this->sortEntityFields($comment), $limitEntityFields);
    }

    /**
     * Examines provided context to determine if any parts are missing and need to be filled in
     *
     * @param   $comment    array                       the full comment structure
     * @throws  \InvalidArgumentException               if an invalid argument was provided
     * @throws  \P4\Spec\Exception\NotFoundException    if no such change or review exists.
     * @return array        the full comment structure, with context filtered for Swarm consumption
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\NotFoundException
     * @throws \Record\Exception\NotFoundException
     */
    protected function filterCommentContext($comment)
    {
        // extract context and exit early if there's nothing to do
        $context = isset($comment['context']) ? $comment['context'] : [];
        if (!$context || !is_array($comment['context'])) {
            unset($comment['context']);
            return $comment;
        }

        // extract the topic information and limit context normalization to Changes and Reviews
        if (!preg_match('#(changes|reviews)/([0-9]+)#', isset($comment['topic']) ? $comment['topic'] : '', $matches)) {
            unset($comment['context']);
            return $comment;
        }

        $group = $matches[1];
        $id    = $matches[2];

        // ensure that a path has been provided
        if (!isset($context['file'])) {
            throw new \InvalidArgumentException("File path is required when specifying inline comment context.");
        }

        $services = $this->services;
        $p4       = $services->get('p4');
        $review   = null;
        $change   = null;

        // if commenting on a review, fetch the review and inject its ID into the context
        if ($group === 'reviews') {
            $review            = Review::fetch($id, $p4);
            $context['review'] = $review->getId();
            unset($context['change']);
        }

        // if commenting on a change, fetch the change and inject its ID into the context
        if ($group === 'changes') {
            $change            = Change::fetchById($id, $p4);
            $context['change'] = $change->getId();
            unset($context['review']);
        }

        // fetch valid review versions and determine which version is being commented on (default to latest)
        $validVersions = $review ? $review->get('versions') : [];
        $version       = isset($context['version']) ? $context['version'] : null;
        $version       = $version ? (int)$version : count($validVersions);
        if ($review && !$review->hasVersion($version)) {
            throw new \InvalidArgumentException("Specified version was not found in this review.");
        }

        // if content has already been provided, check that it is a valid array of strings,
        // then set the leftLine/rightLine/version and exit with sorted context fields
        if (isset($context['content']) && is_array($context['content']) && count($context['content']) > 0) {
            foreach ($context['content'] as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Context content must be an array of strings.');
                }
            }

            if ($review && $version) {
                $context['version'] = $version;
            }

            $context['leftLine']  = isset($context['leftLine'])  ? $context['leftLine']  : null;
            $context['rightLine'] = isset($context['rightLine']) ? $context['rightLine'] : null;
            $comment['context']   = $this->sortContextFields($context);
            return $comment;
        }

        if ($review && $version) {
            $change             = Change::fetchById($review->getChangeOfVersion($version), $p4);
            $context['version'] = $version;
        }

        // find the data for the specified file in the change
        $fileData = current(
            array_filter(
                $change->getFileData(true),
                function ($item) use ($context) {
                    return $item['depotFile'] === $context['file'];
                }
            )
        );

        if (!$fileData) {
            throw new \InvalidArgumentException('File path not found in specified review/change.');
        }

        $action    = isset($fileData['action'])   ? $fileData['action']         : 'edit';
        $leftLine  = isset($context['leftLine'])  ? (int) $context['leftLine']  : null;
        $rightLine = isset($context['rightLine']) ? (int) $context['rightLine'] : null;
        $rightPath = $fileData['depotFile'];
        $pending   = $change->isPending();
        $rev       = $pending ? $change->getId() : $fileData['rev'];
        $maxLines  = 5;
        $lines     = $this->fetchDiffSnippet($action, $leftLine, $rightLine, $rightPath, $pending, $rev, $maxLines);

        // if the line is not found in the diff and we have a line number,
        // we check for context using fileAction() in the Files module
        $lineNumber = isset($context['rightLine']) ? $context['rightLine'] : null;
        $lineNumber = !$lineNumber && isset($context['leftLine']) ? $context['leftLine'] : $lineNumber;
        if (!$lines && $lineNumber) {
            $lines = $this->fetchFullFileSnippet($rightPath, $lineNumber, $maxLines);
        }

        // ensure that, at most, only $maxLines of content are attached to the context
        // if no lines are matched, convert the context to a file-level comment
        if (count($lines) > 0) {
            $context['leftLine']  = $leftLine  ?: null;
            $context['rightLine'] = $rightLine ?: null;
            $context['content']   = array_map(
                function ($value) {
                    return substr($value, 0, 256);
                },
                array_slice($lines, 0, $maxLines)
            );
        } else {
            unset($context['leftLine']);
            unset($context['rightLine']);
            unset($context['content']);
        }

        $comment['context'] = $this->sortContextFields($context);

        return $comment;
    }

    protected function fetchDiffSnippet($action, $leftLine, $rightLine, $rightPath, $pending, $rev, $maxLines)
    {
        $leftRev    = $pending ? '' : '#' . ($rev - 1);
        $diffParams = [
            'right'  => $rightPath . ($pending ? '@=' . $rev : '#' . $rev),
            'left'   => $rightPath . $leftRev,
            'action' => $action,
        ];

        if ($leftRev === '#0' || $action == 'add') {
            unset($diffParams['left']);
        }

        $diffResult = $this->forward(
            \Files\Controller\IndexController::class,
            'diff',
            null,
            $diffParams
        );

        $diff      = $diffResult->getVariable('diff', []);
        $lines     = [];
        $foundLine = false;

        // scan through the diff to find if any of the chunks match the provided line
        // number, maintaining a buffer of the most recent $maxLines lines examined,
        // using array_shift to discard older lines.
        // when moving to the next diff chunk, if we haven't found a matching line,
        // reset the $lines buffer.
        foreach ($diff['lines'] as $currentLine) {
            if ($currentLine['type'] === 'meta') {
                $lines = $foundLine ? $lines : [];
                continue;
            }

            if ($foundLine) {
                continue;
            }

            // add the current line to the buffer
            array_push($lines, $currentLine['value']);

            if ($leftLine && $currentLine['leftLine'] === $leftLine) {
                $foundLine = true;
            }

            if ($rightLine && $currentLine['rightLine'] === $rightLine) {
                $foundLine = true;
            }

            // remove a line from the back of the buffer, if it contains more than $maxLines
            if (count($lines) > $maxLines) {
                array_shift($lines);
            }
        }

        return $foundLine ? $lines : [];
    }

    protected function fetchFullFileSnippet($rightPath, $lineNumber, $maxLines)
    {
        $lines      = [];
        $fileResult = $this->forward(
            \Files\Controller\IndexController::class,
            'file',
            [
                'path' => $rightPath,
            ],
            [
                'lines'  => [
                    'start' => $lineNumber - ($maxLines - 1) > 0 ? $lineNumber - ($maxLines - 1) : 1,
                    'end'   => $lineNumber
                ],
                'view'   => true,
                'format' => 'json',
            ]
        );

        if ($fileResult instanceof CallbackResponse) {
            $lines = array_values(json_decode($fileResult->getContent(), true));
        }

        // reformat the lines
        // - include a leading space that indicates the lines are not an add or an edit
        // - remove newline characters
        foreach ($lines as $key => $line) {
            $lines[$key] = ' ' . preg_replace('/(\r\n|\n)$/', '', $line);
        }

        return $lines;
    }

    /**
     * Helper to order context fields according to expectations
     *
     * @param   array   $context    the context keys/values to sort (shallow)
     * @return  array   the sorted keys/values
     */
    protected function sortContextFields(array $context)
    {
        $sortedContext = [];
        $contextOrder  = ['file', 'leftLine', 'rightLine', 'content', 'change', 'review', 'version'];

        foreach ($contextOrder as $key) {
            if (array_key_exists($key, $context)) {
                $sortedContext[$key] = $context[$key];
                unset($context[$key]);
            }
        }

        // return with any leftover keys at the end
        return $sortedContext + $context;
    }
}
