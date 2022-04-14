<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration\Service;

use Api\IRequest;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Events\Listener\ListenerFactory;
use InvalidArgumentException;
use Application\View\Helper\ViewHelperFactory;
use Application\Escaper\Escaper;
use Files\MimeType;
use Interop\Container\ContainerInterface;
use P4\Model\Fielded\Iterator;
use Projects\Model\Project as ProjectModel;
use Queue\Manager;
use Record\Key\AbstractKey;
use Reviews\Model\Review;
use TestIntegration\Controller\TestRunApi;
use TestIntegration\Filter\EncodingValidator;
use TestIntegration\Filter\ITestRun;
use TestIntegration\Filter\StatusValidator;
use TestIntegration\Model\ITestDefinition;
use TestIntegration\Model\ITestRun as ITestRunModel;
use TestIntegration\Model\TestRun;
use Reviews\Model\Review as ReviewModel;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Laminas\Http\Response;
use TestIntegration\Model\TestRunData;
use Workflow\Model\IWorkflow;
use TestIntegration\Listener\TestRun as TestRunListener;

/**
 * Class TestExecutor
 * @package TestIntegration\Service
 */
class TestExecutor implements ITestExecutor
{
    private $services;
    private $logger;
    private $testRunDao;
    protected $review;
    private $affectedProjects;
    private $projects;
    private $testsForWorkflows;
    private $hasContentChanged;
    private $p4Admin;
    private $separator;

    // The minimum API version that will support the new callback urls
    const API_VERSION             = 'v10';
    const WORKFLOW_TEST_RUNS_DATA = "workflowTestRunsData";
    const PROJECT_TEST_RUNS_DATA  = "projectTestRunsData";
    const LOG_PREFIX              = TestExecutor::class;
    /**
     * @var mixed
     */
    private $event;

    use ProjectTestNameTrait;

    /**
     * TestExecutor constructor.
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->logger     = $services->get(SwarmLogger::SERVICE);
        $this->testRunDao = $services->get(IDao::TEST_RUN_DAO);
        $this->p4Admin    = $services->get(ConnectionFactory::P4_ADMIN);
        $this->separator  = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::TEST_DEFINITIONS_PROJECT_AND_BRANCH_SEPARATOR,
            ':'
        );
    }

    /**
     * @inheritDoc
     * @throws ConfigException
     */
    public function getReviewTests(
        AbstractKey $review,
        array $affectedProjects,
        Iterator $projects,
        array $options = []
    ) {
        $this->review            = $review;
        $this->affectedProjects  = $affectedProjects;
        $this->projects          = $projects;
        $this->hasContentChanged =
            isset($options[ReviewModel::DELFROMCHANGE]) ||
            $this->services->get(IDao::REVIEW_DAO)->hasContentChanged($review);
        $testsForWorkflows       = $this->getTestsForWorkflows();
        $testRunsData            = [];
        if ($this->hasContentChanged) {
            // Content has changed - we run all tests (subject to event/pending) regardless of the previous test runs
            $testRunsData[self::WORKFLOW_TEST_RUNS_DATA] = $this->getWorkflowTests($testsForWorkflows);
            $testRunsData[self::PROJECT_TEST_RUNS_DATA]  = $this->getProjectTests();
        } else {
            $config              = $this->services->get(ConfigManager::CONFIG);
            $forceRunForProjects = ConfigManager::getValue(
                $config,
                IConfigDefinition::PROJECTS_RUN_TESTS_ON_UNCHANGED_SUBMIT,
                false
            );
            // No content change - we may still need to run tests but we can exclude those that passed on the previous
            // revision of the review
            $previouslyPassed = [];
            $head             = $review->getHeadVersion();
            if ($head > 1) {
                $testRunIds = $review->getTestRuns($head - 1);
                if ($testRunIds) {
                    foreach ($testRunIds as $testRunId) {
                        $testRun = $this->testRunDao->fetch($testRunId, $this->p4Admin);
                        // Add to previously passed if the test status is pass, and
                        // - it is a workflow test
                        // or
                        // - it is a project test and we are not forcing project tests to re-run when content has not
                        //   changed
                        if ($testRun->getStatus() === StatusValidator::STATUS_PASS
                            && (!$this->isProjectTestName($testRun->getTest()) || !$forceRunForProjects)) {
                            $previouslyPassed[] = $testRun;
                        }
                    }
                }
            }
            $previousPassedNames = array_map(
                function ($testRun) {
                    return $testRun->getTest();
                },
                $previouslyPassed
            );
            $testRunsData[self::WORKFLOW_TEST_RUNS_DATA] = $this->getWorkflowTests(
                $testsForWorkflows, $previousPassedNames
            );
            $testRunsData[self::PROJECT_TEST_RUNS_DATA]  = $this->getProjectTests($previousPassedNames);
            $this->cloneTestRuns($review, $previouslyPassed, $previousPassedNames);
        }
        return $testRunsData;
    }

