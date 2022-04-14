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
use Application\Permissions\Permissions;
use Application\Permissions\PrivateProjects;
use InvalidArgumentException;
use Exception;
use Record\Exception\NotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Reviews\Model\Review;
use TestIntegration\Filter\ITestRun;
use TestIntegration\Filter\StatusValidator;
use TestIntegration\Model\TestRun;
use TestIntegration\Model\TestRun as Model;
use TestIntegration\Service\ITestExecutor;
use TestIntegration\Service\ProjectTestNameTrait;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Application\Permissions\Exception\ForbiddenException;
use Reviews\Listener\IReviewTask;

/**
 * Class TestRunApi. API controller for TestRun
 * @package TestIntegration\Controller
 */
class TestRunApi extends AbstractRestfulController
{
    const DATA_TEST_RUNS   = 'testruns';
    const PATH_TEST        = 'id';
    const REVIEW_ID        = 'reviewId';
    const UUID_POST_FIELDS = [Model::FIELD_MESSAGES, Model::FIELD_STATUS, Model::FIELD_URL];
    // URL routes to help with qualified url building
    const PASS_URL_ROUTE          = 'api/testruns-api/testruns-pass/action';
    const FAIL_URL_ROUTE          = 'api/testruns-api/testruns-fail/action';
    const UPDATE_URL_ROUTE        = 'api/review-testruns/testruns-test/edit';
    const UPDATE_NOAUTH_URL_ROUTE = 'api/testruns-api/testruns-uuid/action';

    use ProjectTestNameTrait;

    /**
     * TestRunApi constructor.
     * @param $services
     */
    public function __construct($services)
    {
        // As we want the get to work for the ReviewId we need to change the identifier name.
        $this->setIdentifierName(self::REVIEW_ID);
        parent::__construct($services);
    }

    /**
     * Get all test runs. Can have a query parameter of 'ids' which can be a string for a single id or an array of
     * strings to only return test runs matching the given id(s).
     * - /api/vX/testruns
     * - /api/vX/testruns?ids=1
     * - /api/vX/testruns?ids[]=1&ids[]=2
     * @return JsonModel
     */
    public function getList() : JsonModel
    {
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $errors   = null;
        $request  = $this->getRequest();
        $query    = $request->getQuery();
        $testRuns = null;
        try {
            $dao        = $this->services->get(IDao::TEST_RUN_DAO);
            $testRunIds = $query->get(ITestRun::IDS);
            $testRuns   = $dao->fetchAll($testRunIds ? [AbstractKey::FETCH_BY_IDS => $testRunIds] : [], $p4Admin);
            $this->populateTitle($testRuns);
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $data = [self::DATA_TEST_RUNS => $testRuns ? $this->maskFields($testRuns): []];
            $json = $this->success($data);
        }
        return $json;
    }

