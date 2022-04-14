<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Listener;

use Activity\Model\Activity;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Events\Listener\ListenerFactory;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Projects\Model\Project as ProjectModel;
use Laminas\EventManager\Event;
use Workflow\Model\IWorkflow;

/**
 * Listener class to handle workflow events
 * @package Workflow\Listener
 */
class WorkflowListener extends AbstractEventListener
{
    private $translator;

    /**
     * WorkflowListener constructor.
     * @param ServiceLocator $services
     * @param array $eventConfig
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->translator = $this->services->get(TranslatorFactory::SERVICE);
    }

    /**
     * Handle workflow created
     * @param Event $event the event
     */
    public function workflowCreated(Event $event)
    {
        $this->createActivity($event, $this->translator->t('created'));
    }

    /**
     * Handle workflow deleted
     * @param Event $event the event
     */
    public function workflowDeleted(Event $event)
    {
        $this->createActivity($event, $this->translator->t('deleted'));
    }

    /**
     * Handle workflow updated
     * @param Event $event the event
     */
    public function workflowUpdated(Event $event)
    {
        $this->createActivity($event, $this->translator->t('updated'));
        $this->linkToProjects($event);
    }

    /**
     * Handle upgrading of Workflows from one schema version to another
     * @param Event $event
     */
    public function upgradeWorkflows(Event $event)
    {
        $logPrefix = self::class . ':' . __FUNCTION__ . ':';
        $services  = $this->services;
        $logger    = $this->logger;
        $action    = $this->translator->t('upgraded');
        try {
            $p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
            $workflowDAO = $services->get(IModelDAO::WORKFLOW_DAO);
            $data        = $event->getParam(ListenerFactory::DATA);
            $upgraded    = [];
            $logger->info(
                sprintf(
                    "%s %s (%s)",
                    $logPrefix,
                    'About to upgrade the workflow schema to level',
                    $data[IWorkflow::UPGRADE]
                )
            );
            foreach (array_filter(
                iterator_to_array($workflowDAO->fetchAll([], $p4Admin)),
                function ($workflow) use ($data) {
                    return $data[IWorkflow::UPGRADE] > (int)$workflow->get(IWorkflow::UPGRADE);
                }
            ) as $needsUpgrade) {
                $description = $needsUpgrade->get(IWorkflow::NAME) . '(' . $needsUpgrade->getId() . ')';
                $logger->trace(sprintf("%s %s %s", $logPrefix, 'Upgrading', $description));
                $workflowDAO->save($needsUpgrade);
                $upgraded[] = $description;
            }
            // Add an activity for the upgrade event
            $logger->debug(sprintf("%s %s", $logPrefix, 'Create an activity record of the upgrade process'));
            // If we don't have any upgraded workflows then don't add it to the activity
            if (count($upgraded) > 0) {
                $upgradeTargets = count($upgraded) . ' workflow(s) [' . implode(', ', $upgraded) . ']';
                $event->setParam(
                    'activity',
                    (new Activity)->set(
                        [
                            'action' => $action,
                            'type' => IWorkflow::WORKFLOW,
                            'user' => $p4Admin->getUser(),
                            'target' => $upgradeTargets
                        ]
                    )
                );
                $logger->info(sprintf("%s %s %s", $logPrefix, 'Upgraded', $upgradeTargets));
            }
        } catch (\Exception $e) {
            $logger->err($e->getMessage());
        }
    }

    /**
     * Updates the existing activity created on the event to add a project link when required.
     * A project link will describe the projects/branches linked to the workflow on the activity
     * and is only required when a workflow is updated.
     * @param Event $event  the event
     */
    private function linkToProjects(Event $event)
    {
        try {
            $activity     = $event->getParam('activity');
            $wfId         = $event->getParam('id');
            $p4admin      = $this->services->get(ConnectionFactory::P4_ADMIN);
            $projectDAO   = $this->services->get(IModelDAO::PROJECT_DAO);
            $projects     = $projectDAO->fetchAll([ProjectModel::FETCH_BY_WORKFLOW => $wfId], $p4admin);
            $projectsLink = [];
            foreach ($projects as $project) {
                $projectWf                = $project->getWorkflow();
                $projectId                = $project->getId();
                $projectsLink[$projectId] = [];
                $branches                 = $project->getBranches();
                foreach ($branches as $branch) {
                    $branchWf = $project->getWorkflow($branch['id'], $branches);
                    if ($branchWf && $branchWf != $projectWf) {
                        $projectsLink[$projectId][] = $branch['id'];
                    }
                }
            }
            $activity->set(
                ['projects' => $projectsLink]
            );
        } catch (\Exception $e) {
            $this->logger->err($e->getMessage());
        }
    }

    /**
     * Create activity when a workflow is created or updated.
     * @param Event     $event      the event
     * @param string    $action     the action
     */
    private function createActivity(Event $event, string $action)
    {
        try {
            $data = $event->getParam(ListenerFactory::DATA);
            // If name is set in data use that for the activity, else fetch by id to find the name
            if (isset($data[IWorkflow::NAME])) {
                $name = $data[IWorkflow::NAME];
            } else {
                $p4admin = $this->services->get(ConnectionFactory::P4_ADMIN);
                $wf      = $this->services->get(IModelDAO::WORKFLOW_DAO)->fetch($event->getParam('id'), $p4admin);
                $name    = $wf->getName();
            }
            $activity = new Activity;
            $activity->set(
                [
                    'action'   => $action,
                    'user'     => $data['user'],
                    'target'   => "workflow ($name)"
                ]
            );
            $event->setParam('activity', $activity);
        } catch (\Exception $e) {
            $this->logger->err($e->getMessage());
        }
    }
}
