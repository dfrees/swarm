<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Changes\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Filter\FilterException;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Changes\Filter\IChange;
use InvalidArgumentException;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Connection\Exception\CommandException;
use P4\Model\Fielded\Iterator;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\Exception as RecordException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Exception;
use Jobs\Controller\JobTrait;

/**
 * Class ChangeApi
 * @package Changes\Controller
 */
class ChangeApi extends AbstractRestfulController
{
    use JobTrait;
    const DATA_FILES   = 'files';
    const DATA_JOBS    = 'jobs';
    const DATA_CHANGES = 'changes';

    /**
     * Gets a set of changelists, limited to 50
     * TODO: query parameters, filters, restrictions
     *
     * Example response
     *
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "changes": [
     *             {
     *                 "id": 12695,
     *                 "date": "2021/05/14 13:57:49",
     *                 "client": "swarm-77ca5bcd-6144-e4a1-1fd8-d25cd8a97e22",
     *                 "user": "mei",
     *                 "status": "pending",
     *                 "type": "public",
     *                 "importedBy": null,
     *                 "identity": null,
     *                 "description": "Modified through swarm. #review-12696\n",
     *                 "jobStatus": null,
     *                 "jobs": [],
     *                 "stream": null,
     *                 "files": []
     *             },
     *             {
     *                 "id": 12694,
     *                 "date": "2021/04/28 14:57:00",
     *                 "client": "swarm-ab83ce5b-aa81-5192-c9ea-f270425c625e",
     *                 "user": "bruno",
     *                 "status": "submitted",
     *                 "type": "public",
     *                 "importedBy": null,
     *                 "identity": null,
     *                 "description": "Modified through swarm. #review in the middle of the line\n",
     *                 "jobStatus": null,
     *                 "jobs": [],
     *                 "stream": null,
     *                 "files": [
     *                 "//jam/rel2.2/src/execmac.c#2"
     *                 ]
     *            },
     *            {
     *                 "id": 12693,
     *                 "date": "2021/04/26 16:17:19",
     *                 "client": "swarm-5556fac1-1a2c-3f4d-1512-e176f1b8035f",
     *                 "user": "bruno",
     *                 "status": "submitted",
     *                 "type": "public",
     *                 "importedBy": null,
     *                 "identity": null,
     *                 "description": "Implementing custom TaskReadViews for tablet, desktop, and mobile.\n",
     *                 "jobStatus": null,
     *                 "jobs": [],
     *                 "stream": null,
     *                 "files": [
     *                     "//depot/Jam/MAIN/src/make1.c#25"
     *                 ]
     *             }
     *         ]
     *     }
     * }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return JsonModel
     */
    public function getList()
    {
        $errors  = null;
        $changes = [];
        try {
            $services  = $this->services;
            $p4Admin   = $services->get(ConnectionFactory::P4_ADMIN);
            $changeDao = $services->get(IDao::CHANGE_DAO);
            $filter    = $this->services->get(IChange::GET_CHANGES_FILTER);
            $request   = $this->request;
            $query     = $request->getQuery();
            $filter->setData($query->toArray());
            if ($filter->isValid()) {
                $changes = $changeDao->fetchAll($this->normaliseDaoOptions($filter->getValues()), $p4Admin);
            } else {
                $errors = $filter->getMessages();
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (FilterException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = $e->getMessages();
        } catch (CommandException $e) {
            // If a non-existent depot was referenced, a confusing client message will be provided
            if (preg_match('/Command failed.*- must .* client/', $e->getMessage())) {
                $changes = new Iterator();
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors !== null) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    self::DATA_CHANGES    => $this->specsToArray($changes)
                ]
            );
        }
        return $json;
    }

