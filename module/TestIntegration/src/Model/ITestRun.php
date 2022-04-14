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
 * Interface ITestRun describing a test run.
 * @package TestIntegration\Model
 */
interface ITestRun
{
    // Model Fields
    const FIELD_ID             = 'id';
    const FIELD_CHANGE         = 'change';
    const FIELD_VERSION        = 'version';
    const FIELD_TEST           = 'test';
    const FIELD_START_TIME     = 'startTime';
    const FIELD_COMPLETED_TIME = 'completedTime';
    const FIELD_STATUS         = 'status';
    const FIELD_MESSAGES       = 'messages';
    const FIELD_URL            = 'url';
    const FIELD_UUID           = 'uuid';
    const FIELD_TITLE          = 'title';
    const FIELD_UPGRADE        = 'upgrade';
    const FIELD_BRANCHES       = 'branches';
    // Queue task for test run changes
    const TASK_TEST_RUN = 'task.testrun';

    /**
     * Get the change id
     * @return int
     */
    public function getChange() : int;

    /**
     * Set the change id
     * @param int $change
     * @return mixed
     */
    public function setChange(int $change);

    /**
     * Get the review version
     * @return mixed
     */
    public function getVersion();

    /**
     * Set the review version
     * @param int $version
     * @return mixed
     */
    public function setVersion(int $version);

    /**
     * Get the name of the test
     * @return string
     */
    public function getTest() : string;

    /**
     * Set the name of the test
     * @param string $test
     * @return mixed
     */
    public function setTest(string $test);

    /**
     * Get the status
     * @return mixed
     */
    public function getStatus();

    /**
     * Set the status
     * @param string $status
     * @return mixed
     */
    public function setStatus(string $status);

    /**
     * Get the url
     * @return string
     */
    public function getUrl();

    /**
     * Set the url
     * @param mixed $url
     * @return mixed
     */
    public function setUrl($url);

    /**
     * Get the messages
     * @return mixed
     */
    public function getMessages();

    /**
     * Set the messages
     * @param mixed $messages
     * @return mixed
     */
    public function setMessages($messages);

    /**
     * Get the start time
     * @return int
     */
    public function getStartTime();

    /**
     * Set the start time
     * @param mixed $startTime
     * @return mixed
     */
    public function setStartTime($startTime);

    /**
     * Get the completed time
     * @return mixed
     */
    public function getCompletedTime();

    /**
     * Set the completed time
     * @param int|null $completedTime
     * @return mixed
     */
    public function setCompletedTime($completedTime);

    /**
     * Set the uuid
     * @param string|null $uuid
     * @return mixed
     */
    public function setUuid($uuid);


    /**
     * Get the uuid
     * @return mixed
     */
    public function getUuid();

    /**
     * Get the title
     * @return mixed
     */
    public function getTitle();

    /**
     * Set the title
     * @param mixed     $title    the title
     * @return mixed
     */
    public function setTitle($title);

    /**
     * Set the branches related to this test run.
     * @param mixed     $branches     the branches
     * @return mixed
     */
    public function setBranches($branches);

    /**
     * Get the branches related to this test run
     * @return mixed the branches for this test run or an empty string if not set
     */
    public function getBranches();
}
