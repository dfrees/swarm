<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

/**
 * Class TestRunData. To create objects with test runs data.
 * @package TestIntegration\Model
 */
class TestRunData
{
    private $testRun;
    private $fields;
    private $testDefinition;
    private $project;

    /**
     * TestRunData constructor.
     * @param mixed $testRun
     * @param array $fields
     * @param mixed $testDefinition
     * @param mixed $project
     */
    public function __construct($testRun, array $fields, $testDefinition = null, $project = null)
    {
        $this->testRun        = $testRun;
        $this->fields         = $fields;
        $this->testDefinition = $testDefinition;
        $this->project        = $project;
    }

    /**
     * Get the test run
     * @return mixed
     */
    public function getTestRun()
    {
        return $this->testRun;
    }

    /**
     * Get the fields
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get the test definition
     * @return mixed
     */
    public function getTestDefinition()
    {
        return $this->testDefinition;
    }

    /**
     * Get the project
     * @return mixed
     */
    public function getProject()
    {
        return $this->project;
    }
}
