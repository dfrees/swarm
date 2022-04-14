<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration\Service;

use Application\Config\ConfigException;
use Application\Factory\InvokableService;
use P4\Model\Fielded\Iterator;
use Projects\Model\Project;
use Record\Key\AbstractKey;
use TestIntegration\Model\ITestDefinition;
use TestIntegration\Model\ITestRun as ITestRunModel;
use Record\Exception\NotFoundException as RecordNotFoundException;
use InvalidArgumentException;

/**
 * Interface ITestExecutor
 * @package TestIntegration\Service
 */
interface ITestExecutor extends InvokableService
{
    const NAME = 'testExecutorService';
    // Test run name for project tests is 'project:<project id>:<test name>
    const PROJECT_TEST_SEPARATOR = ':';
    const PROJECT_PREFIX         = 'project' . self::PROJECT_TEST_SEPARATOR;
    const PROJECT_TEST_FORMAT    = self::PROJECT_PREFIX . "%s" . self::PROJECT_TEST_SEPARATOR . "%s";

    const FIELD_PROJECTS      = '{projects}';
    const FIELD_PROJECT       = '{project}';
    const FIELD_PROJECT_NAME  = '{projectName}';
    const FIELD_BRANCH        = '{branch}';
    const FIELD_BRANCH_NAME   = '{branchName}';
    const FIELD_BRANCHES      = '{branches}';
    const FIELD_PROJECT_NAMES = '{projectNames}';
    const FIELD_TEST          = '{test}';
    const FIELD_TEST_RUN_ID   = '{testRunId}';
    const FIELD_CHANGE        = '{change}';
    const FIELD_STATUS        = '{status}';
    const FIELD_REVIEW        = '{review}';
    const FIELD_SUCCESS       = '{success}';
    const FIELD_FAIL          = '{fail}';
    const FIELD_PASS          = '{pass}';
    const FIELD_UPDATE        = '{update}';
    const FIELD_VERSION       = '{version}';
    const FIELD_REVIEW_STATUS = '{reviewStatus}';
    const FIELD_DESCRIPTION   = '{description}';
    const BRANCH_KEYWORDS     = ['branches', 'branch'];
    const ITERABLE_KEYWORDS   = ['projects', 'branches', 'branch'];

    /**
     * Fetch or create all the tests associated with a given review, its projects and their workflows
     * @param AbstractKey $review           review
     * @param array       $affectedProjects maps each affected project's id to its branch ids
     *                                      Ex. ['proj1' => ['branchA', 'branchB'], 'proj2' => ['branchY', 'branchX']]
     * @param Iterator    $projects         instances of Project models, directly associated with the review
     * @param array       $options          extra parameter of options can be passed to function
     * @return mixed
     */
    public function getReviewTests(
        AbstractKey $review,
        array $affectedProjects,
        Iterator $projects,
        array $options = []
    );

    /**
     * Runs a single test
     * @param ITestDefinition $testDefinition   test definition
     * @param array           $fields           key-value pairs, used to build the query string for the CI request
     * @param ITestRunModel   $testRun          test run record
     * @param AbstractKey     $review           review object
     * @return array|null request values
     */
    public function runTest(
        ITestDefinition $testDefinition,
        array $fields,
        ITestRunModel $testRun,
        AbstractKey $review
    );

    /**
     * Runs a single project test
     * @param Project         $project          project object with test definition information
     * @param array           $fields           key-value pairs, used to build the query string for the CI request
     * @param ITestRunModel   $testRun          test run record
     * @param array           $testStartTimes   holds the start times of all the project tests
     * @param AbstractKey     $review           review object
     * @return array|null request values
     */
    public function runProjectTest(
        Project $project,
        array $fields,
        ITestRunModel $testRun,
        array &$testStartTimes,
        AbstractKey $review
    );

    /**
     * Start a test. Can be used to re-run an existing test or start an on demand test
     * @param mixed $reviewId     the review id
     * @param mixed $testRunId    the test run id
     * @throws ConfigException
     * @throws RecordNotFoundException if the review id or test run id is not valid
     * @throws InvalidArgumentException if the test run is already running
     * @return ITestRunModel the test run started
     */
    public function startTest($reviewId, $testRunId) : ITestRunModel;
}
