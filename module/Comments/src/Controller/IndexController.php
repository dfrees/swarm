<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Controller;

use Application\Controller\AbstractIndexController;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Application\Permissions\RestrictedChanges;
use Attachments\Model\Attachment;
use Comments\Model\Comment;
use P4\Exception;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Reviews\Model\Review;
use Users\Model\User;
use Application\InputFilter\InputFilter;
use Laminas\Json\Json;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Laminas\Stdlib\Parameters;
use Api\Controller\CommentsController;

class IndexController extends AbstractIndexController
{
    // Define some possible POST parameters from the UI
    const ADD_READ_BY    = 'addReadBy';
    const REMOVE_READ_BY = 'removeReadBy';

    /**
     * Index action to return rendered comments for a given topic.
     *
     * @return  ViewModel
     * @throws ForbiddenException
     * @throws Exception
     * @throws RecordNotFoundException
     */
    public function indexAction()
    {
        $topic   = trim($this->getEvent()->getRouteMatch()->getParam('topic'), '/');
        $request = $this->getRequest();
        $query   = $request->getQuery();
        $format  = $query->get('format');
        $userDAO = $this->services->get(IModelDAO::USER_DAO);

        // determine version-specific information
        $context      = $query->get('context');
        $limitVersion = (bool) $query->get('limitVersion', false);

        // send 404 if no topic is provided for non-JSON request
        if (!strlen($topic) && $format !== 'json') {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if the topic is provided and relates to a review or a change, ensure it's accessible
        if (strlen($topic)) {
            $this->restrictAccess($topic);
        }
        // handle requests for JSON
        if ($format === 'json') {
            $p4Admin    = $this->services->get('p4_admin');
            $ipProtects = $this->services->get('ip_protects');
            $comments   = Comment::fetchAll(
                $this->getFetchAllOptions($topic, $query),
                $p4Admin,
                $ipProtects
            );

            // if a version has been provided and this is a review topic,
            // filter out any comments that don't have a matching version
            if ($limitVersion && strpos($topic, 'reviews/') === 0) {
                $version = $context['version'];
                $comments->filterByCallback(
                    function (Comment $comment) use ($version) {
                        $context = $comment->getContext();
                        return isset($context['version']) && $context['version'] == $version;
                    }
                );
            }

            // prepare comments for output
            $preparedComments = $comments->toArray();

            // handle the case when only tasks are requested
            $tasksOnly = $query->get('tasksOnly');
            if ($tasksOnly && $tasksOnly !== 'false') {
                $view = $this->services->get('ViewRenderer');
                foreach ($preparedComments as $id => &$comment) {
                    // prepare comment url
                    $fileContext    = $comments[$id]->getFileContext();
                    $comment['url'] = $fileContext['file']
                        ? '#' . $view->escapeUrl($fileContext['md5']) . ',c' . $view->escapeUrl($id)
                        : '#comments';

                    $fullName = $user = $comment['user'];
                    try {
                        $fullName = $userDAO->fetchById($user, $p4Admin);
                        $fullName = $fullName->getFullName();
                    } catch (SpecNotFoundException $e) {
                        // Didn't find username just move on and assign user id as fullname.
                    } catch (Exception $p4Error) {
                        $logger = $this->services->get('logger');
                        // found an error and should report this to log
                        $logger->debug(
                            "Comments:: There was an error with fetching user: ".$user.", Error: " . $p4Error
                        );
                    }
                    $comment['userFullName'] = $fullName;
                }
            }

            return new JsonModel(
                [
                    'topic'    => $topic,
                    'comments' => $preparedComments,
                    'lastSeen' => $comments->getProperty('lastSeen')
                ]
            );
        }

        $view = new ViewModel(
            [
                'topic'     => $topic,
                'version'   => $limitVersion && isset($context['version']) ? $context['version'] : false,
                'tasksOnly' => $query->get('tasksOnly'),
                'canAttach' => $this->services->get('depot_storage')->isWritable('attachments/'),
            ]
        );

        $view->setTerminal(true);
        return $view;
    }

    /**
     * Establishes whether the request is delaying comment notifications
     * @param $request the request
     * @return bool true is delay is in force
     */
    private function getIsDelayedNotification($request)
    {
        $delay = $request->getPost('delayNotification', false);
        // Front end will send string version
        return $delay === 'true' || $delay === true;
    }

    /**
     * Establishes whether the request is making comment notifications silent
     * @param $request the request
     * @return bool true if silent is in force
     */
    private function isSilencedNotification($request)
    {
        $silent = $request->getPost(CommentsController::SILENCE_NOTIFICATION_PARAM, false);
        // Front end will send string version
        return $silent === 'true' || $silent === true;
    }

    /**
     * Action to add a new comment.
     * @return JsonModel
     * @throws ForbiddenException
     */
    public function addAction()
    {
        $request = $this->getRequest();
        $this->services->get('permissions')->enforce('authenticated');

        // if the topic relates to a review or change, ensure it's accessible
        $this->restrictAccess($request->getPost('topic'));

        $p4Admin  = $this->services->get('p4_admin');
        $user     = $this->services->get('user');
        $comments = $this->services->get('ViewHelperManager')->get('comments');
        $filter   = $this->getCommentFilter($user, 'add', [Comment::TASK_COMMENT, Comment::TASK_OPEN]);
        $delay    = $this->getIsDelayedNotification($request);
        $posted   = $request->getPost()->toArray();

        $filter->setData($posted);
        $isValid = $filter->isValid();
        if ($isValid) {
            $comment = new Comment($p4Admin);
            $comment->set($filter->getValues())
                    ->save();
            $silent = $this->isSilencedNotification($request);

            // delay comment email notification if we are instructed to do so;
            // otherwise collect previously delayed notifications to send
            $sendComments = null;
            if ($silent !== true) {
                $sendComments = $this->handleDelayedComments($comment, $delay);
            }

            // push comment into queue for further processing.
            // note that we don't send individual notifications for delayed comments
            $queue = $this->services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                [
                    'current'      => $comment->get(),
                    'sendComments' => $sendComments,
                    // 'quiet' is a little odd it may be set to true to try and silence everything,
                    // or an array with for example the words 'mail' or 'activity' to
                    // try and silence a particular action. We want to silence the mail if it
                    // is being delayed; the activity still must be created so we do not want quiet
                    // to be set to true
                    'quiet'               => $delay ? ['mail'] : null,
                    // Pass through silent so that if it is set no future tasks are created
                    CommentsController::SILENCE_NOTIFICATION_PARAM => $silent
                ]
            );
        }

        $data = [
            'isValid'     => $isValid,
            'messages'    => $filter->getMessages(),
            'comment'     => $isValid ? $comment->get() : null,
        ];
        if ($isValid && $sendComments) {
            $data['delayedSent'] = sizeof($sendComments);
        }

        if ($request->getPost('bundleTopicComments', true)) {
            $context = isset($posted['context']) && strlen($posted['context'])
                ? Json::decode($posted['context'], Json::TYPE_ARRAY)
                : null;
            $version = $request->getPost('limitVersion') && isset($context['version'])
                ? $context['version']
                : null;

            $data['comments'] = $isValid
                ? $comments($filter->getValue('topic'), null, $version)
                : null;
        }

        return new JsonModel($data);
    }

