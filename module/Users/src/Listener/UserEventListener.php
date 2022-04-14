<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Listener;

use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

class UserEventListener extends AbstractEventListener
{
    public function onUser(Event $event)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $name   = $event->getName();
        $id     = $event->getParam('id');
        $logger->trace(self::class." processing event $name for id $id");
        // ignore git-fusion-reviews-* users - these are regularly updated
        // and used internally by git-fusion in ways that don't concern us
        if (strpos($id, 'git-fusion-reviews-') === 0) {
            $logger->trace(self::class." Ignoring git-fusion-review users");
            return;
        }

        try {
            $userDao = $this->services->get(IModelDAO::USER_DAO);
            // This will remove from the cache first if set and then add it again which will
            // handle both new users and deletes
            $userDao->fetchByIdAndSet($event->getParam('id'));
            $logger->trace(self::class." processed event $name for id $id");
        } catch (\Exception $e) {
            $logger->err($e);
        }
    }
}
