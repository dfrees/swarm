<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\AbstractDAO;
use Events\Listener\ListenerFactory;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Permissions;
use Interop\Container\ContainerInterface;
use Queue\Manager;
use Record\Exception\NotFoundException;
use TestIntegration\Filter\ITestDefinition as Filter;
use Workflow\Model\IWorkflow;
use Exception;

/**
 * Class TestDefinitionDAO to fetch/build/save TestDefinition
 * @package TestIntegration\Model
 */
class TestDefinitionDAO extends AbstractDAO implements ITestDefinitionDAO
{
    // The Perforce class that handles tests
    const MODEL = TestDefinition::class;
    private $config;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->config = $services->get(IConfigDefinition::CONFIG);
        parent::__construct($services, $options);
    }

    /**
     * Import global tests and link them to a global workflow. We assume here that the global
     * workflow has been created. This will always attempt to import.
     * @throws ConfigException
     */
    public function importGlobalTests()
    {
        $p4Admin            = $this->services->get(ConnectionFactory::P4_ADMIN);
        $countCommandResult = $p4Admin->run('keys', ['-e', TestDefinition::KEY_COUNT]);
        if (!$countCommandResult->hasData()) {
            $errors          = [];
            $testDefinitions = [];
            $queue           = $this->services->get(Manager::SERVICE);
            // Keep track of migrated and errored tests to create an activity
            $migrated        = [];
            $inError         = [];
            $configuredTests = ConfigManager::getValue($this->config, ConfigManager::GLOBAL_TESTS, []);
            foreach ($configuredTests as $configuredTest) {
                $filter = $this->services->get(Filter::NAME);
                // Default owners/shared/description as they were not part of original configuration
                $configuredTest[TestDefinition::FIELD_OWNERS] = [$p4Admin->getUser()];
                $configuredTest[TestDefinition::FIELD_SHARED] = false;
                // Title should be migrated to description
                $configuredTest[TestDefinition::FIELD_DESCRIPTION] = $configuredTest[ITestDefinition::FIELD_TITLE];
                unset($configuredTest[ITestDefinition::FIELD_TITLE]);
                $filter->setData($configuredTest);
                if ($filter->isValid()) {
                    $td = new TestDefinition;
                    $td->set($configuredTest);
                    $td                = $this->save($td);
                    $testDefinitions[] = $td;
                    $migrated[]        = $td->getName();
                    $this->logger->info(sprintf("Migrated global test with name [%s] to key data", $td->getName()));
                } else {
                    $inError[] = $configuredTest[TestDefinition::FIELD_NAME];
                    // encode the messages as a convenient way to handle deep array filter messages
                    $errors[] = sprintf(
                        "name [%s], errors [%s]",
                        $configuredTest[TestDefinition::FIELD_NAME],
                        json_encode($filter->getMessages())
                    );
                }
            }
            if ($testDefinitions) {
                try {
                    $tests = [];
                    foreach ($testDefinitions as $testDefinition) {
                        $test    = [
                            IWorkflow::TEST_ID => $testDefinition->getId(),
                            IWorkflow::EVENT => IWorkflow::EVENT_ON_UPDATE
                        ];
                        $tests[] = $test;
                    }
                    $wfDao          = $this->services->get(IDao::WORKFLOW_DAO);
                    $globalWorkflow = $wfDao->fetchById(IWorkflow::GLOBAL_WORKFLOW_ID, $p4Admin);
                    $globalWorkflow = $globalWorkflow->setTests($tests);
                    $wfDao->save($globalWorkflow);
                    $this->logger->info('Successfully linked migrated tests to the global workflow');
                } catch (Exception $e) {
                    // Don't fail the import if we cannot link to a global workflow (although this should not happen)
                    $errors[] = sprintf("Error migrating global tests to the global workflow [%s]", $e->getMessage());
                }
                // Queue a task to upgrade existing test runs, we only need to do this if there were valid global test
                // definitions defined in configuration
                $queue->addTask(
                    ListenerFactory::TEST_RUN_UPGRADE_SCHEMA,
                    ListenerFactory::TEST_RUN_SCHEMA_VERSION,
                    [ITestRun::FIELD_UPGRADE => TestRun::UPGRADE_LEVEL]
                );
            }
            if ($errors) {
                foreach ($errors as $encodedError) {
                    $this->logger->err(
                        sprintf(
                            "[%s]: Error in global test definition with %s",
                            get_class($this),
                            $encodedError
                        )
                    );
                }
            }
            $queue->addTask(
                ListenerFactory::TEST_DEFINITION_MIGRATION,
                // An arbitrary value for id
                ListenerFactory::TEST_DEFINITION_MIGRATION,
                // Data
                [
                    ListenerFactory::TEST_DEFINITION_MIGRATED => $migrated,
                    ListenerFactory::TEST_DEFINITION_MIGRATED_ERROR => $inError
                ]
            );
        }
    }

    /**
     * Delete the testDefinition
     *
     * @param mixed $model model to delete
     * @return mixed
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function delete($model)
    {
        $services    = $this->services;
        $translator  = $services->get(TranslatorFactory::SERVICE);
        $wfDao       = $services->get(IDao::WORKFLOW_DAO);
        $p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
        $p4User      = $services->get(ConnectionFactory::P4_USER);
        $permissions = $services->get(Permissions::PERMISSIONS);
        $isSuper     = $permissions->is(Permissions::SUPER);
        $workflows   = $wfDao->fetchAll(
            [
                TestDefinition::FETCH_BY_KEYWORDS     => $model->getId(),
                TestDefinition::FETCH_KEYWORDS_FIELDS => [
                    IWorkflow::TESTS
                ]
            ],
            $p4Admin
        );

        $allCount = count((array) $workflows);
        if (!$workflows || $allCount === 0) {
            if ($model->isOwner($p4User->getUser()) || $isSuper) {
                $model->setConnection($p4Admin);
                return parent::delete($model);
            } else {
                // No permission - we'll treat this as not found so as to not give an clues
                throw new NotFoundException($translator->t('Cannot fetch entry. Id does not exist.'));
            }
        } else {
            $inUseOn = array_map(
                function ($workflow) {
                    return $workflow->getName();
                },
                array_reverse($workflows->getArrayCopy())
            );
            $message = $translator->tp(
                'Cannot delete test definition [%s], it is in use on workflow [%s]',
                'Cannot delete test definition [%s], it is in use on workflows [%s]', count($inUseOn),
                [$model->getId(), implode(', ', $inUseOn)]
            );
            throw new ForbiddenException($message);
        }
    }
}