    /**
     * Action to edit a comment
     * @return JsonModel
     * @throws ForbiddenException
     */
    public function editAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => 'Invalid request method. HTTP POST required.'
                ]
            );
        }

        // start by ensuring the user is at least logged in
        $user = $this->services->get('user');
        $this->services->get('permissions')->enforce('authenticated');

        // attempt to retrieve the specified comment
        // translate invalid/missing id's into a 404
        try {
            $id      = $this->getEvent()->getRouteMatch()->getParam('comment');
            $p4Admin = $this->services->get('p4_admin');
            $comment = Comment::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        if (!isset($comment)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if a topic was not provided, try to fetch the comment and use the comment's topic
        $topic = $this->request->getPost('topic') ?: $comment->get('topic');

        // if the topic relates to a review or a change, ensure it's accessible
        $this->restrictAccess($topic);

        // users can only edit the content of comments they own
        $isContentEdit = $request->getPost('body') !== null || $request->getPost('attachments') !== null;
        if ($isContentEdit && $comment->get('user') !== $user->getId()) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        // users cannot add/remove likes or change readBy on archived comments
        $isAllowed = $request->getPost('addLike')   || $request->getPost('removeLike') ||
                     $request->getPost(self::ADD_READ_BY) || $request->getPost(self::REMOVE_READ_BY);
        if ($isAllowed && in_array('closed', $comment->getFlags())) {
            $this->getResponse()->setStatusCode(403);
            return;
        }

        $filter   = $this->getCommentFilter($user, 'edit', array_keys($comment->getTaskTransitions()));
        $comments = $this->services->get('ViewHelperManager')->get('comments');
        $posted   = $request->getPost()->toArray();
        $filter->setValidationGroupSafe(array_keys($posted));

        // if the user has selected verify and archive, add the appropriate flag
        if (isset($posted['taskState']) && $posted['taskState'] == Comment::TASK_VERIFIED_ARCHIVE) {
            $posted['addFlags'] = 'closed';
        }

        $filter->setData($posted);
        $isValid      = $filter->isValid();
        $sendComments = null;
        if ($isValid) {
            $old      = $comment->get();
            $filtered = $filter->getValues();
            $silent   = $this->isSilencedNotification($request);

            // add/remove likes and flags are not stored fields
            unset(
                $filtered['addLike'],
                $filtered['removeLike'],
                $filtered['addFlags'],
                $filtered['removeFlags'],
                $filtered[self::ADD_READ_BY],
                $filtered[self::REMOVE_READ_BY]
            );

            $comment->set($filtered);

            // add/remove likes and any flags that the user passed
            $comment
                ->addLike($filter->getValue('addLike'))
                ->removeLike($filter->getValue('removeLike'))
                ->addReadBy($filter->getValue(self::ADD_READ_BY))
                ->removeReadBy($filter->getValue(self::REMOVE_READ_BY))
                ->addFlags($filter->getValue('addFlags'))
                ->removeFlags($filter->getValue('removeFlags'))
                ->set('edited', $isContentEdit ? time() : $comment->get('edited'))
                ->save();

            // for content edits, handle delayed notifications
            // this means we delay email notifications when instructed to do so
            // and collect delayed comments for sending when ending a batch
            $delay        = $this->getIsDelayedNotification($request);
            $sendComments = null;
            if ($silent !== true) {
                $sendComments = $isContentEdit
                    ? $this->handleDelayedComments($comment, $delay)
                    : null;
            }

            // push comment update into queue for further processing
            $queue = $this->services->get('queue');
            $queue->addTask(
                'comment',
                $comment->getId(),
                [
                    'user'         => $user->getId(),
                    'previous'     => $old,
                    'current'      => $comment->get(),
                    // 'quiet' is a little odd it may be set to true to try and silence everything,
                    // or an array with for example the words 'mail' or 'activity' to
                    // try and silence a particular action. We want to silence the mail if it
                    // is being delayed; the activity still must be created so we do not want quiet
                    // to be set to true
                    'quiet'                                        => $delay ? ['mail'] : null,
                    'sendComments'                                 => $sendComments,
                    CommentsController::SILENCE_NOTIFICATION_PARAM => $silent
                ]
            );
        } else {
            $this->getResponse()->setStatusCode(400);
        }

        $data = [
            'isValid'         => $isValid,
            'messages'        => $filter->getMessages(),
            'taskTransitions' => $comment->getTaskTransitions(),
            'comment'         => $comment->get(),
        ];
        if ($isValid && $sendComments) {
            $data['delayedSent'] = sizeof($sendComments);
        }

        if ($request->getPost('bundleTopicComments', true)) {
            $context = isset($posted['context']) && strlen($posted['context'])
                ? Json::decode($posted['context'], Json::TYPE_ARRAY)
                : null;
            $version = $request->getPost('limitVersion') && isset($context['version'])
                ? $context['version']
                : null;

            $data['comments'] = $comments($comment->get('topic'), null, $version);
        }

        return new JsonModel($data);
    }

    /**
     * Return the filter for data to add comments.
     *
     * @param   User            $user           the current authenticated user.
     * @param   string          $mode           one of 'add' or 'edit'
     * @param   array           $transitions    transitions being validated against
     * @return  InputFilter     filter for adding comments data
     */
    protected function getCommentFilter(User $user, $mode, array $transitions = [])
    {
        $ipProtects     = $this->services->get('ip_protects');
        $filter         = new InputFilter;
        $flagValidators = [
            [
                'name'      => '\Application\Validator\IsArray'
            ],
            [
                'name'      => '\Application\Validator\Callback',
                'options'   => [
                    'callback'  => function ($value) {
                        if (in_array(false, array_map('is_string', $value))) {
                            return 'flags must be set as strings';
                        }

                        return true;
                    }
                ]
            ]
        ];
        $userValidator = [
            'name'      => '\Application\Validator\Callback',
            'options'   => [
                'callback'  => function ($value) use ($user) {
                    if ($value !== $user->getId()) {
                        return 'Not logged in as %s';
                    }

                    return true;
                }
            ]
        ];

        // ensure user is provided and refers to the active user
        $filter->add(
            [
                'name'          => 'user',
                'required'      => true,
                'validators'    => [$userValidator]
            ]
        );

        $filter->add(
            [
                'name'      => 'topic',
                'required'  => true
            ]
        );

        $filter->add(
            [
                'name'      => 'context',
                'required'  => false,
                'filters'   => [
                    [
                        'name'      => '\Laminas\Filter\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (is_array($value)) {
                                    return $value;
                                }

                                return $value !== null && strlen($value)
                                    ? Json::decode($value, Json::TYPE_ARRAY)
                                    : null;
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($ipProtects) {
                                // deny if user doesn't have list access to the context file
                                $file = isset($value['file']) ? $value['file'] : null;
                                if ($file && !$ipProtects->filterPaths($file, Protections::MODE_LIST)) {
                                    return "No permission to list the associated file.";
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'attachments',
                'required'      => false,
                'validators'    => [
                    [
                        'name'          => '\Application\Validator\Callback',
                        'options'       => [
                            'callback'  => function ($value) {
                                // allow empty value
                                if (empty($value)) {
                                    return true;
                                }

                                // error on invalid input (e.g., a string)
                                if (!is_array($value)) {
                                    return false;
                                }

                                // ensure all IDs are true integers and correspond to existing attachments
                                foreach ($value as $id) {
                                    if (!ctype_digit((string) $id)) {
                                        return false;
                                    }
                                }

                                if (count(
                                    Attachment::exists($value, $this->services->get('p4_admin'))
                                ) !=
                                    count(
                                        $value
                                    )) {
                                    return "Supplied attachment(s) could not be located on the server";
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'      => 'body',
                'required'  => true
            ]
        );

        $filter->add(
            [
                'name'          => 'flags',
                'required'      => false,
                'validators'    => $flagValidators
            ]
        );

        $filter->add(
            [
                'name'              => 'delayNotification',
                'required'          => false,
                'continue_if_empty' => true,
            ]
        );

        $filter->add(
            [
                'name'       => 'taskState',
                'required'   => false,
                'validators' => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($mode, $transitions) {
                                if (!in_array($value, $transitions, true)) {
                                    return 'Invalid task state transition specified. '
                                         . 'Valid transitions are: ' . implode(', ', $transitions);
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        // in edit mode don't allow user, topic, or context
        // but include virtual add/remove flags and add/remove like fields
        if ($mode == 'edit') {
            $filter->remove('user');
            $filter->remove('topic');
            $filter->remove('context');

            $filter->add(
                [
                    'name'          => 'addFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                ]
            );

            $filter->add(
                [
                    'name'          => 'removeFlags',
                    'required'      => false,
                    'validators'    => $flagValidators
                ]
            );

            $filter->add(
                [
                    'name'          => 'addLike',
                    'required'      => false,
                    'validators'    => [$userValidator]
                ]
            );

            $filter->add(
                [
                    'name'          => 'removeLike',
                    'required'      => false,
                    'validators'    => [$userValidator]
                ]
            );

            $filter->add(
                [
                    'name'          => self::ADD_READ_BY,
                    'required'      => false,
                    'validators'    => [$userValidator]
                ]
            );

            $filter->add(
                [
                    'name'          => self::REMOVE_READ_BY,
                    'required'      => false,
                    'validators'    => [$userValidator]
                ]
            );
        }

        return $filter;
    }

    /**
     * Helper to ensure that the given topic does not refer to a forbidden change
     * or an inaccessible private project.
     *
     * @param  string  $topic      the topic to check change/review access for
     * @throws ForbiddenException  if the topic refers to a change or project the user can't access
     * @throws RecordNotFoundException
     * @throws Exception
     */
    protected function restrictAccess($topic)
    {
        // early exit if the topic is not change related
        if (!preg_match('#(changes|reviews)/([0-9]+)#', $topic, $matches)) {
            return;
        }

        $group = $matches[1];
        $id    = $matches[2];

        // if the topic refers to a review, we need to fetch it to determine the change
        // and whether the review belongs only to private projects
        // if the topic refers to a change, it always uses the original change id, but for
        // the access check we need to make sure we use the latest/renumbered id.
        if ($group === 'reviews') {
            $review = Review::fetch($id, $this->services->get('p4_admin'));
            $change = $review->getHeadChange();
        } else {
            // resolve original number to latest/submitted change number
            // for 12.1+ we can rely on 'p4 change -O', for older servers, try context param
            $p4     = $this->services->get('p4');
            $lookup = $id;
            if (!$p4->isServerMinVersion('2012.1')) {
                $context = $this->getRequest()->getQuery('context', []);
                $lookup  = isset($context['change']) ? $context['change'] : $id;
            }

            try {
                $change = Change::fetchById($lookup, $this->services->get('p4'));
                $change = $id == $change->getOriginalId() ? $change->getId() : false;
            } catch (SpecNotFoundException $e) {
                $change = false;
            }
        }

        if ($change === false
            || !$this->services->get(RestrictedChanges::class)->canAccess($change)
            || (isset($review) && !$this->services->get('projects_filter')->canAccess($review))
        ) {
            throw new ForbiddenException("You don't have permission to access this topic.");
        }
    }

    /**
     * Delay notification for the given comment or collect delayed
     * comments and close the batch if we are sending (delay is false).
     *
     * @param   Comment     $comment    comment to process
     * @param   bool        $delay      delay this comment, false to close the batch
     * @return  array|null  delayed comment data if sending, null otherwise
     */
    protected function handleDelayedComments(Comment $comment, $delay)
    {
        $topic           = $comment->get('topic');
        $userConfig      = $this->services->get('user')->getConfig();
        $delayedComments = $userConfig->getDelayedComments($topic);

        // nothing to do if we are sending but there are no delayed comments
        if (!$delay && !count($delayedComments)) {
            return null;
        }

        // if not already present, add the comment to delayed comments; in the case of an add,
        // the comment batch time should match the time of the first comment - this should avoid
        // later concluding that the comment was created before the batch. Or if the comment is
        // edited then we should update the time in the delayedComments.
        if (!array_key_exists($comment->getId(), $delayedComments) || $comment->get('edited')) {
            $delayedComments[$comment->getId()] = $comment->get('edited')
                ? time()
                : $comment->get('time');
        }

        //make sure that the comment ending the batch has the 'batched' flag set to true
        $comment->set('batched', true)->save();

        $userConfig->setDelayedComments($topic, $delay ? $delayedComments : null)->save();
        return $delay ? null : $delayedComments;
    }

    /**
     * Prepare FetchAll options for searching comments based on a query
     *
     * @param  string       $topic  the topic parameter to be included in options
     * @param  Parameters   $query  query parameters to build options from
     * @return array        the resulting options array
     */
    protected function getFetchAllOptions($topic, Parameters $query)
    {
        $options = [
            Comment::FETCH_AFTER    => $query->get('after'),
            Comment::FETCH_MAXIMUM  => $query->get('max', 50),
            Comment::FETCH_BY_TOPIC => $topic
        ];

        // add filter options.
        // if task states filter is not provided and only tasks are requested then add option to fetch only tasks
        $user           = $query->get('user');
        $taskStates     = $query->get('taskStates');
        $ignoreArchived = $query->get('ignoreArchived');
        $tasksOnly      = $query->get('tasksOnly');

        if (!$taskStates && $tasksOnly && $tasksOnly !== 'false') {
            $taskStates = [
                Comment::TASK_OPEN,
                Comment::TASK_ADDRESSED,
                Comment::TASK_VERIFIED
            ];
        }

        $options += [
            Comment::FETCH_BY_USER         => $user,
            Comment::FETCH_BY_TASK_STATE   => $taskStates,
            Comment::FETCH_IGNORE_ARCHIVED => $ignoreArchived && $ignoreArchived !== 'false'
        ];

        // eliminate blank values to avoid potential side effects
        return array_filter(
            $options,
            function ($value) {
                return is_array($value) ? count($value) : strlen($value);
            }
        );
    }
}
