<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

use Application\Model\ServicesModelTrait;
use P4\Log\Logger;
use Record\Key\AbstractKey;
use TestIntegration\Service\ITestExecutor;
use P4\Exception;

/**
 * Class TestRun to define the model for review or change test runs
 * @package TestIntegration\Model
 */
class TestRun extends AbstractKey implements ITestRun
{
    use ServicesModelTrait;
    // Perforce fields
    const KEY_PREFIX    = 'swarm-testRun-';
    const KEY_COUNT     = 'swarm-testRun:count';
    const UPGRADE_LEVEL = 2;

    // Search fields
    const FETCH_BY_CHANGE = 'change';

    protected $fields = [
        self::FIELD_CHANGE => [
            self::INDEX    => 1801,
            self::ACCESSOR => 'getChange',
            self::MUTATOR  => 'setChange'
        ],
        self::FIELD_VERSION => [
            self::ACCESSOR => 'getVersion',
            self::MUTATOR  => 'setVersion'
        ],
        self::FIELD_TEST => [
            self::ACCESSOR => 'getTest',
            self::MUTATOR  => 'setTest'
        ],
        self::FIELD_START_TIME => [
            self::ACCESSOR => 'getStartTime',
            self::MUTATOR  => 'setStartTime'
        ],
        self::FIELD_COMPLETED_TIME => [
            self::ACCESSOR => 'getCompletedTime',
            self::MUTATOR  => 'setCompletedTime'
        ],
        self::FIELD_STATUS => [
            self::ACCESSOR => 'getStatus',
            self::MUTATOR  => 'setStatus'
        ],
        self::FIELD_MESSAGES => [
            self::ACCESSOR => 'getMessages',
            self::MUTATOR  => 'setMessages'
        ],
        self::FIELD_URL => [
            self::ACCESSOR => 'getUrl',
            self::MUTATOR  => 'setUrl'
        ],
        self::FIELD_UUID => [
            self::ACCESSOR => 'getUuid',
            self::MUTATOR  => 'setUuid'
        ],
        self::FIELD_TITLE => [
            self::ACCESSOR => 'getTitle',
            self::MUTATOR  => 'setTitle'
        ],
        self::FIELD_BRANCHES => [
            self::ACCESSOR => 'getBranches',
            self::MUTATOR  => 'setBranches'
        ],
        self::FIELD_UPGRADE => ['hidden' => true]
    ];

    /**
     * @inheritDoc
     */
    public function getChange(): int
    {
        return $this->getRawValue(self::FIELD_CHANGE);
    }

    /**
     * @inheritDoc
     */
    public function setChange(int $change)
    {
        return $this->setRawValue(self::FIELD_CHANGE, $change);
    }

    /**
     * @inheritDoc
     */
    public function getVersion()
    {
        return $this->getRawValue(self::FIELD_VERSION);
    }

    /**
     * @inheritDoc
     */
    public function setVersion(int $version)
    {
        return $this->setRawValue(self::FIELD_VERSION, $version);
    }

    /**
     * @inheritDoc
     */
    public function getTest(): string
    {
        return $this->getRawValue(self::FIELD_TEST);
    }

    /**
     * @inheritDoc
     */
    public function setTest(string $test)
    {
        return $this->setRawValue(self::FIELD_TEST, $test);
    }

    /**
     * @inheritDoc
     */
    public function getStatus()
    {
        return $this->getRawValue(self::FIELD_STATUS);
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $status)
    {
        return $this->setRawValue(self::FIELD_STATUS, $status);
    }

    /**
     * @inheritDoc
     */
    public function getUrl()
    {
        return $this->getRawValue(self::FIELD_URL);
    }

    /**
     * @inheritDoc
     */
    public function setUrl($url)
    {
        return $this->setRawValue(self::FIELD_URL, $url);
    }

    /**
     * @inheritDoc
     */
    public function getMessages()
    {
        return $this->getRawValue(self::FIELD_MESSAGES);
    }

