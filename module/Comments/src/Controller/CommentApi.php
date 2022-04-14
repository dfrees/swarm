<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Api\Exception\ConflictException;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Comments\Filter\IComment as ICommentFilter;
use Comments\Filter\IMarkAsRead;
use Comments\Filter\IParameters;
use Comments\Model\Comment;
use Comments\Model\IComment;
use Comments\Validator\Notify;
use Exception;
use InvalidArgumentException;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Model\Fielded\Iterator;
use P4\Spec\Exception\NotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;

/**
 * Class CommentApi
 * @package Comments\Controller
 */
class CommentApi extends AbstractRestfulController implements ICommentApi
{
    const DATA_COMMENTS = 'comments';

    /**
     * Create a new comment for Swarm
     * @param mixed $data
     * @return  JsonModel
     */
    public function create($data): JsonModel
    {
        $errors     = [];
        $comment    = null;
        $filterData = [];
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $paramsFilter = $this->services->get(IParameters::EDIT_COMMENTS_PARAMETERS_FILTER);
            $bodyFilter   = $this->services->get(ICommentFilter::COMMENTS_CREATE_FILTER);

            $params                       = $this->getRequest()->getQuery()->toArray();
            $params[Notify::NOTIFY_FIELD] = $params[Notify::NOTIFY_FIELD] ?? Notify::DELAYED;
            unset($params[IRequest::FORMAT]);
            if ($params) {
                $paramsFilter->setData($params);
                if ($paramsFilter->isValid()) {
                    $filterData = $paramsFilter->getValues();
                } else {
                    $errors = $paramsFilter->getMessages();
                }
            }

            $bodyFilter->setData($this->prepareDataForFilter($data));
            if ($bodyFilter->isValid()) {
                $filterData = array_merge($filterData, $bodyFilter->getValues());
            } else {
                $errors = array_merge($errors, $bodyFilter->getMessages());
            }

            if ($errors) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            } else {
                $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
                $commentDao = $this->services->get(IModelDAO::COMMENT_DAO);
                $comment    = new Comment($p4Admin);
                $comment->set($filterData);
                $comment = $commentDao->save($comment, $filterData[Notify::NOTIFY_FIELD]);
            }
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (RecordNotFoundException | NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_409);
            $errors = [$this->buildMessage(Response::STATUS_CODE_409, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, 'Unauthorized')];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_COMMENTS => [$comment->toArray()]]);
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function editAction() : JsonModel
    {
        $errors  = [];
        $data    = [];
        $comment = null;
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $request      = $this->getRequest();
            $query        = $request->getQuery();
            $paramsFilter = $this->services->get(IParameters::EDIT_COMMENTS_PARAMETERS_FILTER);
            $bodyFilter   = $this->services->get(ICommentFilter::COMMENTS_EDIT_FILTER);
            $commentId    = $this->getEvent()->getRouteMatch()->getParam(IComment::ID);
            $params       = $query->toArray();
            unset($params[IRequest::FORMAT]);
            if ($params) {
                $paramsFilter->setData($query->toArray());
                if ($paramsFilter->isValid()) {
                    $data = $paramsFilter->getValues();
                } else {
                    $errors = $paramsFilter->getMessages();
                }
            }
            $bodyFilter->setData(json_decode($request->getContent(), true));
            if ($bodyFilter->isValid()) {
                $data = array_merge($data, $bodyFilter->getValues());
            } else {
                $errors = array_merge($errors, $bodyFilter->getMessages());
            }
            if ($errors) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            } else {
                $dao     = $this->services->get(IDao::COMMENT_DAO);
                $comment = $dao->edit($commentId, $data);
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, 'Unauthorized')];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_COMMENTS => [$comment->toArray()]]);
        }
        return $json;
    }

    /**
     * get comment by comment id
     * @param mixed $id
     * @return JsonModel
     */
    public function get($id): JsonModel
    {
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $error    = null;
        $comments = [];
        try {
            $fields       = $this->getRequest()->getQuery(IRequest::FIELDS);
            $dao          = $this->services->get(IModelDAO::COMMENT_DAO);
            $commentsData = $dao->fetch($id, $p4Admin)->toArray();
            $comments     = $this->limitFields($commentsData, $fields);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            // comment id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Comments doesn't have a validator on how a comment ID should be.
            // Leaving this hear in case we do later.
            // comment id not correct form, part of the path so use 404
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_COMMENTS => $comments]);
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function sendNotificationAction(): JsonModel
    {
        $error = null;
        $count = [];
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $topic_id = $this->getEvent()->getRouteMatch()->getParam(ICommentApi::TOPIC_ID);
            $topic    = $this->getEvent()->getRouteMatch()->getParam(IComment::TOPIC);
            $dao      = $this->services->get(IModelDAO::COMMENT_DAO);
            $count    = $dao->sendDelayedComments($topic. '/'.$topic_id);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            // topic and topic_id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $error = $this->buildMessage(Response::STATUS_CODE_401, "Unauthorized");
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([IComment::COUNT => $count]);
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function getCommentsByTopicIdAction(): JsonModel
    {
        $topic        = $this->getEvent()->getRouteMatch()->getParam(IComment::TOPIC);
        $id           = $this->getEvent()->getRouteMatch()->getParam(IComment::ID);
        $comments     = [];
        $commentsData = null;
        $errors       = null;
        $request      = $this->getRequest();
        $query        = $request->getQuery();
        try {
            $filter  = $this->services->get(IParameters::COMMENTS_PARAMETERS_FILTER);
            $options = $query->toArray();
            $filter->setData($options);
            if ($filter->isValid()) {
                $options      = $filter->getValues();
                $fields       = $this->getRequest()->getQuery(IRequest::FIELDS);
                $dao          = $this->services->get(IModelDAO::COMMENT_DAO);
                $commentsData = $dao->fetchByTopic($topic, $id, $options);
                $comments     = $this->limitFieldsForAll($this->modelsToArray($commentsData), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (NotFoundException | RecordNotFoundException | InvalidArgumentException $e) {
            // Record id is either not correctly formed or does not reference a valid record
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_COMMENTS             => $comments,
                AbstractKey::FETCH_TOTAL_COUNT  => $commentsData->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN          => $commentsData->getProperty(AbstractKey::LAST_SEEN),
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function markCommentAsReadOrUnreadAction(): JsonModel
    {
        $readByEvent = $this->getEvent()->getRouteMatch()->getParam(IComment::READ_BY_EVENT);
        $id          = $this->getEvent()->getRouteMatch()->getParam(IComment::ID);
        $data        = null;
        $errors      = null;
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $dao = $this->services->get(IDAO::COMMENT_DAO);
            if ($readByEvent === IComment::READ) {
                $filter = $this->services->get(IMarkAsRead::MARK_AS_READ_UPDATE_FILTER);
                $filter->setData($this->getRequest()->getQuery()->toArray());
                if ($filter->isValid()) {
                    $params = $filter->getValues();
                    $data   = $dao->markCommentAsRead($id, $params);
                } else {
                    $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                    $errors = $filter->getMessages();
                }
            } else {
                $data = $dao->markCommentAsUnread($id);
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (NotFoundException | RecordNotFoundException | InvalidArgumentException $e) {
            // Record id is either not correctly formed or does not reference a valid record
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, "Unauthorized")];
        } catch (ConflictException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_409);
            $errors = [$this->buildMessage(Response::STATUS_CODE_409, $e->getMessage())];
            $data   = [
                self::DATA_COMMENTS => [$e->getData()->toArray()],
            ];
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode(), $data);
        } else {
            $result = [
                self::DATA_COMMENTS => [$data->toArray()],
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Convert an iterator of Comments to an array representation
     * @param Iterator     $comments            iterator of comments
     * @return array
     */
    protected function modelsToArray(Iterator $comments): array
    {
        return array_values($comments->toArray());
    }

    /**
     * Prepare data for filter from route params
     * @param mixed $data
     * @return array
     */
    protected function prepareDataForFilter($data): array
    {
        $routeMatch = $this->getEvent()->getRouteMatch();
        $topic      = $routeMatch->getParam(IComment::TOPIC);
        $topic_id   = $routeMatch->getParam(ICommentApi::TOPIC_ID);
        $comment_id = $routeMatch->getParam(IComment::ID);

        if ($topic && $topic_id) {
            $data[IComment::TOPIC] = $topic . '/' . $topic_id;
        }
        if ($comment_id) {
            $data[IComment::CONTEXT][IComment::COMMENT] = (int) $comment_id;
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function archiveOrUnArchiveAction() : JsonModel
    {
        $error      = null;
        $comments   = [];
        $routeMatch = $this->getEvent()->getRouteMatch();
        $commentId  = $routeMatch->getParam(IComment::ID);
        $archive    = $routeMatch->getParam(IComment::ARCHIVE_OPERATION) === IComment::ACTION_ARCHIVE;
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $commentDao = $this->services->get(IDao::COMMENT_DAO);
            $comments   = $archive
                ? $commentDao->archiveComment($commentId)
                : $commentDao->unArchiveComment($commentId);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $error = $this->buildMessage(Response::STATUS_CODE_401, $e->getMessage());
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_COMMENTS => $comments->toArray()]);
        }
        return $json;
    }
}
