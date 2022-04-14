<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Controller;

use Activity\Model\Activity;
use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\ConfigException;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Filter\FilterException;
use Application\Filter\Preformat;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Module as ApplicationModule;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Application\Permissions\IpProtects;
use Application\Permissions\Permissions;
use Application\Validator\ValidatorException;
use Comments\Model\Comment;
use Comments\Model\IComment;
use Comments\Module as commentsModule;
use ErrorException;
use Exception;
use Files\Archiver;
use InvalidArgumentException;
use P4\Connection\Exception\CommandException;
use P4\Spec\Exception\NotFoundException;
use Queue\Manager;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Exception\Exception as RecordException;
use Record\Key\AbstractKey;
use Reviews\Filter\IAppendReplaceChange;
use Reviews\Filter\IProjectsForUser;
use Reviews\Filter\IVersion;
use Reviews\ITransition;
use Reviews\Model\FileInfo;
use Reviews\Model\FileInfoDAO;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Reviews\Filter\IParticipants;
use Groups\Model\Group;
use Laminas\Filter\Exception\RuntimeException;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Model\Fielded\Iterator;

/**
 * Class ReviewApi
 * @package Reviews\Controller
 */
class ReviewApi extends AbstractRestfulController
{
    const LOG_PREFIX         = ReviewApi::class;
    const DATA_REVIEWS       = 'reviews';
    const VOTE               = 'vote';
    const VERSIONS           = 'versions';
    const TRANSITIONS        = 'transitions';
    const CAN_ARCHIVE        = 'canArchive';
    const CAN_EDIT_REVIEWERS = 'canEditReviewers';
    const ARCHIVE            = 'archive';
    const COMMENTS           = 'comments';
    const DATA_FILES_INFO    = "filesInfo";
    const DATA_FILES         = "files";
    const FILE_OPERATION     = "operation";

