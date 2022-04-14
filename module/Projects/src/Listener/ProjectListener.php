<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Listener;

use Activity\Model\Activity;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Exception;

/**
 * Listener class to handle project events
 * @package Projects\Listener
 */
class ProjectListener extends AbstractEventListener
{
    const CREATED_ACTION = 'created';
    const UPDATED_ACTION = 'updated';

    /**
     * Handle project created
     * @param Event $event the event
     */
    public function projectCreated(Event $event)
    {
        $this->createActivity($event, self::CREATED_ACTION);
    }

    /**
     * Handle project updated
     * @param Event $event the event
     */
    public function projectUpdated(Event $event)
    {
        $this->createActivity($event, self::UPDATED_ACTION);
    }

    /**
     * Create activity when a project is created or updated.
     * @param Event     $event      the event
     * @param string    $action     the action, either 'created' or 'updated
     */
    protected function createActivity(Event $event, $action)
    {
        try {
            $id      = $event->getParam('id');
            $data    = $event->getParam('data');
            $p4admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            $project = $this->services->get(IModelDAO::PROJECT_DAO)->fetch($event->getParam('id'), $p4admin);

            $activity = new Activity;
            $activity->set(
                [
                    'type'        => 'project',
                    'link'        => ['project' => $id],
                    'action'      => $action,
                    'user'        => $data['user'],
                    'target'      => 'project (' . $project->getName() . ')',
                    'description' => $project->get('description'),
                ]
            );

            $activity->setProjects([$id => []]);
            $event->setParam('activity', $activity);
        } catch (RecordNotFoundException $e) {
            // If the project can no longer be found it may have been deleted, just log this as info
            $this->services->get(SwarmLogger::SERVICE)->info(sprintf("%s: %s", get_class($this), $e->getMessage()));
        } catch (Exception $e) {
            $this->services->get(SwarmLogger::SERVICE)->err(sprintf("%s: %s", get_class($this), $e->getMessage()));
        }
    }
}
