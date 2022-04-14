<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Log;

use Application\Config\ConfigManager;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

/**
 * Class EventListener to handle logging
 * @package Application\Log
 */
class EventListener extends AbstractEventListener
{
    /**
     * @inheritDoc
     */
    protected function shouldAttach($eventName, $eventDetail)
    {
        return ConfigManager::getValue($this->services->get('config'), ConfigManager::LOG_EVENT_TRACE, false);
    }

    /**
     * Logs to trace to indicate that the event was triggered
     * @param Event $event
     * @return Event
     */
    public function handleEventTriggered(Event $event)
    {
        $logger = $this->services->get('logger');
        $logger->trace('Event [' . $event->getName() . '] triggered');
        return $event;
    }

    /**
     * Log to indicate event processing finished (should have a very low priority)
     * @param Event $event
     * @return Event
     */
    public function handleEventFinished(Event $event)
    {
        $logger = $this->services->get('logger');
        $logger->trace('Event [' . $event->getName() . '] finished processing');
        return $event;
    }
}
