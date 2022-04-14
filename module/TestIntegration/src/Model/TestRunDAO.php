<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

use Application\Factory\InvokableService;
use Application\Option;
use Events\Listener\ListenerFactory;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator;
use Queue\Manager;
use Record\Exception\NotFoundException;
use TestIntegration\Filter\StatusValidator;
use TestIntegration\Model\TestRun as Model;
use Record\Key\AbstractKey;

/**
 * Class TestRunDAO to fetch/build/save TestRun
 * @package TestIntegration\Model
 */
class TestRunDAO implements InvokableService
{
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Fetch a test run by its identifier
     * @param mixed $id id to fetch
     * @param ConnectionInterface|null $connection
     * @return AbstractKey
     * @throws NotFoundException
     */
    public function fetch($id, ConnectionInterface $connection = null)
    {
        return Model::fetch($id, $connection);
    }

    /**
     * Fetch all test runs with the restriction to only fetch all or by (id)s
     *
     * @param  array                      $options        FETCH_BY_IDS an array of ids of test runs to fetch
     * @param  ConnectionInterface|null   $connection     a specific connection to use (optional)
     *
     * @return Iterator|array
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        // Validate that the given options are supported
        Option::validate($options, [Model::FETCH_BY_IDS => null]);

        if (isset($options[Model::FETCH_BY_IDS])) {
            // Cast the ids to an array
            $ids    = (array)$options[Model::FETCH_BY_IDS];
            $numIds = count($ids);

            if ($numIds === 0) {
                $models = new Iterator();
            } elseif ($numIds === 1) {
                try {
                    $run    = Model::fetch(current($ids), $connection);
                    $models = new Iterator([$run->getId() => $run]);
                } catch (NotFoundException $nfe) {
                    $models = new Iterator();
                }
            } else {
                $models = Model::fetchAll($options, $connection);
            }
        } else {
            $models = Model::fetchAll($options, $connection);
        }

        return $models;
    }

    /**
     * Save the model and place a task on the queue to let listeners know of an update
     * @param ITestRun $model   model to save
     * @return mixed the saved model
     */
    public function save(ITestRun $model)
    {
        $queue = $this->services->get(Manager::SERVICE);
        $model = $model->save();
        $queue->addTask(
            ListenerFactory::TEST_RUN,
            $model->getId(),
            [
                Model::FIELD_CHANGE  => $model->getChange(),
                Model::FIELD_VERSION => $model->getVersion()
            ]
        );
        return $model;
    }

    /**
     * Calculate an overall test status based on the statuses for all the test runs
     * @param array $ids    test run ids to fetch
     * @param ConnectionInterface|null $connection
     * @return string|null the overall status or null if no tests runs are found
     */
    public function calculateTestStatus(array $ids, ConnectionInterface $connection = null)
    {
        return $this->calculateTestStatusForTestRuns(
            $this->fetchAll(
                [Model::FETCH_BY_IDS => $ids],
                $connection
            )->toArray(true)
        );
    }

    /**
     * Calculate an overall test status based on the statuses for all the test runs
     * @param array $testRuns    test run models
     * @return string|null the overall status or null if no tests runs are found
     */
    public function calculateTestStatusForTestRuns(array $testRuns)
    {
        $status = null;
        if ($testRuns) {
            $testRunStatuses = [
                StatusValidator::STATUS_RUNNING     => 0,
                StatusValidator::STATUS_PASS        => 0,
                StatusValidator::STATUS_FAIL        => 0,
                StatusValidator::STATUS_NOT_STARTED => 0
            ];
            foreach ($testRuns as $testRun) {
                $testRunStatuses[$testRun->getStatus()] = $testRunStatuses[$testRun->getStatus()] + 1;
            }
            if ($testRunStatuses[StatusValidator::STATUS_RUNNING] > 0
                || $testRunStatuses[StatusValidator::STATUS_NOT_STARTED] > 0) {
                $status = StatusValidator::STATUS_RUNNING;
            } elseif ($testRunStatuses[StatusValidator::STATUS_FAIL] > 0) {
                $status = StatusValidator::STATUS_FAIL;
            } else {
                $status = StatusValidator::STATUS_PASS;
            }
        }
        return $status;
    }
}