    /**
     * @inheritDoc
     */
    public function startTest($reviewId, $testRunId) : ITestRunModel
    {
        $reviewDAO = $this->services->get(IDao::REVIEW_DAO);
        $review    = $reviewDAO->fetch($reviewId, $this->p4Admin);
        $this->validateTestRunId($review, $testRunId);
        $testRunDAO = $this->services->get(IDao::TEST_RUN_DAO);
        $testRun    = $testRunDAO->fetch($testRunId, $this->p4Admin);
        if ($testRun->getStatus() === StatusValidator::STATUS_RUNNING) {
            throw new InvalidArgumentException(
                sprintf(
                    "Review id [%s], test run id [%s]. Cannot re-run a test that is currently running.",
                    $reviewId,
                    $testRunId
                )
            );
        }
        $projectDAO             = $this->services->get(IDao::PROJECT_DAO);
        $this->affectedProjects = $review->getProjects();
        $this->projects         = $projectDAO->fetchAll(
            [ProjectModel::FETCH_BY_IDS => array_keys($review->getProjects())],
            $this->p4Admin
        );
        $testDefinitionDAO      = $this->services->get(IDao::TEST_DEFINITION_DAO);
        $testDefinition         = $testDefinitionDAO->fetchById($testRun->getTest(), $this->p4Admin);
        $fields                 =
            [
                self::FIELD_TEST        => $testDefinition->getName(),
                self::FIELD_TEST_RUN_ID => $testRunId
            ];
        if ($testRun->get('upgrade') < 2) {
            $projectInfo = $this->getProjectInfo();
        } else {
            $branches     = $testRun->getBranches();
            $projectIds   = [];
            $branchIds    = [];
            $branchNames  = [];
            $projectNames = [];
            $projectInfo  =
                [
                    // We always store the value with a ':' delimiter. Convert it to use the
                    // configured delimiter ready for field expansion in the URL or body
                    self::FIELD_BRANCHES => str_replace(':', $this->separator, $branches)
                ];
            foreach (explode(",", $branches) as $projectBranch) {
                $parts        = explode(':', $projectBranch);
                $projectIds[] = $parts[0];
                $branchName   = null;
                if (isset($parts[1])) {
                    // We may not have the project if it is private
                    if (isset($this->projects[$parts[0]])) {
                        $project        = $this->projects[$parts[0]];
                        $projectNames[] = $project->getName();
                        $branchName     = $this->getBranchName($project, $parts[1]);
                        if ($branchName) {
                            $branchNames[] = $branchName;
                        }
                    }
                    $branchIds[] = $parts[1];
                }
            }
            $projectInfo[self::FIELD_PROJECTS]      = implode(',', $projectIds);
            $projectInfo[self::FIELD_BRANCH]        = implode(',', $branchIds);
            $projectInfo[self::FIELD_BRANCH_NAME]   = implode(',', $branchNames);
            $projectInfo[self::FIELD_PROJECT_NAMES] = implode(',', $projectNames);
        }
        $fields       = array_merge(
            $fields,
            $projectInfo,
            $this->getFieldsFromReview($review),
            $this->generateCallbackUrls($testRunId, $review->getToken())
        );
        $this->review = $review;
        $testRun->setStatus(StatusValidator::STATUS_RUNNING);
        $testRun = $testRunDAO->save($testRun);
        $this->runTest($testDefinition, $fields, $testRun, $this->review);
        $queue = $this->services->get(Manager::SERVICE);
        $p4    = $this->services->get(ConnectionFactory::P4_USER);
        $queue->addTask(
            ListenerFactory::TEST_RUN_ON_DEMAND,
            $testRun->getId(),
            [
                TestRunListener::REVIEW_ID => $review->getId(),
                'user' => $p4->getUser()
            ]
        );
        return $testRun;
    }

    /**
     * @inheritDoc
     */
    public function runTest(ITestDefinition $testDefinition, array $fields, ITestRunModel $testRun, AbstractKey $review)
    {
        // We never allow the description in the url, so we preemptively remove it
        $url        = str_replace(self::FIELD_DESCRIPTION, '', $testDefinition->getUrl());
        $bodyFields = $fields;
        // Build out the necessary parameters for making the CI request
        $search   = array_keys($fields);
        $encoding = $testDefinition->getEncoding();
        // We treat fields in the body differently than fields in the URL itself. Expansions in the URL are always
        // fully escaped, encoding on body expansions depends on the request type (url encoded or json encoded)
        $this->encodeBodyExpansions($bodyFields, $encoding);
        $replace  = array_map('rawurlencode', array_values($fields));
        $url      = str_replace($search, $replace, $url);
        $postBody = str_replace($search, array_values($bodyFields), trim($testDefinition->getBody()));
        $headers  = $testDefinition->getHeaders() ?? [];
        $this->logger->info('Tests triggered for review: ' . $review->getId() . ' URL: ' . $url);
        $request  = [
            $url,
            $testDefinition->getDescription(),
            $encoding,
            $postBody,
            $headers,
            $testDefinition->getTimeout()
        ];
        $response = $this->doRequest(...$request);
        $this->validateResponse($testRun, $url, $response);
        return $request;
    }

