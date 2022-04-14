<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Listener;

use Application\Config\ConfigException;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;
use TestIntegration\Service\ITestExecutor;
use TestIntegration\Service\TestExecutor;

/**
 * Class ReviewTestRuns. A listener to run the review test for the current version
 * @package Reviews\Listener
 */
class ReviewTestRuns extends AbstractEventListener
{
    const EVENT_NAME = "reviewTestRunsData";
    const DATA       = "data";
    const REVIEW_ID  = "reviewId";

    /**
     * @var mixed
     */
    private $review;

    /**
     * Process both workflow and project test runs for a the current review version.
     * @param Event $event the event
     * @throws ConfigException
     */
    public function processTests(Event $event)
    {
        parent::log($event);
        $params       = $event->getParam(self::EVENT_NAME);
        $this->logger = $this->services->get(SwarmLogger::SERVICE);

        if ($params) {
            $reviewDao    = $this->services->get(IDao::REVIEW_DAO);
            $p4Admin      = $this->services->get(ConnectionFactory::P4_ADMIN);
            $review       = $reviewDao->fetchNoCheck($params[self::REVIEW_ID], $p4Admin);
            $this->review = $review;
            $this->runWorkflowTests($params[self::DATA][TestExecutor::WORKFLOW_TEST_RUNS_DATA]);
            $this->runProjectTests($params[self::DATA][TestExecutor::PROJECT_TEST_RUNS_DATA]);
        } else {
            $this->logger->debug("No test runs data. Nothing to execute.");
        }
    }

    /**
     * Run workflow tests through the test executor for the given test runs.
     * @param array $testRunsData the testRunsData for a review version
     */
    public function runWorkflowTests(array $testRunsData)
    {
        $testExecutor = $this->services->get(ITestExecutor::NAME);

        foreach ($testRunsData as $testRunData) {
            $testExecutor->runTest(
                $testRunData->getTestDefinition(),
                $testRunData->getFields(),
                $testRunData->getTestRun(),
                $this->review
            );
        }
    }

    /**
     * Run project tests through the test executor for the given test runs.
     * @param array $testRunsData the testRunsData for a review version
     */
    public function runProjectTests(array $testRunsData)
    {
        $testExecutor   = $this->services->get(ITestExecutor::NAME);
        $testStartTimes = [];

        foreach ($testRunsData as $testRunData) {
            $testExecutor->runProjectTest(
                $testRunData->getProject(),
                $testRunData->getFields(),
                $testRunData->getTestRun(),
                $testStartTimes,
                $this->review
            );
        }

        if (count($testRunsData) > 0) {
            $details = $this->review->getTestDetails(true);
            if ($testStartTimes || count($details['startTimes']) > count($details['endTimes'])) {
                $this->review->setTestDetails(
                    ['startTimes' => $testStartTimes, 'endTimes' => []] + $details
                )->save();
            }
        }
    }
}
