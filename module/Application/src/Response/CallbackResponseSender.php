<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 *              Portions of this file are copyright 2005-2013 Zend Technologies USA Inc. licensed under New BSD License
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Response;

use Events\Listener\AbstractEventListener;
use Laminas\Mvc\ResponseSender\ResponseSenderInterface;
use Laminas\Mvc\ResponseSender\SendResponseEvent;
use Laminas\Http\Header\MultipleHeaderInterface;

/**
 * Handles detection and dispatching for CallbackResponses
 */
class CallbackResponseSender extends AbstractEventListener implements ResponseSenderInterface
{
    /**
     * Process the callback and update the event's ContentSent flag
     *
     * @param SendResponseEvent $event an event containing a CallbackResponse
     * @return $this
     */
    public function fireCallback(SendResponseEvent $event)
    {
        if ($event->contentSent()) {
            return $this;
        }
        $response = $event->getResponse();
        $stream   = $response->getCallback();
        call_user_func($stream);
        $event->setContentSent();
    }

    /**
     * Examine incoming events and fireCallback if CallbackResponse detected
     *
     * @param SendResponseEvent $event
     * @return $this
     */
    public function __invoke(SendResponseEvent $event)
    {
        $response = $event->getResponse();
        if (!$response instanceof CallbackResponse) {
            return $this;
        }

        // disable output buffers to facilitate streaming (unless testing)
        if (!$event->getParam('isTest')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->sendHeaders($event);
        $this->fireCallback($event);
        $event->stopPropagation(true);

        return $this;
    }

    /**
     * Send HTTP headers
     *
     * @param  SendResponseEvent $event
     * @return self
     */
    public function sendHeaders(SendResponseEvent $event)
    {
        if (headers_sent() || $event->headersSent()) {
            return $this;
        }

        $response = $event->getResponse();

        foreach ($response->getHeaders() as $header) {
            if ($header instanceof MultipleHeaderInterface) {
                header($header->toString(), false);
                continue;
            }
            header($header->toString());
        }

        $status = $response->renderStatusLine();
        header($status);

        $event->setHeadersSent();
        return $this;
    }
}