    /**
     * @inheritDoc
     */
    public function runProjectTest(
        ProjectModel $project,
        array $fields,
        ITestRunModel $testRun,
        array &$testStartTimes,
        AbstractKey $review
    ) {

        // We never allow the description in the url, so we preemptively remove it
        $url        = str_replace(self::FIELD_DESCRIPTION, '', $project->getTests('url'));
        $bodyFields = $fields;
        // Build out the necessary parameters for making the CI request
        $search     = array_keys($fields);
        $postFormat = $project->getTests('postFormat') ?? EncodingValidator::URL;
        // We treat fields in the body differently that fields in the URL itself. Expansions in the URL are always
        // fully escaped, encoding on body expansions depends on the request type (url encoded or json encoded)
        $this->encodeBodyExpansions($bodyFields, $postFormat);
        $replace     = array_map('rawurlencode', array_values($fields));
        $url         = str_replace($search, $replace, $url);
        $postBody    = str_replace($search, array_values($bodyFields), trim($project->getTests('postBody')));
        $description = "Tests for " . $project->getId();
        $this->logger->info('Tests triggered for review: ' . $review->getId() . ' URL: ' . $url);
        $request  = [$url, $description, $postFormat, $postBody];
        $response = $this->doRequest($url, $description, $postFormat, $postBody);
        if ($response && $response->isSuccess()) {
            $testStartTimes[] = time();
            $review->set('testStatus', StatusValidator::STATUS_RUNNING);
            $review->save();
        }
        $this->validateResponse($testRun, $url, $response);
        return $request;
    }

    /**
     * Gets all the tests to run based on the global workflow and any workflows associated with projects and branches
     * @return mixed
     */
    public function getTestsForWorkflows()
    {
        $projectDao  = $this->services->get(IDao::PROJECT_DAO);
        $workflows   = $projectDao->getWorkflowsForAffectedProjects($this->affectedProjects);
        $workflows[] = IWorkflow::GLOBAL_WORKFLOW_ID;
        $workflowDao = $this->services->get(IDao::WORKFLOW_DAO);
        return $workflowDao->getTestsForWorkflows($workflows, [], $this->p4Admin);
    }

    /**
     * Convert project info into an array of one or more sets of project info which will be used
     * to dispatch the test runs based upon whether:
     *   - the test definition specifies one test run per project/branch combination
     *   - there are any project or branch id keywords in the test definition
     *   - multiple projects and/or branches are affected by the review
     * @param $testDefinition
     * @param $projectInfo
     * @return array
     */
    protected function getTestIterations($testDefinition, $projectInfo) : array
    {
        $this->logger->debug(
            "getTestIterations-> ".$testDefinition->getName().($testDefinition->getIterate()?" iterates":" combines").
              ", keywords[".implode(",", $this->getIterableKeywords($testDefinition))."]".
              ", branches ".$projectInfo[self::FIELD_BRANCHES]
        );
        $iterableKeywords = $this->getIterableKeywords($testDefinition);
        if ($testDefinition->getIterate() === true &&
            count($iterableKeywords) > 0 &&
            strlen($projectInfo[self::FIELD_BRANCHES]??"") > 0) {
            // This test is set to iterate, has got keywords and multiple branches
            $projectNames = array_combine(
                explode(",", $projectInfo[self::FIELD_PROJECTS]),
                explode(",", $projectInfo[self::FIELD_PROJECT_NAMES])
            );
            $branchNames  = array_combine(
                explode(",", $projectInfo[self::FIELD_BRANCH]),
                explode(",", $projectInfo[self::FIELD_BRANCH_NAME])
            );
            $this->logger->trace("Projects/branches ".$projectInfo[self::FIELD_BRANCHES]);
            if ($this->hasProjectOnlyKeywords($iterableKeywords)) {
                $testIterations = $this->buildProjectIterations($projectInfo, $projectNames);
            } else {
                $testIterations = $this->buildBranchIterations($projectInfo, $projectNames, $branchNames);
            }
            return $testIterations;
        }
        return [$projectInfo];
    }

    /**
     * Builds test iterations for the case where only project keywords were specified. An iteration should be created
     * for each project with an empty value for 'branch'
     * @param array     $projectInfo    the project information
     * @param array     $projectNames   the project names
     * @return array test iterations with one for each project
     */
    protected function buildProjectIterations(array $projectInfo, array $projectNames) : array
    {
        $testIterations = [];
        foreach (explode(",", $projectInfo[self::FIELD_PROJECTS]) as $project) {
            $testIterations [] =
                [
                    self::FIELD_PROJECTS      => $project,
                    self::FIELD_PROJECT_NAMES => $projectNames[$project],
                    self::FIELD_BRANCH        => '',
                    self::FIELD_BRANCHES      => $project,
                    self::FIELD_BRANCH_NAME   => ''
                ];
        }
        return $testIterations;
    }

