<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Http;

use Events\Listener\AbstractEventListener;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use P4\Connection\Exception\ConnectException;
use Laminas\Http\Response;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

class ExceptionStrategy extends AbstractEventListener
{
    /**
     * Create an exception view model, and set the HTTP status code
     *
     * Replaces parent to set the status code more selectively.
     *
     * @param  MvcEvent $event
     * @return void
     */
    public function prepareExceptionViewModel(MvcEvent $event)
    {
        // Do nothing if not an exception or not an HTTP response
        if ($event->getError() != Application::ERROR_EXCEPTION
            || !$event->getResponse() instanceof Response
        ) {
            return;
        }

        $exception = $event->getParam('exception');

        // if a service was not created properly, attempt to extract the previous exception that caused the failure
        if ($exception instanceof ServiceNotCreatedException) {
            $exception = $exception->getPrevious() ?: $exception;
        }

        if ($exception instanceof UnauthorizedException) {
            $event->getResponse()->setStatusCode(401);
        }
        if ($exception instanceof ForbiddenException) {
            $event->getResponse()->setStatusCode(403);
        }
        if ($exception instanceof ConnectException) {
            $event->getResponse()->setStatusCode(503);
        }
    }
}
