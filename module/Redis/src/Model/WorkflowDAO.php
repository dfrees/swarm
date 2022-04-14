<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IDao;
use Application\Permissions\Exception\ForbiddenException;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Permissions\PrivateProjects;
use Events\Listener\ListenerFactory;
use P4\Connection\ConnectionInterface;
use Projects\Model\Project as ProjectModel;
use Queue\Manager;
use Record\Exception\NotFoundException;
use Redis\RedisService;
use Workflow\Model\IWorkflow;
use Workflow\Model\Workflow;

/**
 * DAO to handle data access for the Workflow model
 * @package Redis\Model
 */
class WorkflowDAO extends AbstractDAO
{
    const CACHE_KEY_PREFIX = IWorkflow::WORKFLOW . RedisService::SEPARATOR;
    const MODEL            = Workflow::class;
    const POPULATED_STATUS = IWorkflow::WORKFLOW . "-". AbstractDAO::POPULATED_STATUS;
    // The key for the verify status of the workflow dataset
    const VERIFY_STATUS = IWorkflow::WORKFLOW  . "-" . AbstractDAO::VERIFY_STATUS;

    /**
     * @inheritDoc
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        if (isset($options[Workflow::FETCH_BY_KEYWORDS])) {
            // Fall back to Perforce for key searches
            return Workflow::fetchAll($options, $connection);
        } else {
            return parent::fetchAll($options, $connection);
        }
    }

    /**
     * Delete the workflow
     * @param mixed $model  model to delete
     * @return mixed
     * @throws ForbiddenException if an attempt is made to delete the global workflow or if the workflow is in use on
     * any projects
     * @throws NotFoundException if the user has no edit permission
     */
    public function delete($model)
    {
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        if ($model->isGlobal()) {
            throw new ForbiddenException($translator->t('Cannot delete the global workflow'));
        } else {
            $projectDao = $services->get(IDao::PROJECT_DAO);
            $p4Admin    = $services->get(ConnectionFactory::P4_ADMIN);
            $projects   = $projectDao->fetchAll([ProjectModel::FIELD_WORKFLOW => (string)$model->getId()], $p4Admin);
            $allCount   = count($projects);
            if (!$projects || count($projects) === 0) {
                $p4User = $services->get(ConnectionFactory::P4_USER);
                if ($model->canEdit($p4User)) {
                    $model->setConnection($p4Admin);
                    return parent::delete($model);
                } else {
                    // No permission - we'll treat this as not found so as to not give an clues
                    throw new NotFoundException($translator->t('Cannot fetch entry. Id does not exist.'));
                }
            } else {
                $projects = $services->get(PrivateProjects::PROJECTS_FILTER)->filter($projects);
                $inUseOn  = array_map(
                    function ($project) {
                        return $project->getName();
                    },
                    $projects->getArrayCopy()
                );
                $inUseCount = count($inUseOn);
                if ($inUseCount === 0) {
                    $message = $translator->t('Cannot delete workflow [%s], it is in use', [$model->getId()]);
                } else {
                    $andOthers = $inUseCount === $allCount ? '' : ' and others';
                    $message   = $translator->tp(
                        'Cannot delete workflow [%s], it is in use on project [%s]' . $andOthers,
                        'Cannot delete workflow [%s], it is in use on projects [%s]' . $andOthers,
                        count($inUseOn),
                        [$model->getId(), implode(', ', $inUseOn)]
                    );
                }
                throw new ForbiddenException($message);
            }
        }
    }

