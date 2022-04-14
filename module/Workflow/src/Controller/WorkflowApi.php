<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Events\Listener\ListenerFactory;
use Exception;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Queue\Manager;
use Record\Exception\NotFoundException;
use Record\Key\AbstractKey;
use Workflow\Model\IWorkflow;
use Workflow\Model\Workflow;
use Application\Log\SwarmLogger;

/**
 * Class WorkflowApi.
 * @package Workflow\Controller
 */
class WorkflowApi extends AbstractRestfulController
{
    /**
     * Create a new workflow
     * Example: POST http://<host>>/api/<version>/workflows
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "workflows": [
     *                 {
     *                   "id": 0
     *                   "name": "Workflow name",
     *                   "description": null,
     *                   "shared": false,
     *                   "owners": [
     *                       "swarm"
     *                   ],
     *                   "on_submit": {
     *                       "with_review": {
     *                           "rule": "strict",
     *                           "mode": "policy"
     *                       },
     *                       "without_review": {
     *                           "rule": "reject",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "end_rules": {
     *                       "update": {
     *                           "rule": "no_checking",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "auto_approve": {
     *                       "rule": "never",
     *                       "mode": "default"
     *                   },
     *                   "counted_votes": {
     *                       "rule": "anyone",
     *                       "mode": "default"
     *                   },
     *                   "group_exclusions": [],
     *                   "user_exclusions": []
     *               }
     *           ]
     *       }
     * }
     * If workflows are not enabled in configuration the response is:
     *
     * 501:
     * {
     *       "error": 501,
     *       "messages": [
     *          {
     *              "code": 501
     *              "text": "Workflows are not enabled"
     *          }
     *       ],
     *       "data": null
     * }
     *
     * For errors in the provided data (for example)
     * 400:
     * {
     *       "error": 400,
     *       "messages": {
     *           "name" : {
     *               "isEmpty": "Value is required and can't be empty"
     *           }
     *       },
     *       "data": null
     * }
     * Unauthorized
     * {
     *       "error": 401,
     *       "messages": [
     *           {
     *               "code": 401,
     *               "text": "Unauthorized"
     *           }
     *       ],
     *       "data": null
     *  }
     * @param mixed         $data       data for create
     * @return JsonModel|mixed
     */
    public function create($data)
    {
        $errors   = null;
        $services = $this->services;
        $checker  = $services->get(Services::CONFIG_CHECK);
        $workflow = null;
        try {
            $checker->checkAll([IPermissions::AUTHENTICATED_CHECKER, IWorkflow::WORKFLOW_CHECKER]);
            $filter = $services->get(Services::WORKFLOW_FILTER);
            // fix the test to start at 0, reindexing.
            $data[IWorkflow::TESTS] = array_values($data[IWorkflow::TESTS]);
            $this->defaultValues($data);
            $filter->setData($data);
            if ($filter->isValid()) {
                $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
                $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
                $workflow = new Workflow($p4Admin);
                $data     = $filter->getValues();
                $workflow->set($data);
                $workflow = $wfDao->save($workflow);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
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
            $json = $this->success([IWorkflow::WORKFLOWS => [$workflow->toArray()]]);
            $this->addTask($workflow, ListenerFactory::WORKFLOW_CREATED);
        }
        return $json;
    }

    /**
     * Deletes the workflow. Trying to delete the global workflow will result in an error. Authentication is required
     * perform a delete
     * @param mixed $id     id of entity to delete
     *
     * Example 200: DELETE http://<host>>/api/<version>/workflows/<id>
     * {
     *       "error": null,
     *       "messages": [
     *          {
     *              "code": 200
     *              "text": "Workflow [<id>] was deleted"
     *          }
     *       ],
     *       "data": null
     * }
     * If the workflow is not found the response is:
     *
     * 404:
     * {
     *       "error": 404,
     *       "messages": [
     *          {
     *              "code": 404
     *              "text": "Cannot fetch entry. Id does not exist."
     *          }
     *       ],
     *       "data": null
     * }
     * If workflows are not enabled in configuration the response is:
     *
     * 501:
     * {
     *       "error": 501,
     *       "messages": [
     *          {
     *              "code": 501
     *              "text": "Workflows are not enabled"
     *          }
     *       ],
     *       "data": null
     * }
     * If an attempt is made to delete the global workflow the response is:
     *
     * 403:
     * {
     *       "error": 403,
     *       "messages": [
     *          {
     *              "code": 403
     *              "text": "Cannot delete the global workflow"
     *          }
     *       ],
     *       "data": null
     * }
     * If an attempt is made to delete a workflow the caller does not have permission to edit:
     *
     * 404:
     * {
     *       "error": 404,
     *       "messages": [
     *          {
     *              "code": 404
     *              "text": "Cannot fetch entry. Id does not exist."
     *          }
     *       ],
     *       "data": null
     * }
     * Unauthorized
     * {
     *       "error": 401,
     *       "messages": [
     *           {
     *               "code": 401,
     *               "text": "Unauthorized"
     *           }
     *       ],
     *       "data": null
     *  }
     * @return JsonModel|mixed
     */
    public function delete($id)
    {
        $errors     = null;
        $workflow   = null;
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        $checker    = $services->get(Services::CONFIG_CHECK);
        try {
            // Check workflow first outside to get a 501 - Not Implemented if not enabled
            $checker->check(IWorkflow::WORKFLOW_CHECKER);
            $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
            $wfDao   = $services->get(IModelDAO::WORKFLOW_DAO);
            try {
                $checker->check(IPermissions::AUTHENTICATED_CHECKER);
                $workflow = $wfDao->fetchById($id, $p4Admin);
                $wfDao->delete($workflow);
            } catch (ForbiddenException $e) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
        } catch (NotFoundException $e) {
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
            $json = $this->success(null, [$translator->t('Workflow [%s] was deleted', [$id])]);
            $this->addTask($workflow, ListenerFactory::WORKFLOW_DELETED);
        }
        return $json;
    }

    /**
     * Get a workflow by its id. 'fields' can be used as a query parameter to limit returned fields (top level).
     *
     * Example 200: http://<host>>/api/<version>/workflows/<id>
     *
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "workflows": [
     *                 {
     *                   "id": 0
     *                   "name": "Workflow name",
     *                   "description": null,
     *                   "shared": false,
     *                   "owners": [
     *                       "swarm"
     *                   ],
     *                   "on_submit": {
     *                       "with_review": {
     *                           "rule": "strict",
     *                           "mode": "policy"
     *                       },
     *                       "without_review": {
     *                           "rule": "reject",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "end_rules": {
     *                       "update": {
     *                           "rule": "no_checking",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "auto_approve": {
     *                       "rule": "never",
     *                       "mode": "default"
     *                   },
     *                   "counted_votes": {
     *                       "rule": "anyone",
     *                       "mode": "default"
     *                   },
     *                   "group_exclusions": {
     *                       "rule": [],
     *                       "mode": "policy"
     *                   },
     *                   "user_exclusions": {
     *                       "rule": [],
     *                       "mode": "policy"
     *                   }
     *               }
     *           ]
     *       }
     * }
     *
     * Example 200: http://<host>>/api/<version>/workflows?fields=id,name
     *
     * If workflows are not enabled in configuration the response is:
     *
     * 501:
     * {
     *       "error": 501,
     *       "messages": [
     *          {
     *              "code": 501
     *              "text": "Workflows are not enabled"
     *          }
     *       ],
     *       "data": null
     * }
     *
     * If the workflow is not found the response is:
     *
     * 404:
     * {
     *       "error": 404,
     *       "messages": [
     *          {
     *              "code": 404
     *              "text": "Cannot fetch entry. Id does not exist."
     *          }
     *       ],
     *       "data": null
     * }
     * @param mixed $id workflow id
     * @return JsonModel|mixed
     */
    public function get($id)
    {
        $errors = null;
        $data   = null;
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER);
            $fields  = $this->getRequest()->getQuery(IRequest::FIELDS);
            $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
            $data    = $this->limitFields($wfDao->fetchById($id, $p4Admin)->toArray(), $fields);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }

        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([IWorkflow::WORKFLOWS => [$data]]);
        }
        return $json;
    }

    /**
     * Get all workflows. 'fields' can be used as a query parameter to limit returned fields (top level). 'noCache' is
     * also supported, a value of true will get results from the Helix server directly without using the redis cache.
     *
     * Example 200: http://<host>>/api/<version>/workflows
     *
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "workflows": [
     *                 {
     *                   "id": 0
     *                   "name": "Workflow name",
     *                   "description": null,
     *                   "shared": false,
     *                   "owners": [
     *                       "swarm"
     *                   ],
     *                   "on_submit": {
     *                       "with_review": {
     *                           "rule": "strict",
     *                           "mode": "policy"
     *                       },
     *                       "without_review": {
     *                           "rule": "reject",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "end_rules": {
     *                       "update": {
     *                           "rule": "no_checking",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "auto_approve": {
     *                       "rule": "never",
     *                       "mode": "default"
     *                   },
     *                   "counted_votes": {
     *                       "rule": "anyone",
     *                       "mode": "default"
     *                   },
     *                   "group_exclusions": {
     *                       "rule": [],
     *                       "mode": "policy"
     *                   },
     *                   "user_exclusions": {
     *                       "rule": [],
     *                       "mode": "policy"
     *                   }
     *               }
     *           ]
     *       }
     * }
     *
     * Example 200: http://<host>>/api/<version>/workflows?fields=id,name
     *
     * If workflows are not enabled in configuration the response is:
     *
     * 501:
     * {
     *       "error": 501,
     *       "messages": [
     *          {
     *              "code": 501
     *              "text": "Workflows are not enabled"
     *          }
     *       ],
     *       "data": null
     * }
     * @return JsonModel|mixed
     */
    public function getList()
    {
        $errors = null;
        $data   = null;

        $testDefinition = $this->getRequest()->getQuery(IRequest::TESTDEFINITIONS);
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            try {
                $services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER);
                $fields  = $this->getRequest()->getQuery(IRequest::FIELDS);
                $noCache = $this->getRequest()->getQuery(IRequest::NO_CACHE);
                $name    = $this->getRequest()->getQuery(IWorkflow::NAME);
                $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
                $options = [];
                if (isset($noCache) && ($noCache === 'true' || $noCache === true)) {
                    $options[IModelDAO::FETCH_NO_CACHE] = true;
                }
                // Names are unique so if name is set it makes no sense to add additional
                // keyword filter criteria
                if (isset($name)) {
                    $options[AbstractKey::FETCH_BY_KEYWORDS]     = $name;
                    $options[AbstractKey::FETCH_KEYWORDS_FIELDS] = [IWorkflow::NAME];
                    $options[Workflow::FETCH_TOTAL_COUNT]        = true;
                } elseif (isset($testDefinition) && is_numeric(intval($testDefinition))) {
                    $options[Workflow::FETCH_BY_KEYWORDS]     = $testDefinition;
                    $options[Workflow::FETCH_KEYWORDS_FIELDS] = [IWorkflow::TESTS];
                    $options[Workflow::FETCH_TOTAL_COUNT]     = true;
                }
                $data = $this->limitFieldsForAll(
                    array_values($wfDao->fetchAll($options, $p4Admin)->toArray()),
                    $fields
                );
            } catch (ForbiddenException $e) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
                $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }

        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([IWorkflow::WORKFLOWS => $data]);
        }
        return $json;
    }

    /**
     * Update an existing workflow
     * Example: PUT http://<host>>/api/<version>/workflows/1
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "workflows": [
     *                 {
     *                   "id": 1
     *                   "name": "Workflow name",
     *                   "description": null,
     *                   "shared": false,
     *                   "owners": [
     *                       "swarm"
     *                   ],
     *                   "on_submit": {
     *                       "with_review": {
     *                           "rule": "strict",
     *                           "mode": "policy"
     *                       },
     *                       "without_review": {
     *                           "rule": "reject",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "end_rules": {
     *                       "update": {
     *                           "rule": "no_checking",
     *                           "mode": "default"
     *                       }
     *                   },
     *                   "auto_approve": {
     *                       "rule": "never",
     *                       "mode": "default"
     *                   },
     *                   "counted_votes": {
     *                       "rule": "anyone",
     *                       "mode": "default"
     *                   },
     *                   "group_exclusions": [],
     *                   "user_exclusions": []
     *               }
     *           ]
     *       }
     * }
     * If the workflow is not found the response is:
     *
     * 404:
     * {
     *       "error": 404,
     *       "messages": [
     *          {
     *              "code": 404
     *              "text": "Cannot fetch entry. Id does not exist."
     *          }
     *       ],
     *       "data": null
     * }
     * If workflows are not enabled in configuration the response is:
     *
     * 501:
     * {
     *       "error": 501,
     *       "messages": [
     *          {
     *              "code": 501
     *              "text": "Workflows are not enabled"
     *          }
     *       ],
     *       "data": null
     * }
     * If an attempt is made to update a workflow the caller does not have permission to edit:
     *
     * 404:
     * {
     *       "error": 404,
     *       "messages": [
     *          {
     *              "code": 404
     *              "text": "Cannot fetch entry. Id does not exist."
     *          }
     *       ],
     *       "data": null
     * }
     * For errors in the provided data (for example)
     * 400:
     * {
     *       "error": 400,
     *       "messages": {
     *           "name" : {
     *               "isEmpty": "Value is required and can't be empty"
     *           }
     *       },
     *       "data": null
     * }
     * Unauthorized
     * {
     *       "error": 401,
     *       "messages": [
     *           {
     *               "code": 401,
     *               "text": "Unauthorized"
     *           }
     *       ],
     *       "data": null
     *  }
     * @param mixed $id     id to update
     * @param mixed $data   data for update
     * @return JsonModel|mixed
     */
    public function update($id, $data)
    {
        $errors   = null;
        $services = $this->services;
        $workflow = null;
        $checker  = $services->get(Services::CONFIG_CHECK);
        try {
            $checker->checkAll([IWorkflow::WORKFLOW_CHECKER, IPermissions::AUTHENTICATED_CHECKER]);
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $p4User   = $services->get(ConnectionFactory::P4_USER);
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $workflow = $wfDao->fetchById($id, $p4Admin);
            if ($workflow->canEdit($p4User)) {
                $filter = $services->build(
                    $id === (string)IWorkflow::GLOBAL_WORKFLOW_ID
                        ? Services::GLOBAL_WORKFLOW_FILTER : Services::WORKFLOW_FILTER,
                    [
                        IWorkflow::ID => $id
                    ]
                );
                // fix the test to start at 0, reindexing.
                $data[IWorkflow::TESTS] = array_values($data[IWorkflow::TESTS]);
                $this->defaultValues($data, $id);
                $filter->setData($data);
                if ($filter->isValid()) {
                    $data = $filter->getValues();
                    $workflow->set($data);
                    $workflow = $wfDao->save($workflow);
                } else {
                    $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                    $errors = $filter->getMessages();
                }
            } else {
                // No permission - we'll treat this as not found so as to not give an clues
                throw new NotFoundException('Cannot fetch entry. Id does not exist.');
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
        } catch (NotFoundException $e) {
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
            $json = $this->success([IWorkflow::WORKFLOWS => [$workflow->toArray()]]);
            $this->addTask($workflow, ListenerFactory::WORKFLOW_UPDATED);
        }
        return $json;
    }

    /**
     * Add a task to the queue for a workflow action.
     * @param mixed     $workflow   the workflow related to the task
     * @param string    $task       the name of the task
     */
    private function addTask($workflow, $task)
    {
        try {
            $queue = $this->services->get(Manager::SERVICE);
            $queue->addTask(
                $task,
                $workflow->getId(),
                [
                    IWorkflow::NAME       => $workflow->getName(),
                    ListenerFactory::USER => $this->services->get(ConnectionFactory::P4_USER)->getUser()
                ]
            );
        } catch (Exception $e) {
            // Lets not fail if the only issue is task creation for activity
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err(sprintf("Error creating workflow task [%s]", $e->getMessage()));
        }
    }

    /**
     * Provide some defaults for user and group exclusions.
     * @param array     $data       data
     * @param mixed     $id         id of change, defaults to null for create
     */
    private function defaultValues(&$data, $id = null)
    {
        $isGlobal = $id !== null && (int)$id === IWorkflow::GLOBAL_WORKFLOW_ID;
        $mode     = $isGlobal ? IWorkflow::MODE_POLICY : IWorkflow::MODE_INHERIT;
        if (!isset($data[IWorkflow::GROUP_EXCLUSIONS])) {
            $data[IWorkflow::GROUP_EXCLUSIONS] = [
                IWorkflow::RULE => [],
                IWorkflow::MODE => $mode
            ];
        } else {
            $data[IWorkflow::GROUP_EXCLUSIONS] += [
                IWorkflow::RULE => [],
                IWorkflow::MODE => $mode
            ];
        }
        if (!isset($data[IWorkflow::USER_EXCLUSIONS])) {
            $data[IWorkflow::USER_EXCLUSIONS] = [
                IWorkflow::RULE => [],
                IWorkflow::MODE => $mode
            ];
        } else {
            $data[IWorkflow::USER_EXCLUSIONS] += [
                IWorkflow::RULE => [],
                IWorkflow::MODE => $mode
            ];
        }
    }
}
