<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Events\Listener;

use Application\Config\ConfigManager;
use Application\Log\SwarmLogger;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\EventManager\ListenerAggregateTrait;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;

abstract class AbstractEventListener implements ListenerAggregateInterface
{
    use ListenerAggregateTrait;

    protected $services    = null;
    protected $eventConfig = null;
    protected $logger      = null;

    /**
     * Ensure we get a service locator and event config on construction.
     *
     * @param   ServiceLocator  $services       the service locator to use
     * @param   array           $eventConfig    the event config for this listener
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        $this->services    = $services;
        $this->eventConfig = $eventConfig;
        $this->logger      = $services->get(SwarmLogger::SERVICE);
    }

    /**
     * TODO generic things that handlers want to do with a call to parent::handle($event)
     * @param Event $event
     */
    public function handle(Event $event)
    {
    }

    /**
     * Log event
     * @param Event $event
     * @throws \Application\Config\ConfigException
     */
    public function log(Event $event)
    {
        $traceLog = ConfigManager::getValue($this->services->get('config'), ConfigManager::LOG_EVENT_TRACE, false);
        if ($traceLog) {
            $logger    = $this->services->get('logger');
            $function  = debug_backtrace(1)[1]['function'];
            $className = get_class($this);
            $priority  = null;
            foreach ($this->eventConfig as $eventName => $eventDetails) {
                foreach ($eventDetails as $eventDetail) {
                    if ($eventDetail[ListenerFactory::CALLBACK] === $function) {
                        $priority = $eventDetail[ListenerFactory::PRIORITY];
                        break;
                    }
                }
            }
            $logger->trace(
                "Handler class [$className], function ['$function']" .
                ($priority ? ", priority [$priority]" : '')
            );
        }
    }

    /**
     * Get the controller if it has an event manager (determined by the route).
     * @param Event $event
     * @return mixed|null the controller from the route if it has an event manager, otherwise null
     */
    public function getControllerFromRoute(Event $event)
    {
        $controller       = null;
        $routeMatch       = $event->getRouteMatch();
        $controllerName   = $routeMatch->getParam('controller');
        $controllerLoader = $event->getApplication()->getServiceManager()->get('ControllerManager');
        if ($controllerLoader->has($controllerName)) {
            try {
                $controller = $controllerLoader->get($controllerName);
            } catch (\Exception $e) {
                // let the dispatch listener handle bad controllers
                $this->services->get('logger')->trace(
                    "Controller [$controllerName] not found when requesting to attach events"
                );
            }
        }
        return $controller;
    }

    /**
     * Whether the event should be attached. True by default, gives the sub-classes a
     * chance to choose not to attach on a per event basis
     * @param mixed $eventName the event name
     * @param array $eventDetail the event detail
     * @return bool
     */
    protected function shouldAttach($eventName, $eventDetail)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $logger = $this->services->get('logger');

        $eventManager = $events;
        $traceLog     = ConfigManager::getValue($this->services->get('config'), ConfigManager::LOG_EVENT_TRACE, false);
        foreach ($this->eventConfig as $eventName => $eventDetails) {
            foreach ($eventDetails as $eventDetail) {
                if ($this->shouldAttach($eventName, $eventDetail)) {
                    $managerContext = isset($eventDetail[ListenerFactory::MANAGER_CONTEXT])
                        ? $eventDetail[ListenerFactory::MANAGER_CONTEXT]
                        : null;
                    if ($managerContext) {
                        $eventManager =
                            $this->services->get($eventDetail[ListenerFactory::MANAGER_CONTEXT])->getEventManager();
                    }
                    if ($traceLog) {
                        $logger->trace(
                            "Attaching [$eventName] to [" . get_class($this) .
                            "] with priority [" . $eventDetail[ListenerFactory::PRIORITY] .
                            "] and callback [" . $eventDetail[ListenerFactory::CALLBACK] .
                            ($managerContext ? "] and context [$managerContext]" : "]")
                        );
                    }
                    $this->listeners[] = $eventManager->attach(
                        $eventName,
                        [$this, $eventDetail[ ListenerFactory::CALLBACK]],
                        $eventDetail[ListenerFactory::PRIORITY]
                    );
                } else {
                    if ($traceLog) {
                        $logger->trace(
                            "Listener [" . get_class($this) . "] decided not to attach to [$eventName]" .
                            " for callback [" . $eventDetail[ListenerFactory::CALLBACK] . "]"
                        );
                    }
                }
            }
        }
    }
}