    /**
     * Copy the global workflow configuration from the config.php into a key record if it does not already exist.
     *
     * @throws ConfigException         if the configuration is corrupt
     * @return bool true if global workflow data was created, false if no work was done as it already existed
     */
    public function importGlobalWorkflow()
    {
        // Getting the connection up front allows there to be a default connection for testing purposes
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        if ($this->exists(IWorkflow::GLOBAL_WORKFLOW_ID, $p4Admin)) {
            // Already imported nothing to to
            return false;
        }
        $owner          = $p4Admin->getUser();
        $globalWorkflow = (new Workflow($p4Admin))
            ->setId(IWorkflow::GLOBAL_WORKFLOW_ID)
            ->set(IWorkflow::UPGRADE, Workflow::UPGRADE_LEVEL)
            ->setOwners([$owner])
            ->setName(IWorkflow::GLOBAL_WORKFLOW_NAME)
            ->setDescription('');

        // Get the rules from the config manager, this will have merged the config.php with the defaults
        $rules  = ConfigManager::getValue($this->services->get(ConfigManager::CONFIG), IWorkflow::WORKFLOW_RULES);
        $fields = [
            IWorkflow::ON_SUBMIT,
            IWorkflow::END_RULES,
            IWorkflow::COUNTED_VOTES,
            IWorkflow::AUTO_APPROVE,
            IWorkflow::GROUP_EXCLUSIONS,
            IWorkflow::USER_EXCLUSIONS
        ];
        foreach ($fields as $field) {
            $globalWorkflow->set($field, $rules[$field]);
        }
        $this->save($globalWorkflow);
        // Queue an activity task to record who/when
        $queue = $this->services->get(Manager::SERVICE);
        $queue->addTask(
            ListenerFactory::WORKFLOW_CREATED,
            IWorkflow::GLOBAL_WORKFLOW_ID,
            [ListenerFactory::USER => $owner]
        );
        $queue->addTask(
            ListenerFactory::WORKFLOW_UPGRADE_SCHEMA,
            ListenerFactory::WORKFLOW_SCHEMA_VERSION,
            [IWorkflow::UPGRADE => Workflow::UPGRADE_LEVEL]
        );
        return true;
    }

    /**
     * Get a list of tests to run based on workflows.
     * @param array                     $workflowIds        ids of workflows to examine
     * @param array|null                $events             array of events to limit results by
     * @param ConnectionInterface|null  $connection         connection to use
     * @return array keys of event names with values of unique test ids collected from the workflows tests. For example:
     * [
     *     'onUpdate' => [4, 7, 9],
     *     'onSubmit' => [1, 4, 10],
     *     'onDemand' => [1, 4, 8]
     * ]
     * If events are specified results will be limited to just those events. For example for events value of
     * ['onUpdate'] when computing the same workflows as the previous example would result in
     * [
     *     'onUpdate' => [4, 7, 9]
     * ]
     */
    public function getTestsForWorkflows(
        array $workflowIds,
        $events = null,
        ConnectionInterface $connection = null
    ) : array {
        return $this->getTestsArray($workflowIds, IWorkflow::EVENT, $events, $connection);
    }

    /**
     * Gets the blocking tests on the workflows
     * @param array                     $workflowIds        ids of workflows to examine
     * @param array|null                $states             states to limit the results by
     * @param ConnectionInterface|null $connection          connection to use
     * @return array key of state name with an array of unique test ids that block that state for example
     * [
     *      'approved' => [1, 2]
     *      'none' => [1, 2, 3, 4]
     * ]
     * If states are specified results will be limited to the states provided
     */
    public function getBlockingTests(array $workflowIds, $states = null, ConnectionInterface $connection = null) : array
    {
        return $this->getTestsArray($workflowIds, IWorkflow::BLOCKS, $states, $connection);
    }

    /**
     * Gets a key/value array for all the test ids for the field provided
     * @param array                     $workflowIds        ids of workflows to examines
     * @param string                    $field              field on the test to examine, for example 'event'
     * @param array|null                $values             values to limit the results by
     * @param ConnectionInterface|null $connection
     * @return array key of field name with an array of unique test ids for that field name
     */
    protected function getTestsArray(
        array $workflowIds,
        string $field,
        $values = null,
        ConnectionInterface $connection = null
    ) : array {
        $workflows = $this->fetchAll([Workflow::FETCH_BY_IDS => $workflowIds], $connection);
        $all       = [];
        foreach ($workflows as $workflow) {
            $tests = $workflow->getTests();
            foreach ($tests as $test) {
                $forMerge = [$test[$field] => $test[IWorkflow::TEST_ID]];
                $all      = array_merge_recursive($all, $forMerge);
            }
        }
        foreach ($all as $fieldKey => $ids) {
            $all[$fieldKey] = array_values(array_unique((array)$ids));
        }
        if ($values && is_array($values)) {
            $all = array_intersect_key($all, array_flip($values));
        }
        return $all;
    }
}