    /**
     * Builds test iterations for the case when project and branch or just branch keywords are present
     * @param array     $projectInfo    the project information
     * @param array     $projectNames   the project names
     * @param array     $branchNames    the branch names
     * @return array test iterations with one for each project and branch combination
     */
    protected function buildBranchIterations(array $projectInfo, array $projectNames, array $branchNames) : array
    {
        $testIterations = [];
        foreach (explode(",", $projectInfo[self::FIELD_BRANCHES]) as $projectBranch) {
            $parts             = explode($this->separator, $projectBranch);
            $testIterations [] =
                [
                    self::FIELD_PROJECTS      => $parts[0],
                    self::FIELD_PROJECT_NAMES => $projectNames[$parts[0]],
                    self::FIELD_BRANCHES      => $projectBranch,
                    self::FIELD_BRANCH        => $parts[1],
                    self::FIELD_BRANCH_NAME   => $branchNames[$parts[1]]
                ];
        }
        return $testIterations;
    }

    /**
     * Tests if the keywords have only project based values
     * @param array     $keywords   keywords to examine
     * @return bool true if only project based keywords are present, otherwise false
     */
    protected function hasProjectOnlyKeywords(array $keywords) : bool
    {
        return empty(array_intersect($keywords, self::BRANCH_KEYWORDS));
    }

    /**
     * Return an array of the keywords that are present in a test definition and will lead to multiple
     * test runs needing to be dispatched
     * @param $testDefinition
     * @return array
     */
    protected function getIterableKeywords($testDefinition) : array
    {
        $url      = $testDefinition->getUrl();
        $body     = $testDefinition->getBody();
        $keywords = [];
        foreach (self::ITERABLE_KEYWORDS as $keyword) {
            if (strpos($url, "{{$keyword}}") !== false || strpos($body, "{{$keyword}}") !== false) {
                $keywords[] = $keyword;
            }
        }
        return $keywords;
    }

    /**
     * Get a name string for a test run which will include project and branch names when the test is
     * defined as dispatching separate runs for each project/branch
     * @param $testDefinition
     * @param $iteration
     * @return string
     */
    protected function buildIterationName($testDefinition, $iteration)
    {
        return $testDefinition->get(ITestDefinition::FIELD_ITERATE_PROJECT_BRANCHES) === true &&
            count($this->getIterableKeywords($testDefinition)) > 0
            ? $testDefinition->getName() . " " .
                $iteration[self::FIELD_PROJECT_NAMES] .
                ($iteration[self::FIELD_BRANCH_NAME] ? $this->separator . $iteration[self::FIELD_BRANCH_NAME] : '')
            : $testDefinition->getName();
    }

    /**
     * Get a branch name from a project based in the branch id.
     * @param ProjectModel  $project            the project
     * @param string        $branchId           the branch id
     * @param mixed         $populatedBranches  an array of populated branches, can be omitted so the model will get
     *                                          branches itself
     * @return mixed the branch name or null if the branch could not be found by the branch id
     */
    private function getBranchName(ProjectModel $project, string $branchId, $populatedBranches = null)
    {
        $branchName = null;
        try {
            $branchName = $project->getBranch($branchId, $populatedBranches)['name'];
        } catch (InvalidArgumentException $e) {
            $this->logger->err(
                sprintf(
                    "[%s]: Branch [%s] does not exist on project [%s], " .
                    "skipping test field population for that branch",
                    self::LOG_PREFIX,
                    $branchId,
                    $project->getName()
                )
            );
        }
        return $branchName;
    }

    /**
     * Processes the currentProjects and projects arrays to return lists of project ids & names and branch ids (which
     * are composed from both the project id and the branch id). Also history branch ids and branch name without
     * prefix of project to enable old project tests to be transferred into new world.
     *
     * @return array
     */
    protected function getProjectInfo() : array
    {
        $projectIds          = [];
        $projectNames        = [];
        $projectAndBranchIds = [];
        $branchIds           = [];
        $branchNames         = [];

        foreach ($this->affectedProjects as $projectId => $currentBranchIds) {
            if (isset($this->projects[$projectId])) {
                $project           = $this->projects[$projectId];
                $projectIds[]      = $projectId;
                $projectNames[]    = $project->getName();
                $populatedBranches = $project->getBranches();
                foreach ($currentBranchIds as $idx => $branchId) {
                    // Construct the branch id in the form of <projectId>:<branchId>
                    $projectAndBranchIds[] = sprintf('%s%s%s', $projectId, $this->separator, $branchId);
                    $branchName            =  $this->getBranchName($project, $branchId, $populatedBranches);
                    if ($branchName) {
                        $branchIds[]   =  $branchId;
                        $branchNames[] =  $branchName;
                    }
                }
            }
        }
        return [
            self::FIELD_PROJECTS      => implode(',', $projectIds),
            self::FIELD_PROJECT_NAMES => implode(',', $projectNames),
            self::FIELD_BRANCHES      => implode(',', $projectAndBranchIds),
            self::FIELD_BRANCH        => implode(',', $branchIds),
            self::FIELD_BRANCH_NAME   => implode(',', $branchNames)
        ];
    }

    /**
     * Convenience method to get a list of branch names, given branch ids
     *
     * @param ProjectModel $project
     * @param array        $branchIds
     *
     * @return array
     */
    protected function getBranchNamesFromIds(ProjectModel $project, array $branchIds) : array
    {
        $branches    = $project->getBranches();
        $branchNames = [];
        foreach ($branchIds as $branchId) {
            foreach ($branches as $branch) {
                if ($branch['id'] == $branchId) {
                    $branchNames[] = $branch['name'];
                    break;
                }
            }
        }

        return $branchNames;
    }

