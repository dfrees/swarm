<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration\Controller;

use Api\Controller\AbstractRestfulController;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Application\Permissions\Permissions;
use Events\Listener\ListenerFactory;
use Exception;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Queue\Manager;
use Record\Exception\NotFoundException;
use TestIntegration\Model\ITestDefinition;
use TestIntegration\Filter\ITestDefinition as ITestDefinitionFilter;
use TestIntegration\Model\TestDefinition;
use Workflow\Model\IWorkflow;

/**
 * Class TestDefinitionsApi.
 * @package TestIntegration\Controller
 */
class TestDefinitionsApi extends AbstractRestfulController
{
    const PUBLIC_FIELDS  = [
        'id',
        ITestDefinition::FIELD_NAME,
        ITestDefinition::FIELD_DESCRIPTION,
        ITestDefinition::FIELD_OWNERS,
        ITestDefinition::FIELD_SHARED
    ];
    const PRIVATE_FIELDS = [
        ITestDefinition::FIELD_URL,
        ITestDefinition::FIELD_BODY,
        ITestDefinition::FIELD_ENCODING,
        ITestDefinition::FIELD_HEADERS,
        ITestDefinition::FIELD_TIMEOUT,
        ITestDefinition::FIELD_ITERATE_PROJECT_BRANCHES
    ];

    /**
     * Get a test definition
     * @param mixed $id test definition id
     * @return JsonModel|mixed
     */
    public function get($id)
    {
        $errors = null;
        $data   = null;
        try {
            $services = $this->services;
            $checker  = $services->get(Services::CONFIG_CHECK);
            $checker->check(ITestDefinition::TEST_DEFINITION_CHECKER);
            $testDefinitionDao = $services->get(IModelDAO::TEST_DEFINITION_DAO);
            $p4Admin           = $services->get(ConnectionFactory::P4_ADMIN);
            $testDefinition    = $testDefinitionDao->fetchById($id, $p4Admin);
            $data              = $this->limitTestDefinitionsFields([$testDefinition]);
            // If we have no test definitions then we should throw an error.
            if (count($data) < 1) {
                throw new NotFoundException(sprintf("No Test Definition found for ID %s", $id));
            }
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
            $json = $this->success([ITestDefinition::TESTDEFINITIONS => $data]);
        }
        return $json;
    }

    /**
     * Get all Test Definitions
     * @return JsonModel|mixed
     */
    public function getList()
    {
        $errors = null;
        $data   = null;
        try {
            $services = $this->services;
            $checker  = $services->get(Services::CONFIG_CHECK);
            $checker->check(ITestDefinition::TEST_DEFINITION_CHECKER);
            $testDefinitionDao = $services->get(IModelDAO::TEST_DEFINITION_DAO);
            $p4Admin           = $services->get(ConnectionFactory::P4_ADMIN);
            $options           = [];
            $data              = $this->limitTestDefinitionsFields(
                $testDefinitionDao->fetchAll($options, $p4Admin)
            );
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([ITestDefinition::TESTDEFINITIONS => $data]);
        }
        return $json;
    }

