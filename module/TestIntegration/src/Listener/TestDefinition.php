<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Listener;

use Activity\Model\Activity;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Events\Listener\ListenerFactory;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use TestIntegration\Model\ITestDefinition;

/**
 * Class TestDefinition. Listener for queue tasks to do with test definitions
 * @package TestIntegration\Listener
 */
class TestDefinition extends AbstractEventListener
{
    private $translator;

    /**
     * TestDefinitionListener constructor.
     * @param ServiceLocator $services
     * @param array $eventConfig
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->translator = $this->services->get(TranslatorFactory::SERVICE);
    }

    /**
     * Handle testDefinition created
     * @param Event $event the event
     */
    public function testDefinitionCreated(Event $event)
    {
        $this->createActivity($event, $this->translator->t('created'));
    }

    /**
     * Handle testDefinition updated
     * @param Event $event the event
     */
    public function testDefinitionUpdated(Event $event)
    {
        $this->createActivity($event, $this->translator->t('updated'));
    }

    /**
     * Handle testDefinition deleted
     * @param Event $event the event
     */
    public function testDefinitionDeleted(Event $event)
    {
        $this->createActivity($event, $this->translator->t('deleted'));
    }

    /**
     * Handle the test definitions migration event by creating activity to be processed
     * @param Event $event  the event
     */
    public function definitionsMigrated(Event $event)
    {
        try {
            $data     = $event->getParam(ListenerFactory::DATA);
            $p4admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
            $migrated = $data[ListenerFactory::TEST_DEFINITION_MIGRATED];
            $inError  = $data[ListenerFactory::TEST_DEFINITION_MIGRATED_ERROR];
            // If we have a migrated or inerror report this in activity otherwise don't put this in the activity.
            if (count($migrated) > 0 || count($inError) > 0) {
                $activity = new Activity;
                $target   = $this->translator->t(
                    "test definition(s) [%s] from configuration, test definition(s) in error [%s]",
                    [
                        implode(', ', $migrated),
                        implode(', ', $inError)
                    ]
                );
                $activity->set(
                    [
                        'action' => $this->translator->t('migrated'),
                        'user'   => $p4admin->getUser(),
                        'target' => $target
                    ]
                );
                $event->setParam('activity', $activity);
            }
        } catch (Exception $e) {
            $this->logger->err($e->getMessage());
        }
    }

    /**
     * Create activity when a testDefinition is created, updated or deleted.
     * @param Event     $event      the event
     * @param string    $action     the action, defaults to 'created'
     */
    private function createActivity(Event $event, $action)
    {
        try {
            $data        = $event->getParam(ListenerFactory::DATA);
            $description = $this->getDescriptionText($data);
            // If name is set in data use that for the activity, else fetch by id to find the name
            if (isset($data[ITestDefinition::FIELD_NAME])) {
                $name = $data[ITestDefinition::FIELD_NAME];
            } else {
                $p4admin           = $this->services->get(ConnectionFactory::P4_ADMIN);
                $testDefinitionDao = $this->services->get(IModelDAO::TEST_DEFINITION_DAO);
                $testDefinition    = $testDefinitionDao->fetch(
                    $event->getParam(ListenerFactory::ID),
                    $p4admin
                );
                $name              = $testDefinition->getName();
            }

            $activity = new Activity;
            $activity->set(
                [
                    'action'      => $action,
                    'user'        => $data ? $data[ListenerFactory::USER] : '',
                    'target'      => $this->translator->t(
                        "test definition (%s)",
                        [
                            $name,
                        ]
                    ),
                    'type'        => ITestDefinition::TESTDEFINITION,
                    'description' => $description,
                ]
            );
            $event->setParam('activity', $activity);
        } catch (\Exception $e) {
            $this->logger->err($e->getMessage());
        }
    }

    /**
     * Get the description text from for the event.
     * As this will only be set when the test definition name is changed.
     * @param array $data The data from the event to inspect.
     * @return string
     */
    private function getDescriptionText($data)
    {
        $description = '';
        // If we have new then we know we are a edit.
        if (is_array($data) && isset($data[ITestDefinition::TEST_DEFINITION_NEW])) {
            $old = (array)$data[ITestDefinition::TEST_DEFINITION_OLD];
            $new = (array)$data[ITestDefinition::TEST_DEFINITION_NEW];
            if (isset($old[ITestDefinition::FIELD_NAME]) && isset($new[ITestDefinition::FIELD_NAME])) {
                $oldName = $old[ITestDefinition::FIELD_NAME];
                $newName = $new[ITestDefinition::FIELD_NAME];
                if ($oldName !== $newName) {
                    $description = $this->translator->t("Previously known as (%s)", [$oldName]);
                }
            }
        }
        return $description;
    }
}
