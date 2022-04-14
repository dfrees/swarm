<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Listener;

use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Projects\Model\Project;
use Laminas\EventManager\Event;
use Exception;

class GroupsListener extends AbstractEventListener
{

    /**
     * Handle group events, for example new groups and deletes
     * @param Event $event
     */
    public function onGroup(Event $event)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        try {
            $id = $event->getParam('id');
            $logger->trace(self::class . ':' . __FUNCTION__ . " processing event group with id $id");
            $groupDao = $this->services->get(IModelDAO::GROUP_DAO);
            // This will remove from the cache first if set and then add it again which will
            // handle both new groups and deletes
            $groupDao->fetchByIdAndSet($id, $this->services->get(ConnectionFactory::P4_ADMIN));
            if (Project::isProjectName($id)) {
                $projectDao = $this->services->get(IModelDAO::PROJECT_DAO);
                $projectDao->fetchByIdAndSet(
                    Project::getProjectName($id), $this->services->get(ConnectionFactory::P4_ADMIN)
                );
            }
        } catch (Exception $e) {
            $logger->err($e);
        }
    }
}