    /**
     * Create a test definition. Caller must be authenticated
     * Example: POST http://<host>>/api<version>/testdefinitions
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *          "testdefinitions": [
     *              {
     *                  "id": 2,
     *                  "headers": {
     *                      "BasicAuth": "user:password",
     *                      "OtherHeader": "OtherValue"
     *                  },
     *                  "title": "Title",
     *                  "encoding": "json",
     *                  "body": "{}",
     *                  "url": "http://<url>",
     *                  "timeout": 10,
     *                  "owners": [
     *                      "swarm"
     *                  ],
     *                  "shared": false,
     *                  "description": ""
     *               }
     *          ]
     *       }
     * }
     *
     * For errors in the provided data (for example)
     * 400:
     * {
     *       "error": 400,
     *       "messages": {
     *           "title" : {
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
     * }
     * @param mixed         $data       data for create
     * @return JsonModel|mixed
     */
    public function create($data)
    {
        $errors   = null;
        $td       = null;
        $services = $this->services;
        $checker  = $services->get(Services::CONFIG_CHECK);
        try {
            // Make sure that a user is Authenticated
            $checker->checkAll([ITestDefinition::TEST_DEFINITION_CHECKER, IPermissions::AUTHENTICATED_CHECKER]);
            $filter = $services->get(ITestDefinitionFilter::NAME);
            $filter->setData($data);
            if ($filter->isValid()) {
                $tdDao   = $services->get(IDao::TEST_DEFINITION_DAO);
                $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
                $td      = new TestDefinition($p4Admin);
                $td->set($filter->getValues());
                $td = $tdDao->save($td);
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
            $json = $this->success([ITestDefinition::TESTDEFINITIONS => [$td->toArray()]]);
            $this->addTask($td, ListenerFactory::TEST_DEFINITION_CREATED);
        }
        return $json;
    }

    /**
     * Update a test definition. Caller must be authenticated
     * Example: PUT http://<host>>/api<version>/testdefinitions/2
     * 200:
     * {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *          "testdefinitions": [
     *              {
     *                  "id": 2,
     *                  "headers": {
     *                      "BasicAuth": "user:password",
     *                      "OtherHeader": "OtherValue"
     *                  },
     *                  "title": "Title",
     *                  "encoding": "json",
     *                  "body": "{}",
     *                  "url": "http://<url>",
     *                  "timeout": 10,
     *                  "owners": [
     *                      "swarm"
     *                  ],
     *                  "shared": false,
     *                  "description": ""
     *               }
     *          ]
     *       }
     * }
     *
     * For errors in the provided data (for example)
     * 400:
     * {
     *       "error": 400,
     *       "messages": {
     *           "title" : {
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
     * }
     * @param mixed     $id     id of record to update
     * @param mixed     $data   data for the update
     * @return JsonModel|mixed
     */
    public function update($id, $data)
    {
        $errors         = null;
        $services       = $this->services;
        $translator     = $services->get(TranslatorFactory::SERVICE);
        $checker        = $services->get(Services::CONFIG_CHECK);
        $testDefinition = null;
        $extraData      = [];
        try {
            // Make sure that a user is Authenticated and that test definitions are enabled
            $checker->checkAll([ITestDefinition::TEST_DEFINITION_CHECKER, IPermissions::AUTHENTICATED_CHECKER]);
            $tdDao   = $services->get(IDao::TEST_DEFINITION_DAO);
            $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
            $td      = $tdDao->fetchById($id, $p4Admin);
            $p4user  = $services->get(ConnectionFactory::P4_USER);
            if ($p4user->isSuperUser() || $td->isOwner($p4user->getUser())) {
                // set the old and new data so listener can use the data.
                $extraData[ITestDefinition::TEST_DEFINITION_OLD] = $td->toArray();

                $filter = $services->build(ITestDefinitionFilter::NAME, [ITestDefinition::FIELD_ID => $id]);
                $filter->setData($data);
                if ($filter->isValid()) {
                    $td->set($filter->getValues());
                    $td = $tdDao->save($td);
                    // now get the data
                    $extraData[ITestDefinition::TEST_DEFINITION_NEW] = $td->toArray();
                } else {
                    $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                    $errors = $filter->getMessages();
                }
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $errors = [
                    $this->buildMessage(
                        Response::STATUS_CODE_403,
                        $translator->t("You do not have permission to edit this test definition")
                    )
                ];
            }
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, 'Unauthorized')];
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $errors = [$this->buildMessage(Response::STATUS_CODE_501, $e->getMessage())];
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([ITestDefinition::TESTDEFINITIONS => [$td->toArray()]]);
            $this->addTask($td, ListenerFactory::TEST_DEFINITION_UPDATED, $extraData);
        }
        return $json;
    }

    /**
     * Delete the testDefinition.
     * @param mixed $id     id of entity to delete
     * @return JsonModel|mixed
     */
    public function delete($id)
    {
        $errors         = null;
        $services       = $this->services;
        $translator     = $services->get(TranslatorFactory::SERVICE);
        $checker        = $services->get(Services::CONFIG_CHECK);
        $testDefinition = null;
        try {
            // Check test definition is enabled
            $checker->checkAll([ITestDefinition::TEST_DEFINITION_CHECKER, IPermissions::AUTHENTICATED_CHECKER]);
            try {
                $p4Admin           = $services->get(ConnectionFactory::P4_ADMIN);
                $testDefinitionDao = $services->get(IModelDAO::TEST_DEFINITION_DAO);
                $testDefinition    = $testDefinitionDao->fetchById($id, $p4Admin);
                $testDefinitionDao->delete($testDefinition);
            } catch (ForbiddenException $e) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            }
        } catch (UnauthorizedException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
            $errors = [$this->buildMessage(Response::STATUS_CODE_401, 'Unauthorized')];
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
            $json = $this->success(null, [$translator->t('Test definition with id [%s] was deleted', [$id])]);
            $this->addTask($testDefinition, ListenerFactory::TEST_DEFINITION_DELETED);
        }
        return $json;
    }


    /**
     * Limit the fields based on owner, super user and testDefinition shared attribute.
     * Private fields will only be returned for owners and super-users
     * @param mixed   $testDefinitions Test definitions with prohibited fields removed.
     * @return array
     */
    protected function limitTestDefinitionsFields($testDefinitions)
    {
        $userId      = '';
        $results     = [];
        $permissions = $this->services->get(Permissions::PERMISSIONS);
        $isSuper     = $permissions->is(Permissions::SUPER);
        if ($permissions->is(Permissions::AUTHENTICATED)) {
            $userId = $this->services->get(ConnectionFactory::P4_USER)->getUser();
        }
        foreach ($testDefinitions as $testDefinition) {
            $isOwner = $testDefinition->isOwner($userId);
            $fields  = self::PUBLIC_FIELDS;
            if ($isSuper || $isOwner) {
                $fields = array_merge($fields, self::PRIVATE_FIELDS);
            }
            $results[] = $this->limitFields(
                $testDefinition->toArray(),
                $fields
            );
        }
        return $results;
    }

    /**
     * Add a task to the queue for a testdefinition action.
     *
     * @param mixed  $testDefinition the testdefinition related to the task
     * @param string $task           the name of the task
     * @param array  $data           additional data to be set
     */
    private function addTask($testDefinition, $task, $data = [])
    {
        try {
            $queue = $this->services->get(Manager::SERVICE);
            $queue->addTask(
                $task,
                $testDefinition->getId(),
                array_merge(
                    $data,
                    [
                        IWorkflow::NAME       => $testDefinition->getName(),
                        ListenerFactory::USER => $this->services->get(ConnectionFactory::P4_USER)->getUser()
                    ]
                )
            );
        } catch (Exception $e) {
            // Lets not fail if the only issue is task creation for activity
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err(sprintf("Error creating test definition task [%s]", $e->getMessage()));
        }
    }
}