    /**
     * Creates a new TestRun instance
     * @param string    $testIdentifier     identifier for the test - either the test definition id or the project test
     *                                      identifier based on the project id.
     * @param string    $title              the title for the test
     * @param string    $status             initial status for the test
     * @param string    $branches           the branches for the test run. This value is stored on the test run so that
     *                                      any subsequent re-running of the test run will use the same branch data for
     *                                      test run with an upgrade level >= 2.
     * @return TestRun|null
     */
    protected function createTestRun(
        string $testIdentifier,
        string $title,
        string $status,
        string $branches = ''
    ) {
        $review  = $this->review;
        $testRun = null;
        $data    = [
            TestRun::FIELD_CHANGE     => $review->getId(),
            TestRun::FIELD_VERSION    => $review->getHeadVersion(),
            TestRun::FIELD_TEST       => $testIdentifier,
            TestRun::FIELD_START_TIME => time(),
            TestRun::FIELD_STATUS     => $status,
            TestRun::FIELD_UUID       => $review->getToken(),
            TestRun::FIELD_TITLE      => $title,
            // Always store the value on the test run with the ':' delimiter so that
            // we know what to expect when we see the field for a test rerun
            TestRun::FIELD_BRANCHES   => str_replace($this->separator, ':', $branches)
        ];

        $filter = $this->services->get(ITestRun::NAME);
        $filter->setData($data);

        if ($filter->isValid()) {
            $testRun = new TestRun();
            $testRun = $testRun->set($data);
            $this->testRunDao->save($testRun);
            $review->addTestRun($testRun->getId())->save();
        } else {
            $errors = $filter->getMessages();
            $this->logValidationErrors($errors);
        }

        return $testRun;
    }