    /**
     * Gets the files for a change.
     *
     * Example response:
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "files": [
     *               {
     *                   "depotFile": "//gwt-streams/experimental/build.xml",
     *                   "action": "branch",
     *                   "type": "text",
     *                   "rev": "1",
     *                   "fileSize": "210",
     *                   "digest": "34E54D2E1A6CE7A67FE47AF0CAF3D8AA"
     *               },
     *               ...
     *               ...
     *           ]
     *       }
     *   }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return  JsonModel
     */
    public function filesAction() : JsonModel
    {
        $errors      = null;
        $fileChanges = [];
        try {
            $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
            $id        = $this->getEvent()->getRouteMatch()->getParam('id');
            $changeDao = $this->services->get(IDao::CHANGE_DAO);
            $filter    = $this->services->get(IChange::GET_FILES_FILTER);
            $request   = $this->request;
            $query     = $request->getQuery();
            $filter->setData($query->toArray());
            if ($filter->isValid()) {
                $values      = $filter->getValues();
                $fileChanges = $changeDao->getFileChanges(
                    $values[IChange::FROM_CHANGE_ID],
                    $id,
                    $p4Admin
                );
            } else {
                $errors = $filter->getMessages();
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (FilterException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = $e->getMessages();
        } catch (RecordNotFoundException | RecordException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors !== null) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    IRequest::ROOT    => $fileChanges[IRequest::ROOT],
                    IRequest::FILES   => isset($fileChanges[IRequest::FILE_CHANGES])
                        ? array_values($fileChanges[IRequest::FILE_CHANGES])
                        : [],
                    IRequest::LIMITED => $fileChanges[IRequest::LIMITED]
                ]
            );
        }
        return $json;
    }

    /**
     * Get the jobs for a change.
     *
     * Example response:
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "jobs": [
     *               {
     *                   "job": "job000020",
     *                   "link": "/jobs/job000020",
     *                   "fixStatus": "open",
     *                   "description": "Need Project files\n",
     *                   "descriptionMarkdown": "<span class=\"first-line\">Need Project files</span>"
     *              },
     *              ...
     *              ...
     *       ]
     *   }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return JsonModel
     */
    public function jobsAction()
    {
        $error = null;
        $jobs  = [];
        try {
            $jobs = $this->getJobs($this->getEvent()->getRouteMatch()->getParam('id'));
        } catch (ForbiddenException $e) {
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (SpecNotFoundException $e) {
            // Change id is good but no spec found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Change id not correct form, part of the path so use 404
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_JOBS => $jobs]);
        }
        return $json;
    }

    /**
     * Get the jobs from a change and process the descriptions
     * @param mixed $changeId the change id
     * @return mixed
     */
    private function getJobs($changeId)
    {
        $p4        = $this->services->get(ConnectionFactory::P4);
        $changeDao = $this->services->get(IDao::CHANGE_DAO);
        $change    = $changeDao->fetchById($changeId, $p4);
        $jobDao    = $this->services->get(IDao::JOB_DAO);
        $jobs      = $jobDao->getJobs($change);
        return $this->filterDescriptions($jobs);
    }

    /**
     * Convert an iterator of Change specs to an api compatible array representation
     * @param Iterator     $changes            iterator of changes
     * @return array
     */
    protected function specsToArray(Iterator $changes): array
    {
        $changeArray = [];
        foreach ($changes as $changeModel) {
            $change = [];
            foreach ($changeModel->getFields() as $fieldName) {
                $change[lcfirst($fieldName === Change::ID_FIELD?'id':$fieldName)] = $changeModel->get($fieldName);
            }
            $changeArray[] = $change;
        }
        return $changeArray;
    }

    /**
     * Build a set of dao options from the query parameters provided
     * @param array $values
     * @return mixed[]
     */
    protected function normaliseDaoOptions(array $values) : array
    {
        $options = [Change::FETCH_MAXIMUM => 50 ];
        foreach ($values as $option => $value) {
            switch ($option) {
                case IChange::PENDING:
                    if ($value !== null) {
                        $options[Change::FETCH_BY_STATUS] = $value === true ? "pending" : "submitted";
                    }
                    break;
                case IChange::ROOT_PATH:
                    if ($value) {
                        $depotPath = rtrim(base64_decode($value), '/\.');
                        // Make sure that depot path is at least //...
                        $options[Change::FETCH_BY_FILESPEC] = ($depotPath === "" ? '/' : $depotPath).'/...';
                    }
                    break;
                case IChange::USER:
                    if ($value) {
                        $options[Change::FETCH_BY_USER] = $value;
                    }
                    break;
                default:
                    break;
            }
        }
        return $options;
    }

    /**
     * Add or remove a job from the change depending on the mode
     * @param string $mode either 'add' to add a job or 'remove' to remove a job
     * @return JsonModel
     */
    private function addRemoveJobAction(string $mode): JsonModel
    {
        $error     = null;
        $jobs      = [];
        $changeId  = $this->getEvent()->getRouteMatch()->getParam('id');
        $jobId     = $this->getEvent()->getRouteMatch()->getParam('jobid');
        $changeDao = $this->services->get(IDao::CHANGE_DAO);
        try {
            $checker = $this->services->get(Services::CONFIG_CHECK);
            $checker->check(IPermissions::AUTHENTICATED_CHECKER);
            $mode === 'remove' ? $changeDao->removeJob($changeId, $jobId) : $changeDao->addJob($changeId, $jobId);
            $jobs = $this->getJobs($changeId);
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $error = $this->buildMessage(Response::STATUS_CODE_401, "Unauthorized");
        } catch (ForbiddenException $e) {
            $error = $this->buildMessage(Response::STATUS_CODE_403, $e->getMessage());
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (SpecNotFoundException $e) {
            // Change id is good but no spec found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_JOBS => $jobs]);
        }
        return $json;
    }

    /**
     * Add a job to a change. The change and job must exist and the caller must be authenticated. Returns all the jobs
     * on the change
     * Example response:
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "jobs": [
     *               {
     *                   "job": "job000020",
     *                   "link": "/jobs/job000020",
     *                   "fixStatus": "open",
     *                   "description": "Need Project files\n",
     *                   "descriptionMarkdown": "<span class=\"first-line\">Need Project files</span>"
     *              },
     *              ...
     *              ...
     *       ]
     *   }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return JsonModel
     */
    public function addJobAction(): JsonModel
    {
        return $this->addRemoveJobAction("add");
    }

    /**
     * Remove a job from a change. The change and job must exist and the caller must be authenticated. Returns all the
     * jobs on the change
     * Example response:
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "jobs": [
     *               {
     *                   "job": "job000020",
     *                   "link": "/jobs/job000020",
     *                   "fixStatus": "open",
     *                   "description": "Need Project files\n",
     *                   "descriptionMarkdown": "<span class=\"first-line\">Need Project files</span>"
     *              },
     *              ...
     *              ...
     *       ]
     *   }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return JsonModel
     */
    public function removeJobAction(): JsonModel
    {
        return $this->addRemoveJobAction("remove");
    }
}