    /**
     * Gets a review
     * @param mixed $id The review ID
     * @return mixed|JsonModel
     */
    public function get($id)
    {
        $p4Admin     = $this->services->get(ConnectionFactory::P4_ADMIN);
        $error       = null;
        $reviewArray = [];
        $review      = null;
        try {
            $dao         = $this->services->get(IModelDAO::REVIEW_DAO);
            $review      = $dao->fetch($id, $p4Admin);
            $fields      = $this->getRequest()->getQuery(IRequest::FIELDS);
            $metadata    = $this->getRequest()->getQuery(IRequest::METADATA);
            $reviewArray =
                $review->toArray() +
                [
                    self::VERSIONS => $review->getVersions()
                ];
            $this->addDescriptionMarkdown($reviewArray);
            if ($metadata === "true") {
                $reviewArray[IRequest::METADATA][self::CAN_ARCHIVE]        = $dao->canArchive($review->getHeadChange());
                $reviewArray[IRequest::METADATA][self::CAN_EDIT_REVIEWERS] = $dao->canEditReviewers($review);
            }
            $reviewArray = $this->limitFields($reviewArray, $fields);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Review id not correct form, part of the path so use 404
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_REVIEWS => [$reviewArray]]);
        }
        return $json;
    }

    /**
     * Get archive:
     * This endpoint does three things.
     * 1. If there is no status file for the requested archive, create one.
     * 2. If there is a status file report the current status to the user.
     * 3. If there is a status file and it is 100% and success true, return file.
     *
     * @return int|JsonModel
     * @throws CommandException
     * @throws ConfigException
     */
    public function archiveAction()
    {
        $id         = $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID);
        $p4admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $reviewDAO  = $this->services->get(IModelDAO::REVIEW_DAO);
        $isReview   = $reviewDAO->exists($id, $p4admin);
        $error      = null;
        $cacheDir   = DATA_PATH . '/cache/archives';
        $archiver   = $this->services->get(Services::ARCHIVER);
        $fileName   = '/swarm-review-' . $id ;
        $statusFile = $cacheDir . $fileName. '.status';

        if (!$isReview) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, "Id " . $id . " isn't a Review");
            return $this->error([$error], Response::STATUS_CODE_404);
        }
        ApplicationModule::ensureCacheDirExistAndWritable($cacheDir);
        // set protections on the archiver to filter out files user doesn't have access to
        $archiver->setProtections($this->services->get(IpProtects::IP_PROTECTS));

        if (!$archiver->hasStatus($statusFile)) {
            $archiver->writeStatus($statusFile, ['phase' => 'initializing']);
            try {
                return $this->createArchive($archiver, $statusFile, $fileName, $id, $p4admin, $reviewDAO, $cacheDir);
            } catch (NotFoundException $err) {
                // Review id is good but no record found
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                $error = $this->buildMessage(Response::STATUS_CODE_404, $err->getMessage());
            } catch (InvalidArgumentException $err) {
                // Review id not correct form, part of the path so use 404
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                $error = $this->buildMessage(Response::STATUS_CODE_404, $err->getMessage());
            } catch (ForbiddenException $err) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $error = $this->buildMessage(Response::STATUS_CODE_403, $err->getMessage());
            } catch (RuntimeException  $err) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                $error = $this->buildMessage(Response::STATUS_CODE_404, $err->getMessage());
            }
            if ($error) {
                return $this->error([$error], $this->getResponse()->getStatusCode());
            }
        }
        $status = $archiver->getStatus($statusFile);
        if ($status['progress'] === 100 && $status['success'] === true) {
            return $archiver->getArchive($cacheDir, $fileName);
        }
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_202);
        return $this->success([self::ARCHIVE => $status]);
    }

    /**
     * Create the review Archive file.
     *
     * @param Archiver $archiver   This is the archiver we are going to use
     * @param string   $statusFile The status file location and name.
     * @param string   $fileName   Just the status file name.
     * @param int      $id         The review ID in question.
     * @param string   $cacheDir   The cache directory that will be used.
     * @return JsonModel
     * @throws CommandException
     * @throws ConfigException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function createArchive($archiver, $statusFile, $fileName, $id, $p4admin, $reviewDAO, $cacheDir)
    {
        $changeId = $id;
        $review   = $reviewDAO->fetch($id, $p4admin);
        $changes  = $review->isCommitted() ? $review->getCommits() : $review->getChanges();
        $changeId = array_pop($changes);


        $fileSpec = $archiver->getFilesSpec($changeId, $statusFile);
        if (empty($fileSpec)) {
            throw new NotFoundException("No files within this review.", Response::STATUS_CODE_404);
        }
        // Now we have the files we can return back to the user to say we are building the archive.
        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_201);
        $json = $this->success([self::ARCHIVE => $archiver->getStatus($statusFile)]);
        $response->getHeaders()->addHeaderLine('Content-Type: application/json; charset=utf-8');
        $response->setContent($json->serialize());
        $this->disconnect();
        // Start the building of the archive without the user connection being used.
        $archiver->buildArchive($archiver, $cacheDir, $fileSpec, $fileName);
        // If we have reached her Successfully completed the creation of the archive.
        return $json;
    }

    /**
     * Vote on a review
     * @return JsonModel
     */
    public function voteAction()
    {
        $errors   = null;
        $data     = null;
        $userVote = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $dao      = $this->services->get(IModelDAO::REVIEW_DAO);
            $data     = json_decode($this->getRequest()->getContent(), true);
            $userVote = $dao->vote(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID),
                $this->services->get(ConnectionFactory::USER)->getId(),
                $data
            );
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (FilterException $e) {
            $errors = $e->getMessages();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($userVote);
        }
        return $json;
    }

    /**
     * Change the author of a review
     * @return JsonModel
     */
    public function authorAction()
    {
        $errors = null;
        $data   = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $dao  = $this->services->get(IModelDAO::REVIEW_DAO);
            $data = json_decode($this->getRequest()->getContent(), true);
            $data = $dao->changeAuthor(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID),
                $data
            );
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (RuntimeException $e) {
            $errors = $e->getMessages();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($data);
        }
        return $json;
    }

    /**
     * Obliterate the review
     * @param mixed $id The review ID
     * @return mixed|JsonModel
     * @throws ConfigException
     */
    public function delete($id)
    {
        $translator            = $this->services->get(TranslatorFactory::SERVICE);
        $config                = $this->services->get(ConfigManager::CONFIG);
        $p4User                = $this->services->get(ConnectionFactory::P4_USER);
        $p4Admin               = $this->services->get(ConnectionFactory::P4_ADMIN);
        $logger                = $this->services->get(SwarmLogger::SERVICE);
        $allowAuthorObliterate = ConfigManager::getValue($config, ConfigManager::REVIEWS_ALLOW_AUTHOR_OBLITERATE);
        $user                  = $p4User->getUser();
        $msg                   = [];
        try {
            $dao    = $this->services->get(IModelDAO::REVIEW_DAO);
            $review = $dao->fetch($id, $p4Admin);
            // Keep the review data to return that if complete.
            $reviewData = $review->toArray();
            $author     = $review->get(Review::FIELD_AUTHOR);
            // Now check if the user making the request is Admin or above permissions. The author is allowed to
            // obliterate their own review.
            if (($allowAuthorObliterate && $author === $user) || $p4User->isAdminUser(true)) {
                // Obliterate the review
                try {
                    $review->obliterate();
                } catch (Exception $err) {
                    throw new ErrorException($translator->t('Failed to Obliterate review'));
                }
                $logger->notice(Review::REVIEW_OBLITERATE . "Review " . $id . " has been Obliterated by user ". $user);
                // Now the review is obliterated try removing all the additional data.
                // As we cannot back out of the obliterate at this point, we just try to remove all additional data
                // but not fail the request.
                $this->obliterateReviewRelatedObjects($id);
                return $this->success(
                    [self::DATA_REVIEWS => [$reviewData]],
                    [
                        self::buildMessage(
                            'review-obliterated',
                            $translator->t('The review with id [%s] has been obliterated.', [$id])
                        )
                    ]
                );
            } else {
                throw new ForbiddenException(
                    $translator->t(
                        "Failed to Obliterate the review, you need to have admin privileges or be the review author"
                        . " with 'allow_author_obliterate' set to true."
                    )
                );
            }
        } catch (ForbiddenException $error) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $msg[] = self::buildMessage('forbidden-exception', $error->getMessage());
        } catch (RecordNotFoundException $error) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $msg[] = self::buildMessage('record-not-found', $translator->t("Review [%s] can not be found.", [$id]));
        } catch (ErrorException $error) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $msg[] = self::buildMessage("error-obliterating", $error->getMessage());
        }
        return self::error($msg, $this->getResponse()->getStatusCode());
    }

    /**
     * Obliterate all other data that is linked to the review like:
     * Comments and activity streams.
     *
     * @param  int     $id          This is the Review ID.
     * @throws Exception
     */
    private function obliterateReviewRelatedObjects($id)
    {
        $p4Admin        = $this->services->get(ConnectionFactory::P4_ADMIN);
        $queue          = $this->services->get(Manager::SERVICE);
        $p4User         = $this->services->get(ConnectionFactory::P4_USER);
        $logger         = $this->services->get(SwarmLogger::SERVICE);
        $commentTopic   = 'review/' . $id;
        $activityStream = 'review-' . $id;
        $user           = $p4User->getUser();

        // Delete all the comments
        try {
            $comments = Comment::fetchAll([Comment::FETCH_BY_TOPIC => $commentTopic], $p4Admin);
            foreach ($comments as $comment) {
                // We need to ensure there are no future task for each of these comments.
                $hash = CommentsModule::getFutureCommentNotificationHash(
                    $commentTopic,
                    $comment->get('user')
                );
                $queue->deleteTasksByHash($hash);
                // Now delete the comment related.
                $comment->delete();
            }
            $logger->notice("Review " . $id . " comments have been Obliterated by user " . $user);
        } catch (\Exception $e) {
            $logger->trace(
                Review::REVIEW_OBLITERATE . "Review " . $id . " found an issue with Comments removal "
                . $e->getMessage()
            );
        }

        // Delete all the activity streams
        try {
            $reviewActivity = Activity::fetchAll([Activity::FETCH_BY_STREAM => $activityStream], $p4Admin);
            foreach ($reviewActivity as $activity) {
                $activity->delete();
            }
            $logger->notice("Review " . $id . " activity's have been Obliterated by user ". $user);
        } catch (\Exception $e) {
            $logger->trace(
                Review::REVIEW_OBLITERATE . "Review " . $id . " found an issue with Activity removal "
                . $e->getMessage()
            );
        }
    }

    /**
     * Get a list of allowed transitions
     * @return JsonModel a model conforming to the standard response with data of (for example)
     * [
     *     'needsRevision' => 'Needs Revision',
     *     'needsReview'   => 'Needs Review'
     * ]
     */
    public function transitionsAction()
    {
        $errors      = null;
        $transitions = null;
        try {
            $dao         = $this->services->get(IModelDAO::REVIEW_DAO);
            $transitions = $dao->getTransitions(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID),
                $this->services->get(ConnectionFactory::USER)->getId()
            );
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::TRANSITIONS => $transitions]);
        }
        return $json;
    }

    /**
     * Transition a review. The data in the JSON body must contain a minimum of a transition value. Example of full
     * specification
     *                          {
     *                              "transition" : "approved:commit",
     *                              "jobs"       : ["job000001"],
     *                              "fixStatus"  : "closed",
     *                              "text"       : "text for comment or description change"
     *                              "cleanup"    : true
     *                          }
     * @return JsonModel with state moved to and its label description
     */
    public function transitionAction()
    {
        $errors = null;
        $data   = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $dao  = $this->services->get(IModelDAO::REVIEW_DAO);
            $data = json_decode($this->getRequest()->getContent(), true);
            $data = $dao->transition(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID),
                $this->services->get(ConnectionFactory::USER)->getId(),
                $data
            );
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (ValidatorException $e) {
            $errors = $e->getMessages();
            if (in_array($data[ITransition::TRANSITION], ITransition::ALL_VALID_TRANSITIONS)) {
                // It's a known state but we cannot move to it
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_409);
            } else {
                // We don't recognise the state
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (CommandException $e) {
            // This also handles ConflictException which is a subclass of CommandException
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($data);
        }
        return $json;
    }

    /**
     * Handle the update of a description. You must be authenticated and have access to the review
     * JSON body should be in the form:
     * {
     *     "description" : "new description"
     * }
     * Can optionally specify 'updateOriginalChangelist' (any value) which will attempt to update the original
     * change list description also (only if the editor is the same user and the author of the original change)
     * Success response:
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "description": "Description text",
     *         "description-markdown" : "description converted with markdown"
     *     }
     * }
     * Error response example:
     * {
     *     "error": 400,
     *     "messages": ["Error message"],
     *     "data": {}
     * }
     * @return JsonModel
     */
    public function descriptionAction()
    {
        $errors = null;
        $result = [];
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $dao    = $this->services->get(IModelDAO::REVIEW_DAO);
            $data   = json_decode($this->getRequest()->getContent(), true);
            $result = $dao->setDescription(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID),
                $data
            );
            $this->addDescriptionMarkdown($result);
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($result);
        }
        return $json;
    }

    /**
     * Add participants. You must be authenticated and have access to the review. It will also show vote
     * status of existing user in response.
     * Sample JSON body with all example:
     * {
     *    "participants": {
     *      "groups":
     *          {
     *              "dev": {}, // required none by default here
     *              "qa": {"required": "one"},
     *              "ux": {"required": "all"},
     *              "ops": {"required": "none"}
     *          },
     *      "users":
     *          {
     *              "alice": {"required": "yes"},
     *              "bob": {"required": "no"},
     *              "raj": {} // required no by default here
     *          }
     *      }
     * }
     * Success response:
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "participants": {
     *              "users": {
     *                  "bruno": [], // logged in user
     *                  "alice":  {"required": "yes"},
     *                  "bob": {"required": "no"},
     *                  "raj": {"required": "no"}
     *              },
     *              "groups": {
     *                  "dev": {"required": "none"},
     *                  "qa":  {"required": "one"},
     *                  "ux":  {"required": "all"},
     *                  "ops": {"required": "none"}
     *              }
     *          }
     *     }
     * }
     * Error response example:
     * {
     *     "error": 400,
     *     "messages": ["Error message"],
     *     "data": {}
     * }
     * @return JsonModel
     */
    public function addParticipantsAction()
    {
        return $this->processParticipantsData();
    }

    /**
     * Endpoint for joining a review - similar to adding participants but without the permissions check for editing
     * reviewers
     * @return JsonModel
     * @see ReviewApi::addParticipantsAction()
     */
    public function joinAction()
    {
        return $this->processParticipantsData(true);
    }

    /**
     * Endpoint for leaving a review - similar to deleting participants but without the permissions check for editing
     * reviewers
     * @return JsonModel
     * @see ReviewApi::deleteParticipantsAction()
     */
    public function leaveAction()
    {
        return $this->processParticipantsData(true);
    }

    /**
     * Update participants.You must be authenticated and have access to the review. It will also show vote
     * status of existing user in response.
     * Sample JSON body with example mygroup to not required and testUser to required:
     * {
     *    "participants": {
     *      "groups":
     *          {
     *              "dev": {}, // required none by default here group that need to update to none from required
     *          },
     *      "users":
     *          {
     *              "alice": {"required": "yes"}, user that need to be updated to required from not required(no)
     *          }
     *      }
     * }
     * Success response:
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "participants": {
     *              "users": {
     *                  "bruno": [], // logged in user
     *                  "alice":  {"required": "yes", "vote": {"value": 1, "version": 1, "isStale": false}}
     *              },
     *              "groups": {
     *                  "dev":   {"required": "none"},
     *              }
     *          }
     *     }
     * }
     * Error response example:
     * {
     *     "error": 400,
     *     "messages": ["Error message"],
     *     "data": {}
     * }
     * @return JsonModel
     */
    public function updateParticipantsAction()
    {
        return $this->processParticipantsData();
    }

    /**
     * Delete participants. You must be authenticated and have access to the review. It will also show vote
     * status of existing user in response.
     * Sample JSON body with example mygroup will be deleted
     * {
     *    "participants": {
     *      "groups":
     *          {
     *              "dev": {}, // required none by default here, Group that need to be deleted
     *          }
     *      }
     * }
     * Success response:
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "participants": {
     *              "users": {
     *                  "bruno": [], // logged in user
     *                  "alice":  {"required": "yes", "vote": {"value": 1, "version": 1, "isStale": false}}
     *              }
     *          }
     *     }
     * }
     * Error response example:
     * {
     *     "error": 400,
     *     "messages": ["Error message"],
     *     "data": {}
     * }
     * @return JsonModel
     */
    public function deleteParticipantsAction()
    {
        return $this->processParticipantsData();
    }

    /**
     * Adds a description markdown field to the array based in the description in $data[Review::FIELD_DESCRIPTION]
     * (modifies the array parameter
     * @param array $data the data with the description
     */
    private function addDescriptionMarkdown(array &$data)
    {
        $preFormat                                = new Preformat($this->services, $this->getRequest()->getBaseUrl());
        $data[Review::FIELD_DESCRIPTION_MARKDOWN] = $preFormat->filter($data[Review::FIELD_DESCRIPTION]);
    }

    /**
     * Processes participants data. It converts the participant data into correct format to perform
     * validation and then do add/edit/delete operation on review participants data
     * @param boolean   $canEdit    whether to allow review editing. False by default will result in a permissions
     *                              check which will be bypassed if canEdit is true
     * @return JsonModel
     */
    protected function processParticipantsData($canEdit = false)
    {
        $errors         = null;
        $updateResponse = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $id             = $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID);
            $data           = json_decode($this->getRequest()->getContent(), true);
            $reviewDao      = $this->services->get(IModelDAO::REVIEW_DAO);
            $formattedData  = $reviewDao->convertParticipantData($data);
            $updateResponse = $reviewDao->updateParticipants(
                $id,
                $formattedData,
                $this->getRequest(),
                $canEdit
            );
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (FilterException $e) {
            $errors =  $e->getMessages();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $participantData = $updateResponse[0]->getParticipantsData();
            $response        = $this->prepareParticipantsResponse($participantData);
            $messages        = $updateResponse[1];
            $json            = $this->success($response, $messages);
        }
        return $json;
    }

    /**
     * Prepared the response format for add/update/delete participant api
     * @param $responseData
     * @return mixed
     * Sample Input
     *  {
     *       "participants" : {
     *           "groups" :{
     *               "administrators": {"required":"all"}
     *           }
     *       }
     *   }
     * Sample Output
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "participants": {
     *               "users": {
     *                   "Aruna_Gupta": [],
     *                   "dai": [],
     *                   "ines": {
     *                       "required": "yes",
     *                       "minimumRequired": "yes"
     *                   },
     *                   "WIN\\eve": []
     *               },
     *               "groups": {
     *                   "administrators": {
     *                       "required": "all",
     *                       "minimumRequired": "one"
     *                   },
     *                   "Administrators": [],
     *                   "testers": {
     *                       "required": "all",
     *                       "minimumRequired": "all"
     *                   },
     *                   "WebMasters": []
     *               }
     *           }
     *       }
     *   }
     */
    protected function prepareParticipantsResponse($responseData)
    {
        $response[IParticipants::PARTICIPANTS] = [];
        foreach ($responseData as $key => $value) {
            $name        = $key;
            $type        = IParticipants::USERS;
            $required    = null;
            $minRequired = null;
            if (Group::isGroupName($key)) {
                $type        = IParticipants::GROUPS;
                $name        = Group::getGroupName($key);
                $required    = $this->translateGroupRequired(
                    isset($value[IParticipants::REQUIRED]) ? $value[IParticipants::REQUIRED] : null
                );
                $minRequired = $this->translateGroupRequired(
                    isset($value[Review::FIELD_MINIMUM_REQUIRED]) ? $value[Review::FIELD_MINIMUM_REQUIRED] : null
                );
            } else {
                $required    = $this->translateUserRequired(
                    isset($value[IParticipants::REQUIRED]) ? $value[IParticipants::REQUIRED] : null
                );
                $minRequired = $this->translateUserRequired(
                    isset($value[Review::FIELD_MINIMUM_REQUIRED]) ? $value[Review::FIELD_MINIMUM_REQUIRED] : null
                );
            }
            $response[IParticipants::PARTICIPANTS][$type][$name] = [];
            if ($required !== null) {
                $response[IParticipants::PARTICIPANTS][$type][$name][IParticipants::REQUIRED] = $required;
            }
            if ($minRequired !== null) {
                $response[IParticipants::PARTICIPANTS][$type][$name][Review::FIELD_MINIMUM_REQUIRED] = $minRequired;
            }
            if (isset($value['vote'])) {
                $response[IParticipants::PARTICIPANTS][$type][$name]['vote'] = $value['vote'];
            }
        }
        return $response;
    }

    /**
     * Translate participantsData format for user requirement into the correct format for participants
     * @param mixed $value  value
     * @return string|null  "yes" for true, "no" for false, "0" for "0", null for all others
     */
    private function translateUserRequired($value)
    {
        $required = null;
        if ($value === true) {
            $required = IParticipants::YES;
        } elseif ($value === false) {
            $required = IParticipants::NO;
        } elseif ($value === "0") {
            $required = $value;
        }
        return $required;
    }

    /**
     * Translate participantsData format for group requirement into the correct format for participants
     * @param mixed $value  value
     * @return string|null  "one" for "1", "all" for true, "0" for "0", null for all others
     */
    private function translateGroupRequired($value)
    {
        $required = null;
        if ($value === '1') {
            $required = IParticipants::ONE;
        } elseif ($value === true) {
            $required = IParticipants::ALL;
        } elseif ($value === "0") {
            $required = $value;
        }
        return $required;
    }

    /**
     * End point to get a list of files and there change status based on the review and any 'from' and 'to' values
     * Gets file changes based on a review and the 'from' and 'to' parameters. Caller must have access to the review.
     * Example success response:
     *
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "root": "//jam/main/src",
     *           "files": [
     *               {
     *                   "depotFile": "//jam/main/src/execvms.c",
     *                   "action": "edit",
     *                   "type": "text",
     *                   "rev": "3",
     *                   "fileSize": "3343",
     *                   "digest": "82D5161601D12DB46F184D3F0778A16D"
     *               },
     *               {
     *                   "depotFile": "//jam/main/src/jam.h",
     *                   "action": "add",
     *                   "type": "text",
     *                   "rev": "2",
     *                   "fileSize": "7364",
     *                   "digest": "2E94B379F201AD02CF7E8EE33FB7DA99"
     *               }
     *           ]
     *       }
     *   }
     *
     * Example error response (invalid version numbers)
     *
     *  {
     *       "error": 400,
     *       "messages": {
     *           "from": {
     *               "invalidVersion": "Must be an integer between revision [-1] and head [5] inclusively"
     *           },
     *           "to": {
     *               "invalidVersion": "Must be an integer between revision [1] and head [5] inclusively"
     *           }
     *       },
     *       "data": null
     *   }
     * @return JsonModel
     */
    public function fileChangesAction()
    {
        $errors      = null;
        $fileChanges = [];
        try {
            $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
            $dao       = $this->services->get(IModelDAO::REVIEW_DAO);
            $review    = $dao->fetch($this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID), $p4Admin);
            $changeDao = $this->services->get(IModelDAO::CHANGE_DAO);
            // If review versions do not yet exist (perhaps due to workers) get the files from the head change
            // rather than
            // the version information
            if ($review->getVersions()) {
                $versions = $this->getVersions($review);
                $from     = ($versions[IVersion::FROM] && $versions[IVersion::FROM] != -1)
                            ? $review->getChangeOfVersion($versions[IVersion::FROM])
                            : null;
                $to       = $review->getChangeOfVersion($versions[IVersion::TO]);
            } else {
                $from = null;
                $to   = $review->getHeadChange();
            }
            $fileChanges = $changeDao->getFileChanges($from, $to);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (FilterException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = $e->getMessages();
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (RecordException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    IRequest::ROOT    => $fileChanges[IRequest::ROOT],
                    IRequest::FILES   => array_values($fileChanges[IRequest::FILE_CHANGES]),
                    IRequest::LIMITED => $fileChanges[IRequest::LIMITED]
                ]
            );
        }
        return $json;
    }

    /**
     * Gets version information from the query parameters with appropriate defaults
     * @param mixed     $review     the review
     * @return array containing from and to values to use
     * @throws FilterException
     */
    private function getVersions($review) : array
    {
        $headVersion = $review->getHeadVersion();
        $from        = $this->getRequest()->getQuery(IVersion::FROM) ?? 0;
        $to          = $this->getRequest()->getQuery(IVersion::TO)   ?? $headVersion;
        // Set up the filter so that 'from' must range between -1 and head, 'to' must be between 0 and head, 'from'
        // should be less than or equal to to
        $versionFilter = $this->services->build(
            IVersion::VERSION_FILTER, [IVersion::MAX_VERSION => $headVersion, IVersion::MAX_FROM => $to]
        );
        $versionFilter->setData([IVersion::FROM => $from, IVersion::TO => $to]);
        if (!$versionFilter->isValid()) {
            throw new FilterException($versionFilter);
        }
        return $versionFilter->getValues();
    }

    /**
     * Gets a review comments
     * @return mixed|JsonModel
     */
    public function getCommentsAction()
    {
        $error    = null;
        $comments = null;
        try {
            $commentDAO = $this->services->get(IModelDAO::COMMENT_DAO);
            $reviewId   = $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID);
            $comments   = $commentDAO->fetchByTopic(IComment::TOPIC_REVIEWS, $reviewId);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Review id not correct form, part of the path so use 404
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::COMMENTS                 => array_values($comments->toArray()),
                AbstractKey::FETCH_TOTAL_COUNT => $comments->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN         => $comments->getProperty(AbstractKey::LAST_SEEN),
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Gets all reviews data according to logged in user and return all reviews that the
     * user has permission for based on restricted changes and project visibility
     * Example success response
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "reviews": [
     *          {
     *              "id": 12610,
     *              "type": "default",
     *              "changes": [
     *                12609,
     *              ],
     *              "commits": [],
     *              "author": "newuser",
     *              "approvals": null,
     *              "participants": ["newuser"],
     *              "participantsData": {"newuser": []},
     *              "hasReviewer": 0,
     *              "description": "Review for NewUser",
     *              "created": 1594265070,
     *              "updated": 1594266228,
     *              "projects": {"mynewproject-for-newuser": ["main"]},
     *              "state": "needsReview",
     *              "stateLabel": "Needs Review",
     *              "testStatus": "fail",
     *              "testDetails": [],
     *              "deployStatus": null,
     *              "deployDetails": [],
     *              "pending": true,
     *              "commitStatus": [],
     *              "groups": [],
     *              "created": "1594265070",
     *              "updated": "1594266228"
     *          },
     *          ...
     *          ...
     *         ]
     *    }
     * }
     *
     * Query parameters supported:
     *  state - filter by state
     *  project - filter by project(s)
     *  metadata - include a metadata field for each review containing extra information for ease of use
     *      "metadata": {
     *          "comments": [ summary count of open/closed comments ],
     *          "upVotes": [ users voted up ],
     *          "downVotes": [ users voted down ]
     *      }
     *
     *
     * Example error response
     *
     * Unauthorized response 401, if require_login is true
     * {
     *   "error": "Unauthorized"
     * }
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @return mixed|JsonModel
     */
    public function getList()
    {
        $p4Admin      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $errors       = null;
        $reviewsArray = [];
        $reviews      = null;
        $request      = $this->getRequest();
        $query        = $request->getQuery();
        try {
            $filter  = $this->services->get(Services::GET_REVIEWS_FILTER);
            $options = $query->toArray();
            // Convert 'projects-for-user:<userId>' into projects if found. We do not want to embed this in the
            // GET_REVIEWS_FILTER filter chain for the 'project' input as that will mean it will be executed twice
            // with calls to $filter->isValid followed by $filter->getValues and it could be a reasonably expensive
            // call
            if (isset($options[IReview::FETCH_BY_PROJECT])) {
                $projectsForUser                    = $this->services->get(Services::PROJECTS_FOR_USER);
                $options[IReview::FETCH_BY_PROJECT] = $projectsForUser->filter($options[IReview::FETCH_BY_PROJECT]);
            }
            $filter->setData($options);
            if ($filter->isValid()) {
                $options      = $filter->getValues();
                $dao          = $this->services->get(IModelDAO::REVIEW_DAO);
                $fields       = $query->get(IRequest::FIELDS);
                $reviews      = $dao->fetchAll($options, $p4Admin);
                $reviewsArray = $this->limitFieldsForAll($this->modelsToArray($reviews, $options), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_REVIEWS              => $reviewsArray,
                AbstractKey::FETCH_TOTAL_COUNT  => $reviews->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN          => $reviews->getProperty(AbstractKey::LAST_SEEN),
            ];
            if ($reviews->hasProperty(AbstractKey::FETCH_AFTER_UPDATED)) {
                $result[AbstractKey::FETCH_AFTER_UPDATED] = $reviews->getProperty(AbstractKey::FETCH_AFTER_UPDATED);
            }
            $json = $this->success($result);
        }
        return $json;
    }

    /**
     * Convert an iterator of reviews to an array representation merging in any required metadata
     * @param Iterator      $reviews            iterator of reviews
     * @param array         $options            options for merging arrays. Supports IRequest::METADATA to merge in
     *                                          metadata
     * @param array         $metadataOptions    options for metadata. Supports:
     *      IReview::FIELD_COMMENTS     summary of open closed counts
     *      IReview::FIELD_UP_VOTES     up vote user ids
     *      IReview::FIELD_DOWN_VOTES   down vote user ids
     * @return array
     */
    public function modelsToArray($reviews, $options, $metadataOptions = [])
    {
        $reviewsArray = [];
        if (isset($options) && isset($options[IRequest::METADATA]) && $options[IRequest::METADATA] === true) {
            $metadataOptions += [
                IReview::FIELD_COMMENTS => true,
                IReview::FIELD_UP_VOTES => true,
                IReview::FIELD_DOWN_VOTES => true
            ];
            $dao              = $this->services->get(IModelDAO::REVIEW_DAO);
            $metadata         = $dao->fetchAllMetadata($reviews, $metadataOptions);
            if ($metadata) {
                $count = 0;
                foreach ($reviews as $review) {
                    $reviewsArray[] = array_merge($review->toArray(), $metadata[$count++]);
                }
            }
        } else {
            $reviewsArray = $reviews->toArray();
        }
        return array_values($reviewsArray);
    }

    /**
     * Refresh the project associations for the current Review, using the
     *
     * @return JsonModel
     */
    public function refreshProjectsAction()
    {
        $errors = null;
        $review = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $dao    = $this->services->get(IModelDAO::REVIEW_DAO);
            $review = $dao->refreshReviewProjects(
                $this->getEvent()->getRouteMatch()->getParam(Review::FIELD_ID)
            )->toArray();
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (FilterException $e) {
            $errors = $e->getMessages();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (InvalidArgumentException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($review);
        }
        return $json;
    }

    /**
     * Gets data for reviews which are currently needing attention from the current user.
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "reviews": [
     *          {
     *              "id": 12610,
     *              "type": "default",
     *              "changes": [
     *                12609,
     *              ],
     *              "commits": [],
     *              "author": "newuser",
     *              "authorFullName": "newuser (Mr N User)",
     *              "approvals": null,
     *              "participants": ["newuser"],
     *              "participantsData": {"newuser": []},
     *              "hasReviewer": 0,
     *              "description": "Review for NewUser",
     *              "created": 1594265070,
     *              "updated": 1594266228,
     *              "projects": {"mynewproject-for-newuser": ["main"]},
     *              "state": "needsReview",
     *              "stateLabel": "Needs Review",
     *              "testStatus": "fail",
     *              "testDetails": [],
     *              "deployStatus": null,
     *              "deployDetails": [],
     *              "pending": true,
     *              "commitStatus": [],
     *              "groups": [],
     *              "created": "1594265070",
     *              "updated": "1594266228",
     *              "roles" : [
     *                "reviewer"
     *              ],
     *          },
     *          ...
     *          "projectsForUser" : [
     *            "project1"
     *         ]
     *    }
     * }
     *
     * In addition to standard properties such as 'lastSeen' and 'totalCount' an extra 'projectsForUser'
     * value is returned. This is an array of project id values for which the user is either an owner,
     * member, or moderator
     *
     * No query parameters are currently supported.
     *
     * Example error response
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @return mixed|JsonModel
     */
    public function dashboardAction()
    {
        $services = $this->services;
        try {
            $dao     = $services->get(IModelDAO::REVIEW_DAO);
            $reviews = $dao->fetchDashboard(
                [AbstractKey::FETCH_TOTAL_COUNT => true],
                $services->get(ConnectionFactory::P4_ADMIN)
            );

            $options[IRequest::METADATA] = (bool) $this->getRequest()->getQuery(IRequest::METADATA, false);
            return $this->success(
                [
                    self::DATA_REVIEWS => $this->modelsToArray($reviews, $options),
                    AbstractKey::FETCH_TOTAL_COUNT => $reviews->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                    AbstractKey::LAST_SEEN => $reviews->getProperty(AbstractKey::LAST_SEEN),
                    IProjectsForUser::PROJECTS_FOR_USER_VALUE =>
                        $reviews->getProperty(IProjectsForUser::PROJECTS_FOR_USER_VALUE)
                ]
            );
        } catch (ServiceNotCreatedException $snce) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_401);
            return $this->error(
                [$this->buildMessage($response->getStatusCode(), $response->getReasonPhrase())],
                $response->getStatusCode()
            );
        } catch (Exception $e) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_500);
            return $this->error(
                [$this->buildMessage($response->getStatusCode(), $e->getMessage())],
                $response->getStatusCode()
            );
        }
    }

    /**
     * Gets all the review files readBy information for logged-in user
     * Success response:
     * {
     *      "error": null,
     *      "messages": [],
     *      "data": {
     *          "filesInfo": [
     *              {
     *                  "review": 12452,
     *                  "depotFile": "//jam/rel2.1/src/filesys.h",
     *                  "readBy": {
     *                      "bruno": {
     *                          "version": 2,
     *                          "digest": "E8FDEE21B821BEFF45E1DBDA18623EC6"
     *                      }
     *                  }
     *              },
     *          .....
     *          ]
     *      }
     * }
     * Error response example:
     * {
     *     "error": 401,
     *     "messages": [
     *          {
     *              "code": 401,
     *              "text": "Unauthorized"
     *          },
     *      ]
     *     "data": null
     * }
     * @return JsonModel
     */
    public function getFilesReadByAction(): JsonModel
    {
        $errors = [];
        $data   = [];
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $route      = $this->getEvent()->getRouteMatch();
            $reviewId   = $route->getParam(IReview::FIELD_ID);
            $dao        = $this->services->get(IDao::FILE_INFO_DAO);
            $daoOptions = [FileInfoDAO::FETCH_BY_REVIEW => $reviewId];
            $data       = $dao->fetchAll($daoOptions);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (NotFoundException | RecordNotFoundException | InvalidArgumentException  $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, "Unauthorized")];
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    self::DATA_FILES_INFO => $this->limitFieldsForAll(
                        $this->modelsToArray($data, []), [
                            FileInfo::FETCH_BY_REVIEW, FileInfo::DEPOT_FILE, FileInfo::READ_BY
                        ]
                    )
                ]
            );
        }
        return $json;
    }

    /**
     * Sets a file as either read or unread for the current user on an individual review version.
     * JSON body should be in the form:
     * {
     *     "version" : 2,
     *     "path": "//dir/dir/file.type"
     * }
     * Success response:
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *          "files": {
     *              "version": 2,
     *              "path": "//dir/dir/file.type"
     *          }
     *     }
     * }
     * Error response example:
     * {
     *     "error": 400,
     *          "messages": {
     *              "version": {
     *                  "notBetween": "The input is not an integer between '0' and '9223372036854775807', inclusively"
     *                  }
     *          },
     *     "data": null
     * }
     * @return JsonModel
     */
    public function markFileAsReadOrUnreadAction(): JsonModel
    {
        $errors = [];
        $data   = [];
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $filter        = $this->services->get(Services::FILE_READ_UNREAD_FILTER);
            $route         = $this->getEvent()->getRouteMatch();
            $request       = $this->getRequest();
            $reviewId      = $route->getParam(IReview::FIELD_ID);
            $fileOperation = $route->getParam(self::FILE_OPERATION);
            $bodyData      = json_decode($request->getContent(), true);
            $filter->setData($bodyData);
            if ($filter->isValid()) {
                $dao        = $this->services->get(IDao::FILE_INFO_DAO);
                $daoOptions = [
                        IReview::FIELD_ID => $reviewId,
                        $fileOperation => true,
                    ] + $bodyData;
                $data       = $dao->markReviewFileReadOrUnRead($daoOptions);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (NotFoundException | RecordNotFoundException | InvalidArgumentException  $e) {
            // One of review/version/file does not exist
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, "Unauthorized")];
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_FILES => $data]);
        }
        return $json;
    }

    /**
     * Append a change to a review
     * @return JsonModel
     */
    public function appendChangeAction() : JsonModel
    {
        return $this->handleAppendReplaceChange(IAppendReplaceChange::MODE_APPEND);
    }

    /**
     * Replace a review with a change
     * @return JsonModel
     */
    public function replaceWithChangeAction() : JsonModel
    {
        return $this->handleAppendReplaceChange(IAppendReplaceChange::MODE_REPLACE);
    }

    /**
     * Handle a call for append/replace a change to a review by passing on responsibility to the review DAO. On success
     * returns a reponse with the complete review with any updates made.
     * @param string    $mode   'append' or 'replace'
     * @return JsonModel the updated review
     */
    private function handleAppendReplaceChange(string $mode) : JsonModel
    {
        $errors = [];
        $review = null;
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $request  = $this->getRequest();
            $logger   = $this->services->get(SwarmLogger::SERVICE);
            $filter   = $this->services->get(IAppendReplaceChange::FILTER);
            $bodyData = json_decode($request->getContent(), true);
            $filter->setData($bodyData);
            if ($filter->isValid()) {
                $values   = $filter->getValues();
                $dao      = $this->services->get(IDao::REVIEW_DAO);
                $reviewId = $this->getEvent()->getRouteMatch()->getParam(IReview::FIELD_ID);
                $changeId = $values[IAppendReplaceChange::CHANGE_ID];
                $logger->debug(
                    sprintf(
                        "%s: Action [%s], change id [%s], review id [%s]",
                        self::LOG_PREFIX,
                        $mode,
                        $changeId,
                        $reviewId
                    )
                );
                // The filter may return 'false' when pending was not provided, so we only use the value
                // if it was set in the original body data.
                $pending = isset($bodyData[IAppendReplaceChange::PENDING])
                    ? $values[IAppendReplaceChange::PENDING]
                    : null;
                $review  = $mode === IAppendReplaceChange::MODE_APPEND
                    ? $dao->appendChange($changeId, $reviewId, $pending)
                    : $dao->replaceWithChange($changeId, $reviewId, $pending);

                $reviewArray =
                    $review->toArray() +
                    [
                        self::VERSIONS => $review->getVersions()
                    ];
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, "Unauthorized")];
        } catch (NotFoundException | RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_REVIEWS => [$reviewArray]]);
        }
        return $json;
    }
}
