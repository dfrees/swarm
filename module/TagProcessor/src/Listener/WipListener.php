<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TagProcessor\Listener;

use Application\Config\ConfigException;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;
use TagProcessor\Service\IWip;

class WipListener extends AbstractEventListener
{
    const LOG_PREFIX = WipListener::class;

    /**
     * The Event checking if the Wip keyword is present and then allows event to carry on or disregards
     * the event.
     *
     * @param Event $event
     * @throws ConfigException
     */
    public function checkWip(Event $event)
    {
        parent::log($event);
        // Ignore default changelist.
        if ($event->getParam('id') === 'default') {
            return;
        }
        $this->logger->trace(sprintf("[%s]: Event handle launched", self::LOG_PREFIX));
        try {
            $id         = $event->getParam('id');
            $wipService = $this->services->get(IWip::WIP_SERVICE);
            $matches    = $wipService->checkWip($id);
            if ($matches) {
                $this->logger->info(sprintf("[%s]: Match has been found in changelist [%s]", self::LOG_PREFIX, $id));
                $event->stopPropagation(true);
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf("[%s]: %s", self::LOG_PREFIX, $e->getMessage()));
            return;
        }
        $this->logger->trace(sprintf("[%s]: Event handle finished.", self::LOG_PREFIX));
    }
}