    /**
     * Get test runs for review (and optionally version)
     * @return JsonModel
     */
    public function getByReviewAction(): JsonModel
    {
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $version  = $this->getRequest()->getQuery(Model::FIELD_VERSION);
        $error    = null;
        $testRuns = null;
        $dao      = $this->services->get(IDao::TEST_RUN_DAO);
        $reviewId = $this->getEvent()->getRouteMatch()->getParam(self::REVIEW_ID);
        try {
            $review = Review::fetch($reviewId, $p4Admin);
            try {
                $testRunIds = $review->getTestRuns($version);
                if ($testRunIds) {
                    $testRuns = $dao->fetchAll([Model::FETCH_BY_IDS => $testRunIds], $p4Admin);
                    $this->populateTitle($testRuns);
                }
            } catch (InvalidArgumentException $e) {
                // Version not found, but the request is correctly formed
                $error = $this->buildMessage(Response::STATUS_CODE_422, $e->getMessage());
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_422);
            }
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
            $data = [self::DATA_TEST_RUNS => $testRuns ? $this->maskFields($testRuns): []];
            // If we have specified a particular version build an overall status value based on all
            // the test run statuses
            if ($version && $testRuns) {
                $data[Model::FIELD_STATUS] = $dao->calculateTestStatusForTestRuns($testRuns->toArray(true));
            }
            $json = $this->success($data);
        }
        return $json;
    }

    /**
     * Populate the title for the test runs if not set. The title is determined by linking the 'test' field from the run
     * to a project or global test.
     * In the case of a project test it is set to the project name if the project is found, or if not found it is
     * set to the value of the project id
     * In the case of a global test it is set to the title if the global test is found, or if not found it is
     * set to the value of the 'test' field
     * @param mixed     $testRuns   the test runs
     * @return mixed
     */
    private function populateTitle($testRuns)
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        foreach ($testRuns as $testRun) {
            if (!$testRun->getTitle()) {
                $testIdentifier = $testRun->getTest();
                if (strpos($testIdentifier, ITestExecutor::PROJECT_PREFIX) === 0) {
                    $projectId = $this->getProjectIdFromTestName($testIdentifier);
                    $dao       = $this->services->get(IModelDAO::PROJECT_DAO);
                    try {
                        $project = $dao->fetchById($projectId, $p4Admin);
                        $testRun->setTitle($project->getName());
                    } catch (RecordNotFoundException $e) {
                        $testRun->setTitle($projectId);
                    }
                } else {
                    $dao = $this->services->get(IDao::TEST_DEFINITION_DAO);
                    try {
                        $testDefinition = $dao->fetchById($testIdentifier, $p4Admin);
                        $testRun->setTitle($testDefinition->getName());
                    } catch (Exception $e) {
                        $testRun->setTitle($testIdentifier);
                    }
                }
            }
        }
        return $testRuns;
    }

    /**
     * Handle POST to update a test run for a non-authenticated call.
     * @return mixed|JsonModel
     */
    public function updateWithUuidAction()
    {
        $errors  = [];
        $testRun = null;
        $data    = json_decode($this->getRequest()->getContent(), true);
        // Work out if $data specifies anything other that UUID_POST_FIELDS
        $extraFields = array_diff_key($data, array_flip(self::UUID_POST_FIELDS));
        if ($extraFields) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = [$this->buildMessage(
                Response::STATUS_CODE_400,
                $translator->t(
                    sprintf(
                        "Only the following fields are permitted [%s]",
                        implode(', ', self::UUID_POST_FIELDS)
                    )
                )
            )];
        } else {
            $testRun = $this->getTestRunWithUuid($errors);
            if (!$errors) {
                $mergedData = array_merge($testRun->get(), $data);
                $filter     = $this->services->get(ITestRun::NAME);
                // Some model fields may be null if optional (allowed), remove these from the data
                // so the filter otherwise the filter will see them as a specified value being set
                // to null
                $filter->removeOptional($mergedData);
                $filter->setData($mergedData);
                if ($filter->isValid()) {
                    $testRun = $this->saveTestRun($testRun, $filter->getValues());
                    $this->queueReviewTask($testRun);
                } else {
                    $errors = $filter->getMessages();
                    $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                }
            }
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_TEST_RUNS => [$testRun->toArray()]]);
        }
        return $json;
    }

    /**
     * Handle POST to create a test run for a given test id
     * @return mixed|JsonModel
     */
    public function createForTestAction()
    {
        $data   = null;
        $errors = null;
        $testId = $this->getEvent()->getRouteMatch()->getParam(self::PATH_TEST);
        // Fail early if not authenticated
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
            $testDefDao              = $this->services->get(IDao::TEST_DEFINITION_DAO);
            $testDefinition          = $testDefDao->fetchById($testId);
            $data                    = json_decode($this->getRequest()->getContent(), true);
            $data[Model::FIELD_TEST] = (string)$testDefinition->getId();
        } catch (NotFoundException $e) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            $errors     = [
                $this->buildMessage(
                    Response::STATUS_CODE_422,
                    $translator->t(sprintf('Test definition [%s] not found', $testId))
                )
            ];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_422);
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->create($data);
        }
        return $json;
    }

    /**
     * Handle default POST requests
     * @param mixed $data
     * @return mixed|JsonModel
     */
    public function create($data)
    {
        $errors  = null;
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
            $review = Review::fetch($this->getEvent()->getRouteMatch()->getParam(self::REVIEW_ID), $p4Admin);
            $filter = $this->services->get(ITestRun::NAME);
            $filter->setData($data);
            if ($filter->isValid()) {
                $testRun = new TestRun;
                $testRun = $this->saveTestRun($testRun, $filter->getValues());
                $review->addTestRun($testRun->getId(), $testRun->getVersion())->save();
            } else {
                $errors = $filter->getMessages();
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (InvalidArgumentException $e) {
            // Review id not correct form, part of the path so use 404
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_TEST_RUNS => [$testRun->toArray()]]);
        }
        return $json;
    }

    /**
     * Saves the test run applying the data if provided
     * @param mixed         $testRun    the test run to save
     * @param null|array    $data       the data to set
     * @param mixed         $dao        DAO to use (if already found via services). If null we will get it from services
     * @return mixed the saved test run
     */
    private function saveTestRun($testRun, $data = null, $dao = null)
    {
        if ($dao === null) {
            $dao = $this->services->get(IDao::TEST_RUN_DAO);
        }
        if ($data) {
            unset($data[Model::FIELD_ID]);
            foreach ($data as $key => $value) {
                if ($key === Model::FIELD_STATUS) {
                    switch ($value) {
                        case StatusValidator::STATUS_PASS:
                        case StatusValidator::STATUS_FAIL:
                            if (!isset($data[Model::FIELD_COMPLETED_TIME])) {
                                // Status update to pass|fail and no completedTime in data
                                $testRun->setCompletedTime(time());
                            }
                            break;
                        default:
                            // Status update to running
                            $testRun->setCompletedTime(null);
                            if (!isset($data[Model::FIELD_START_TIME])) {
                                // No start time specified in data
                                $testRun->setStartTime(time());
                            }
                            break;
                    }
                }
                $testRun->setRawValue($key, $value);
            }
        }
        $this->populateTitle([$testRun]);
        return $dao->save($testRun);
    }

    /**
     * Update (PUT) test run data
     * @param mixed $reviewId  the review id to update
     * @param mixed $data      the data
     * @return mixed|JsonModel
     */
    public function update($reviewId, $data)
    {
        try {
            // Fail fast if not authenticated
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
            $tr     = new TestRun;
            $fields = array_flip($tr->getDefinedFields());
            unset($fields[Model::FIELD_ID]);
            unset($fields[Model::FIELD_TITLE]);
            unset($fields[Model::FIELD_UPGRADE]);
            $missingFields = array_diff_key($fields, array_flip(array_keys($data)));
            if ($missingFields) {
                // Fail if any values (except ID) as missing from the PUT
                $translator = $this->services->get(TranslatorFactory::SERVICE);
                $json       = $this->error(
                    [
                        $this->buildMessage(
                            Response::STATUS_CODE_400,
                            $translator->t(
                                sprintf(
                                    "All fields required for update, missing [%s]",
                                    implode(', ', array_keys($missingFields))
                                )
                            )
                        )
                    ],
                    Response::STATUS_CODE_400
                );
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            } else {
                $json = $this->patch($reviewId, $data);
            }
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $json = $this->error($errors, Response::STATUS_CODE_403);
        }
        return $json;
    }

    /**
     * Patch test run data
     * @param mixed $reviewId the review id to update
     * @param mixed $data     the data
     * @return mixed|JsonModel
     */
    public function patch($reviewId, $data)
    {
        $errors  = null;
        $testRun = null;
        try {
            $id = $this->getEvent()->getRouteMatch()->getParam('id');
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
            $filter  = $this->services->get(ITestRun::NAME);
            $dao     = $this->services->get(IDao::TEST_RUN_DAO);
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            // We need to combine the data with existing values (in the case of PATCH all fields may not have been
            // specified but we need to evaluate all together in the filter)
            $testRun       = $dao->fetch($id, $p4Admin);
            $currentValues = $testRun->get();
            $mergedData    = array_merge($currentValues, $data);
            $filter->removeOptional($mergedData);
            $filter->setData($mergedData);
            if ($filter->isValid()) {
                // Fetch to make sure the review exists, the test run will already be set on it
                $review  = Review::fetch($reviewId, $p4Admin);
                $testRun = $this->saveTestRun($testRun, $filter->getValues(), $dao);
                if (isset($data[Model::FIELD_STATUS])) {
                    // Status was updated so queue a review task to process that update
                    $this->queueReviewTask($testRun, $review);
                }
            } else {
                $errors = $filter->getMessages();
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (ForbiddenException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
        } catch (RecordNotFoundException $e) {
            // Review id is good but no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_TEST_RUNS => [$testRun->toArray()]]);
        }
        return $json;
    }

    /**
     * Endpoint for GET/POST testrun pass
     * @return JsonModel
     */
    public function passAction()
    {
        return $this->passOrFail(StatusValidator::STATUS_PASS);
    }

    /**
     * Endpoint for GET/POST testrun fail
     * @return JsonModel
     */
    public function failAction()
    {
        return $this->passOrFail();
    }

    /**
     * Run the testrun of a review
     * @return mixed|JsonModel
     */
    public function runAction(): JsonModel
    {
        $errors  = null;
        $testRun = null;
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $reviewId     = $this->getEvent()->getRouteMatch()->getParam(self::REVIEW_ID);
            $testRunId    = $this->getEvent()->getRouteMatch()->getParam(Model::FIELD_ID);
            $testExecutor = $this->services->get(ITestExecutor::NAME);
            $testRun      = $testExecutor->startTest($reviewId, $testRunId);
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_409);
            $errors = [$this->buildMessage(Response::STATUS_CODE_409, $e->getMessage())];
        }

        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_TEST_RUNS => $this->maskFields([$testRun])]);
        }
        return $json;
    }

    /**
     * Process GET/POST call to pass/fail url
     * @param string $status  status, default to StatusValidator::STATUS_FAIL
     * @return JsonModel
     */
    private function passOrFail(string $status = StatusValidator::STATUS_FAIL)
    {
        $errors  = [];
        $testRun = $this->getTestRunWithUuid($errors);
        // Get the url from post parameters or the query
        $data = $this->getRequest()->getPost()->toArray()
              + $this->getRequest()->getQuery()->toArray();
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            if (isset($data[Model::FIELD_URL])) {
                $testRun = $testRun->setUrl($data[Model::FIELD_URL]);
            }
            $testRun = $this->saveTestRun($testRun->setStatus($status)->setCompletedTime(time()));
            $this->queueReviewTask($testRun);
            $json = $this->success([self::DATA_TEST_RUNS => [$testRun->toArray()]]);
        }
        return $json;
    }

    /**
     * Gets a TestRun by id from the route and also validates that it has a matching UUID from the route
     * @param array $errors     errors to populate
     * @return Model|null the test run if the id and uuid are correct
     */
    private function getTestRunWithUuid(array &$errors)
    {
        $testRun = null;
        try {
            $id      = $this->getEvent()->getRouteMatch()->getParam(Model::FIELD_ID);
            $uuid    = $this->getEvent()->getRouteMatch()->getParam(Model::FIELD_UUID);
            $dao     = $this->services->get(IDao::TEST_RUN_DAO);
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            $testRun = $dao->fetch($id, $p4Admin);
            $this->populateTitle([$testRun]);
            if ($testRun->getUuid() !== $uuid) {
                // UUID does not match, we are effectively treating a UUID like authorisation
                // so return a 401
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
                $errors[] =
                    $this->buildMessage(
                        Response::STATUS_CODE_401,
                        $this->getResponse()->getReasonPhrase()
                    );
            }
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors[] = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        }
        return $testRun;
    }

    /**
     * Queue a task to indicate a review has been updated according to a test status change
     * @param Model $testRun    the test run
     * @param mixed $review     the review, if not known it will be looked up from the test run change
     */
    private function queueReviewTask($testRun, $review = null)
    {
        $reviewDao = $this->services->get(IDao::REVIEW_DAO);
        $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
        $dao       = $this->services->get(IDao::TEST_RUN_DAO);
        $logger    = $this->services->get(SwarmLogger::SERVICE);
        try {
            if ($review === null) {
                // fetchNoCheck is new function created to fetch the review data without
                // applying permission or filter check
                $review = $reviewDao->fetchNoCheck($testRun->getChange(), $p4Admin);
            }
            $testRuns = $review->getTestRuns($testRun->getVersion());
            $reviewDao->queueTask(
                $review,
                [
                    // Get the overall test status for all the test runs for the version of the review
                    Review::FIELD_TEST_STATUS =>
                        $testRuns ? $dao->calculateTestStatus($testRuns, $p4Admin) : $testRun->getStatus(),
                    IReviewTask::IS_DESCRIPTION_CHANGE => false,
                    IReviewTask::IS_AUTHOR_CHANGE      => false,
                    IReviewTask::IS_STATE_CHANGE       => false
                ],
                $review->get()
            );
            $logger->debug(
                sprintf(
                    "[%s]: Queued task for review [%s] revision [%s]",
                    get_class($this),
                    $testRun->getChange(),
                    $testRun->getVersion()
                )
            );
        } catch (RecordNotFoundException $e) {
            // Not fatal that we cannot queue the review but it is a problem
            $logger->err(
                sprintf(
                    "[%s]: Review [%s] not found on test status update",
                    get_class($this),
                    $testRun->getChange()
                )
            );
        } catch (InvalidArgumentException $e) {
            // Likely the version was not valid
            $logger->err(sprintf("[%s]: %s", get_class($this), $e->getMessage()));
        }
    }

    /**
     * Mask the sensitive data if private project such as title when test definition is iterate
     * Always unset key "branches"
     * @param   mixed   $testRuns    test run models
     * @return  array
     */
    protected function maskFields($testRuns): array
    {
        $p4Admin               = $this->services->get(ConnectionFactory::P4_ADMIN);
        $user                  = $this->services->get(ConnectionFactory::USER);
        $testDefinitionDao     = $this->services->get(IDao::TEST_DEFINITION_DAO);
        $projectDao            = $this->services->get(IModelDAO::PROJECT_DAO);
        $privateProjectService = $this->services->get(PrivateProjects::PROJECTS_FILTER);
        $logger                = $this->services->get(SwarmLogger::SERVICE);
        $translator            = $this->services->get(TranslatorFactory::SERVICE);
        $data                  = [];
        foreach ($testRuns as $testRun) {
            $testRunData    = $testRun->toArray();
            $testIdentifier = $testRun->getTest();
            try {
                $testDefinition     = $testDefinitionDao->fetchById($testIdentifier, $p4Admin);
                $branches           = explode(",", $testRun->getBranches());
                $testName           = $testDefinition->getName();
                $testRunName        = $testRun->getTitle();
                $testNameMatcher    = $testName . " ";
                $startsWithTestName = substr($testRunName, 0, strlen($testNameMatcher)) === $testNameMatcher;

                if ($branches && $branches[0] !== "" && count($branches) === 1 && $startsWithTestName) {
                    $testRunBranches = explode(ITestExecutor::PROJECT_TEST_SEPARATOR, $testRun->getBranches());
                    $project         = $projectDao->fetchById($testRunBranches[0], $p4Admin);
                    if (!$privateProjectService->canUserAccess($user, $project)) {
                        $testRunData[Model::FIELD_TITLE] = $testNameMatcher . $translator->t("(Private)");
                    }
                }
            } catch (RecordNotFoundException $e) {
                // Not fatal but it is a problem
                $logger->debug(
                    sprintf(
                        "[%s]: Test definition [%s] not found while masking",
                        get_class($this),
                        $testRun->getTest()
                    )
                );
            }
            unset($testRunData[Model::FIELD_BRANCHES]);
            $data[] = $testRunData;
        }

        return $data;
    }
}