    /**
     * @inheritDoc
     */
    public function setMessages($messages)
    {
        return $this->setRawValue(self::FIELD_MESSAGES, $messages ? $messages : []);
    }

    /**
     * @inheritDoc
     */
    public function getStartTime()
    {
        return $this->getRawValue(self::FIELD_START_TIME);
    }

    /**
     * @inheritDoc
     */
    public function setStartTime($startTime)
    {
        return $this->setRawValue(self::FIELD_START_TIME, $startTime);
    }

    /**
     * @inheritDoc
     */
    public function getCompletedTime()
    {
        return $this->getRawValue(self::FIELD_COMPLETED_TIME);
    }

    /**
     * @inheritDoc
     */
    public function setCompletedTime($completedTime)
    {
        return $this->setRawValue(self::FIELD_COMPLETED_TIME, $completedTime);
    }

    /**
     * @inheritDoc
     */
    public function setUuid($uuid)
    {
        return $this->setRawValue(self::FIELD_UUID, $uuid);
    }

    /**
     * @inheritDoc
     */
    public function getUuid()
    {
        return $this->getRawValue(self::FIELD_UUID);
    }

    /**
     * @inheritDoc
     */
    public function getTitle()
    {
        return $this->getRawValue(self::FIELD_TITLE);
    }

    /**
     * @inheritDoc
     */
    public function setTitle($title)
    {
        return $this->setRawValue(self::FIELD_TITLE, $title);
    }

    /**
     * @inheritDoc
     */
    public function setBranches($branches)
    {
        return $this->setRawValue(self::FIELD_BRANCHES, $branches);
    }

    /**
     * @inheritDoc
     */
    public function getBranches()
    {
        return $this->getRawValue(self::FIELD_BRANCHES) ?:  '';
    }

    /**
     * Upgrade a test run to a new schema version
     *
     * Level 1: If the test run is not for a project test find the test definition by name by looking at the 'test'
     *          value of the run and then update that value to the id of the test definition.
     *
     * @param AbstractKey|null $stored
     * @throws Exception
     */
    protected function upgrade(AbstractKey $stored = null)
    {
        // For new runs set the current level
        if (!$stored) {
            $this->set(self::FIELD_UPGRADE, static::UPGRADE_LEVEL);
            return;
        }
        // Exit early for runs already upgraded
        if ((int)$stored->get(self::FIELD_UPGRADE) >= static::UPGRADE_LEVEL) {
            return;
        }
        $this->original = null;
        $upgradeError   = false;
        // Upgrade to level 1
        //  - change 'test' value to use TestDefinition id instead of test name for global tests
        if ((int)$stored->get(self::FIELD_UPGRADE) === 0) {
            if (strpos($stored->getTest(), ITestExecutor::PROJECT_PREFIX) === 0) {
                // Nothing to do for project settings tests
                $this->set(self::FIELD_UPGRADE, 1);
            } else {
                // This is a global test - it does not start with the project prefix
                $testName    = $stored->getTest();
                $dao         = self::getTestDefinitionDao();
                $definitions = $dao->fetchAll(
                    [
                        self::FETCH_BY_KEYWORDS     => $testName,
                        self::FETCH_KEYWORDS_FIELDS => [ITestDefinition::FIELD_NAME]
                    ],
                    $this->getConnection()
                );
                // Only set the upgrade level if we have found the definition, if there is a problem we might
                // want to try again next time it is saved
                if ($definitions && count($definitions) === 1) {
                    $this->setTest($definitions->first()->getId());
                    $this->set(self::FIELD_UPGRADE, 1);
                } else {
                    $upgradeError = true;
                    Logger::log(
                        Logger::ERR,
                        sprintf("TestRun upgrade could not find a test definition for name [%s]", $testName)
                    );
                }
            }
        }
        if ((int)$stored->get(self::FIELD_UPGRADE) < 2) {
            // There are currently no upgrade tests to perform, just lift the level as long as the previous upgrade
            // did not fail
            if (!$upgradeError) {
                $this->set(self::FIELD_UPGRADE, 2);
            }
        }
    }
}