    /**
     * Executes the actual CI request
     *
     * @param string   $url          url for the CI system to run the test
     * @param string   $description  description of the test
     * @param string   $postBody     request parameters that will be sent in the body or in the url
     * @param string   $postFormat   either url or json
     * @param array    $headers      headers, if specified
     * @param int      $timeout      optional timeout, defaults to 0 and is ignored if 0
     *
     * @return mixed
     */
    protected function doRequest(
        string $url,
        string $description,
        string $postFormat,
        string $postBody = '',
        array $headers = [],
        int $timeout = 0
    ) {
        $response = null;
        $logger   = $this->logger;

        // extract the http client options; including any special overrides for our host
        $options = $this->services->get(ConfigManager::CONFIG) + ['http_client_options' => []];
        $options = (array)$options['http_client_options'];

        // below function call is to encode the url path
        $url = $this->encodeUrlPath($url);

        // attempt a request for the given url to trigger tests.
        try {
            $client = new HttpClient;
            $client->setUri($url);
            $client->setHeaders($headers);

            // if we have post data, ensure we make a POST request
            if (!empty($postBody)) {
                $client->setMethod(Request::METHOD_POST);

                // parse body based on its format (url, xml or json)
                switch ($postFormat) {
                    case strcasecmp($postFormat, EncodingValidator::URL) === 0:
                        parse_str($postBody, $postParams);
                        $client->setParameterPost($postParams);
                        break;
                    default:
                        $client->setEncType(MimeType::$mimeTypes[strtolower($postFormat)]);
                        $client->setRawBody($postBody);
                        break;
                }
            }

            // calculate options, including host based overrides, and set them
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array)$options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            if ($timeout > 0) {
                $options['timeout'] = $timeout;
            }
            $client->setOptions($options);
            $logger->trace(get_class($this) . ': test dispatch request ' . var_export($client->getRequest(), true));
            // attempt trigger remote build.
            $response = $client->dispatch($client->getRequest());
        } catch (\Exception $e) {
            $logger->err($e);
        }
        return $response;
    }

    /**
     * Generate callback urls which can be used by the CI system to update a given test run
     *
     * @param int      $testRunId   id of the test run
     * @param mixed    $uuid        uuid of the test run
     *
     * @return array
     */
    protected function generateCallbackUrls(int $testRunId, $uuid) : array
    {
        $urlHelper = $this->services->get('ViewHelperManager')->get(ViewHelperFactory::QUALIFIED_URL);
        $params    = [
            IRequest::VERSION   => self::API_VERSION,
            TestRun::FIELD_ID   => $testRunId,
            TestRun::FIELD_UUID => $uuid
        ];

        return [
            self::FIELD_PASS   => $urlHelper(TestRunAPI::PASS_URL_ROUTE, $params),
            self::FIELD_FAIL   => $urlHelper(TestRunAPI::FAIL_URL_ROUTE, $params),
            self::FIELD_UPDATE => $urlHelper(TestRunAPI::UPDATE_NOAUTH_URL_ROUTE, $params)
        ];
    }

    /**
     * Handle the logging of filter validation errors
     * @param array $errors
     */
    protected function logValidationErrors(array $errors)
    {
        $message = "TestRun could not be created!\n";

        foreach ($errors as $field => $fieldErrors) {
            $message .= "$field => ";
            foreach ($fieldErrors as $type => $error) {
                $message .= "$type: $error\n";
            }
        }

        $this->logger->err($message);
    }

    /**
     * Ensure that the response from the CI system is valid
     * If the response is not valid we:
     *  - log that there was no response and if there was a response, add the reason and code
     *  - set the TestRun record's status as a fail
     *  - set the TestRun record's messages as 1-element array, containing a string indicating the code & reason for
     *    the validation failure or a message saying there was no response
     *
     * @param ITestRunModel   $testRun    TestRun record
     * @param string          $url        url of the CI system
     * @param Response|null   $response   response from the CI system or null
     */
    protected function validateResponse(ITestRunModel $testRun, string $url, $response)
    {
        // All good, nothing to do here, so exit early
        if ($response && $response->isSuccess()) {
            return;
        }
        if (is_null($response)) {
            $redactUrl = $this->redactUrl($url);
            $message   = sprintf("There was no response from %s", explode('?', $redactUrl)[0]);
        } else {
            $message = sprintf("%s:%s", $response->getStatusCode(), $response->getReasonPhrase());
        }
        $this->logger->err(sprintf("Failed to trigger %s: %s, %s", $testRun->getTest(), $url, $message));
        $testRun->setStatus(StatusValidator::STATUS_FAIL);
        $testRun->setMessages([$message]);
        $this->testRunDao->save($testRun);
    }

    /**
     * Escapes a string to deal with markdown, newlines and unicode
     * @param string   $dirty   string to be cleaned
     * @return string
     */
    protected function sanitize(string $dirty) : string
    {

        if (empty($dirty)) {
            $clean = '';
        } else {
            $parsedown = new \Parsedown();
            $markdown  = $parsedown->text($dirty);
            $stripped  = strip_tags($markdown);
            $clean     = htmlentities($stripped);
        }
        return $clean;
    }

    /**
     * As we don't want to show the user login or token we remove them from the url
     * We also remove query and hashes from the url as well.
     * @param string $url The url we send to test system.
     * @return string without user credentials, query fields and hashes.
     */
    protected function redactUrl(string $url) : string
    {
        $parts = parse_url($url);
        return $parts['scheme'] . '://' .
            $parts['host'] .
            (isset($parts['port']) ? ':'.$parts['port'] : '') .
            (isset($parts['path']) ? $parts['path'] : '');
    }

    /**
     * It encodes the url path and return the new url
     * @param string   $url url for the CI system to run the test
     * @return string
     */
    protected function encodeUrlPath(string $url) : string
    {
        $escaper = new Escaper();
        $url     = $escaper->escapeFullUrl(rawurldecode($url));
        return $url;
    }

    /**
     * Encode field values for expansions
     * @param array         $fields     key of expansion field to value
     * @param string        $encoding   whether we are dealing with URL encoding or JSON
     * @return bool
     */
    protected function encodeBodyExpansions(array &$fields, string $encoding) : bool
    {
        return array_walk($fields, [$this, 'encodeBodyExpansion'], $encoding);
    }

    /**
     * Encode a value according to the encoding type.
     * In a JSON encoded request we just want to encode any field expansion to be JSON safe rather than fully escaping
     * In a URL encoded request we fully escape fields in the body. For description we also strip tags and html entities
     * @param $value
     * @param $key
     * @param $encoding
     */
    protected function encodeBodyExpansion(&$value, $key, $encoding)
    {
        switch ($encoding) {
            case strcasecmp(EncodingValidator::JSON, $encoding) === 0:
                $value = trim(json_encode($value, JSON_UNESCAPED_SLASHES), '"');
                break;
            default:
                if ($key === self::FIELD_DESCRIPTION) {
                    $value = $this->sanitize($value);
                }
                $value = rawurlencode($value);
                break;
        }
    }

    /**
     * Copies previously passed test run results to a new test run and adds it to the latest revision of the review.
     * All the runs in 'previouslyPassed' are candidates for copy but they will only be copied if the test name is
     * also in 'previousPassedNames'. The names may have been manipulated due to onSubmit tests not causing a clone.
     * @param mixed     $review                 the review
     * @param array     $previouslyPassed       previously passed test runs
     * @param array     $previousPassedNames    previously passed test runs names.
     */
    private function cloneTestRuns($review, $previouslyPassed, $previousPassedNames)
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        foreach ($previouslyPassed as $testRun) {
            if (in_array($testRun->getTest(), $previousPassedNames)) {
                $newRun = new TestRun;
                $newRun->set($testRun->toArray());
                $newRun = $newRun->setId(null)->setTitle($testRun->getTitle() . ' (' . $translator->t('Copy') . ')');
                $newRun = $this->testRunDao->save($newRun);
                $review = $review->addTestRun($newRun->getId());
            }
        }
        $reviewDao = $this->services->get(IDao::REVIEW_DAO);
        $reviewDao->save($review);
    }

    /**
     * Get the test ids from the workflow tests for the given event(s)
     * @param mixed     $events             the events. Can be an event or array of events
     * @param mixed     $testsForWorkflows  map of events => test definition ids
     * @return array
     */
    private function getTestIds($events, $testsForWorkflows) : array
    {
        $testIds = [];
        if ($testsForWorkflows) {
            foreach ((array)$events as $event) {
                if (isset($testsForWorkflows[$event])) {
                    foreach ($testsForWorkflows[$event] as $id) {
                        $testIds[] = $id;
                    }
                }
            }
        }
        return $testIds;
    }

    /**
     * Fetch or create all the tests associated with the global workflow
     * and any other workflows linked to affected projects
     * @param array $testsForWorkflows      keys of event names with values of test ids. For example:
     * [
     *     'onUpdate' => [4, 7, 9],
     *     'onSubmit' => [1, 4, 10]
     * ]
     * @param array $previouslyPassedNames  any test names that passed on a previous revision of the review. When there
     *                                      is no content change previously passed tests will not be executed.
     * @return array
     */
    private function getWorkflowTests($testsForWorkflows, &$previouslyPassedNames = []) : array
    {
        $review  = $this->review;
        $testIds = $this->getTestIds(IWorkflow::EVENT_ON_UPDATE, $testsForWorkflows);
        // Remove previously passed 'onUpdate' tests.
        // 'onSubmit' will be checked next and they always run
        $testIds = array_diff($testIds, $previouslyPassedNames);
        $tests   = [];

        if (!$review->isPending()) {
            $onSubmitTestIds = $this->getTestIds(IWorkflow::EVENT_ON_SUBMIT, $testsForWorkflows);
            // Remove onSubmit ids from previously passed so that we do not clone them
            $previouslyPassedNames = array_diff($previouslyPassedNames, $onSubmitTestIds);
            $testIds               = array_unique(array_merge($testIds, $onSubmitTestIds));
        }
        $onDemandTestIds = $this->getTestIds(IWorkflow::EVENT_ON_DEMAND, $testsForWorkflows);
        $onDemandTestIds = array_diff($onDemandTestIds, $previouslyPassedNames);
        // Get the enabled test definitions
        if ($testIds || $onDemandTestIds) {
            $testDefinitionDAO  = $this->services->get(IDao::TEST_DEFINITION_DAO);
            $allTestDefinitions = [];
            if ($testIds) {
                $testDefinitions = $testDefinitionDAO->fetchAll(
                    [AbstractKey::FETCH_BY_IDS => $testIds],
                    $this->p4Admin
                );

                $allTestDefinitions[StatusValidator::STATUS_RUNNING] = $testDefinitions;
            }
            if ($onDemandTestIds) {
                $testDefinitions = $testDefinitionDAO->fetchAll(
                    [AbstractKey::FETCH_BY_IDS => $onDemandTestIds],
                    $this->p4Admin
                );

                $allTestDefinitions[StatusValidator::STATUS_NOT_STARTED] = $testDefinitions;
            }
            // Set review vars that will not change within the loop
            $reviewFields = $this->getFieldsFromReview($review);
            $reviewToken  = $review->getToken();
            $projectInfo  = $this->getProjectInfo();

            // Run a test for each test definition
            foreach ($allTestDefinitions as $testStatus => $testDefinitions) {
                foreach ($testDefinitions as $testDefinition) {
                    foreach ($this->getTestIterations($testDefinition, $projectInfo) as $idx => $iteration) {
                        // For an iterating test the name will include project:branch
                        $testName = $this->buildIterationName($testDefinition, $iteration);
                        $this->logger->info("getWorkflowTests-> Test run($idx) ".$testName);
                        // Create the test run record
                        $testRun = $this->createTestRun(
                            $testDefinition->getId(),
                            $testName,
                            $testStatus,
                            $iteration[self::FIELD_BRANCHES]
                        );

                        // Test run could not be created, or it is an on demand test, so we continue on
                        if (is_null($testRun) || $testStatus === StatusValidator::STATUS_NOT_STARTED) {
                            continue;
                        }

                        // Get the callback urls
                        $testRunId = $testRun->getId();
                        // Used to build out the CI request url
                        $fields = [
                            self::FIELD_TEST        => $testDefinition->getName(),
                            self::FIELD_TEST_RUN_ID => $testRunId,
                        ];
                        $fields = array_merge(
                            $fields,
                            $reviewFields,
                            $iteration,
                            $this->generateCallbackUrls($testRunId, $reviewToken)
                        );
                        array_push($tests, new TestRunData($testRun, $fields, $testDefinition));
                    }
                }
            }
        }
        return $tests;
    }

    /**
     * Fetch or create the project tests associated with a review
     * @param array $previouslyPassedNames  any test names that passed on a previous revision of the review. When there
     *                                      is no content change previously passed tests will not be executed.
     * @return array
     */
    private function getProjectTests($previouslyPassedNames = []) : array
    {
        $review = $this->review;

        // Set review vars that will not change within the loop
        $reviewFields = $this->getFieldsFromReview($review);
        $reviewToken  = $review->getToken();
        $tests        = [];

        // reviews can impact multiple projects and each project can have its own test config
        // note we only include projects/branches the change being processed impacts.
        foreach ($this->affectedProjects as $projectId => $branchIds) {
            // get the full project object and the list of impacted branch names
            $project         = $this->projects[$projectId] ?? null;
            $branchNames     = $this->getBranchNamesFromIds($project, $branchIds);
            $projectTestName = $this->getProjectTestName($projectId);
            // if enabled, configured and not previously passed, run automated tests
            if ($project
                && $project->getTests('enabled')
                && $project->getTests('url')
                && !in_array($projectTestName, $previouslyPassedNames)) {
                $testRun = $this->createTestRun($projectTestName, $project->getName(), StatusValidator::STATUS_RUNNING);

                // Test run could not be created, so we continue on
                if (is_null($testRun)) {
                    continue;
                }

                // Get the callback urls
                $testRunId = $testRun->getId();
                $fields    = [
                    self::FIELD_TEST         => $testRun->getTest(),
                    self::FIELD_TEST_RUN_ID  => $testRunId,
                    self::FIELD_PROJECT      => $project->getId(),
                    self::FIELD_PROJECT_NAME => $project->getName(),
                    self::FIELD_BRANCH       => implode(',', $branchIds),
                    self::FIELD_BRANCH_NAME  => implode(',', $branchNames),
                ];

                $fields = array_merge($fields, $reviewFields, $this->generateCallbackUrls($testRunId, $reviewToken));
                array_push($tests, new TestRunData($testRun, $fields, null, $project));
            }

            // if enabled and configured, run deploy
            if ($project && $project->getDeploy('enabled') && $project->getDeploy('url')) {
                // compose deploy success/fail callback urls.
                $urlHelper        = $this->services->get('ViewHelperManager')->get(ViewHelperFactory::QUALIFIED_URL);
                $deploySuccessUrl = $urlHelper(
                    'review-deploy',
                    ['review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'success']
                );
                $deployFailUrl    = $urlHelper(
                    'review-deploy',
                    ['review' => $review->getId(), 'token' => $review->getToken(), 'status' => 'fail']
                );
                // customize test url for this project.
                $search  = [
                    self::FIELD_CHANGE,
                    self::FIELD_STATUS,
                    self::FIELD_REVIEW,
                    self::FIELD_PROJECT,
                    self::FIELD_PROJECT_NAME,
                    self::FIELD_BRANCH,
                    self::FIELD_BRANCH_NAME,
                    self::FIELD_SUCCESS,
                    self::FIELD_FAIL
                ];
                $replace = array_map(
                    'rawurlencode',
                    [
                        $reviewFields[self::FIELD_CHANGE],
                        $reviewFields[self::FIELD_STATUS],
                        $reviewFields[self::FIELD_REVIEW],
                        $project->getId(),
                        $project->getName(),
                        implode(',', $branchIds),
                        implode(',', $branchNames),
                        $deploySuccessUrl,
                        $deployFailUrl
                    ]
                );

                $url = str_replace($search, $replace, $project->getDeploy('url'));
                // Preset the status to deploying while the request to deploy is in progress, the deploy environment
                // is expected to record successful deployment
                $review->set('deployStatus', 'deploying')->save();
                // Issue the deployment request
                $response = $this->doRequest($url, 'automated deploy', EncodingValidator::URL);
                // Record any failure in the deployment request
                if (is_null($response)) {
                    $redactUrl = $this->redactUrl($url);
                    $review->set(
                        'deployStatus',
                        'failed:'.sprintf("There was no response from %s", explode('?', $redactUrl)[0])
                    );
                } elseif (!$response->isSuccess()) {
                    $review->set(
                        'deployStatus',
                        'failed:'.sprintf("%s:%s", $response->getStatusCode(), $response->getReasonPhrase())
                    );
                }
                $review->save();
            }
        }
        return $tests;
    }

    /**
     * Get an array of fields based on review information
     * @param mixed     $review     the review
     * @return array
     */
    private function getFieldsFromReview($review) : array
    {
        return [
            self::FIELD_CHANGE        => $review->getHeadChange(true),
            self::FIELD_STATUS        => $review->isPending() ? 'shelved' : 'submitted',
            self::FIELD_REVIEW        => $review->getId(),
            self::FIELD_VERSION       => $review->getHeadVersion(),
            self::FIELD_REVIEW_STATUS => $review->getState(),
            self::FIELD_DESCRIPTION   => $review->getDescription()
        ];
    }

    /**
     * Validates that the test run id is associated with the latest revision of the review provided
     * @param mixed     $review     the review
     * @param mixed     $testRunId  the test run id
     * @throws InvalidArgumentException if the test run id is not associated with the latest revision of the review
     */
    private function validateTestRunId($review, $testRunId)
    {
        $valid = false;
        foreach ($review->getTestRuns() as $reviewTestRunId) {
            if ($reviewTestRunId == $testRunId) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new InvalidArgumentException(
                sprintf(
                    "Test run id [%s] is not associated with the latest revision of review id [%s]",
                    $testRunId,
                    $review->getId()
                )
            );
        }
    }
}
