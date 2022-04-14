<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Listener;

use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Events\Listener\AbstractEventListener;
use P4\Log\Logger;
use Laminas\Mvc\MvcEvent;

class EventErrorListener extends AbstractEventListener
{
    public function onError(MvcEvent $event)
    {
        $exception = $event->getParam('exception');
        $logger    = $this->services->get('logger');
        $priority  = Logger::CRIT;

        if (!$exception) {
            return;
        }

        if ($exception instanceof UnauthorizedException || $exception instanceof ForbiddenException) {
            $priority = Logger::DEBUG;
        }

        $logger->log($priority, $exception);
    }
}
