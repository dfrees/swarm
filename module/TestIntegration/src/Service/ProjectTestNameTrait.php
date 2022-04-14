<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Service;

/**
 * Trait ProjectTestNameTrait to help build and decode project identifiers from
 * test names.
 * @package TestIntegration\Service
 */
trait ProjectTestNameTrait
{
    /**
     * Builds a test name using the project and test name
     * @param mixed $projectId  the project id
     * @param mixed $testName   the test name, defaults to 'test'
     * @return string
     */
    public function getProjectTestName($projectId, $testName = 'test')
    {
        return sprintf(ITestExecutor::PROJECT_TEST_FORMAT, $projectId, $testName);
    }

    /**
     * Gets the project id from the test name
     * @param $testName
     * @return string|null the project id or null if it can not be determined
     */
    public function getProjectIdFromTestName($testName)
    {
        $parts = explode(ITestExecutor::PROJECT_TEST_SEPARATOR, $testName);
        return sizeof($parts) > 1 ? $parts[1] : null;
    }

    /**
     * Test if the test name is for a project
     * @param mixed $testName   the test name
     * @return bool true of the test name has the project prefix
     */
    public function isProjectTestName($testName) : bool
    {
        return strpos($testName, ITestExecutor::PROJECT_PREFIX) === 0;
    }
}
