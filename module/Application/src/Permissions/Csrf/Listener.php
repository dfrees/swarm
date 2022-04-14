<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions\Csrf;

use Events\Listener\AbstractEventListener;
use Users\Authentication\Storage\BasicAuth as BasicAuthStorage;
use Laminas\Mvc\MvcEvent;

/**
 * Intended to listen to MVC_DISPATCH and get a CSRF listener in before the controller dispatch
 * to enforce the presence of _csrf tokens on requests that need it.
 */
class Listener extends AbstractEventListener
{
    /**
     * This is our phase 1 handler. Its job is to locate the target controller and
     * register the CSRF check on its dispatch event prior to the action running.
     *
     * @param $event
     * @throws \Application\Config\ConfigException
     */
    public function registerControllerListener(MvcEvent $event)
    {
        // so long as we're shooting for a valid controller, register
        // our CSRF test on the controller's dispatch process
        $controller = $this->getControllerFromRoute($event);
        if ($controller) {
            // We need to do this here rather than in config as it is attached to
            // the controller event manager
            $controller->getEventManager()->attach(
                MvcEvent::EVENT_DISPATCH,
                [$this, 'enforceCsrf'],
                100
            );
        }
    }

    /**
     * This is our phase 2 handler. Its job is to throw a CSRF exception if the token is
     * required but bad. This occurs just prior to the action running.
     * By throwing so late we get handled just like exceptions in the action would have
     * resulting in a nice json or html error.
     *
     * @param $event
     */
    public function enforceCsrf($event)
    {
        $services   = $this->services;
        $request    = $event->getRequest();
        $routeMatch = $event->getRouteMatch();

        // pull out the token and remove it from the POST values to
        // ensure it can't end up erroneously stored or divulged.
        // we hang onto the token in the request meta-data to re-validate
        // it should the request get forwarded to another action.
        $header = $request->getHeader('x-csrf-token');
        $token  = $header ? $header->getFieldValue() : $request->getPost('_csrf', $request->getMetadata('_csrf'));
        if ($request->getPost()->offsetExists('_csrf')) {
            $request->setMetadata('_csrf', $token);
            $request->getPost()->offsetUnset('_csrf');
        }

        // determine the exempt routes
        $config = $services->get('config') + ['security' => null];
        $config = (array) $config['security'] + ['login_exempt' => null, 'csrf_exempt' => null];
        $exempt = array_merge((array) $config['login_exempt'], (array) $config['csrf_exempt']);

        // deal with CSRF enforcement
        // there are a number of happy cases where we can just return without action:
        // - it is a get (or put another way it isn't a Post, Put, Patch, Delete, etc)
        // - the route is CSRF exempt or login exempt
        // - the user isn't logged in
        // - they're using basic auth (credentials in every request, csrf redundant)
        if ($request->isGet()
            || in_array($routeMatch->getMatchedRouteName(), $exempt)
            || !$services->get('permissions')->is('authenticated')
            || $services->get('auth')->getStorage() instanceof BasicAuthStorage
        ) {
            return;
        }

        // looks like enforcement is required; test the token (throwing if it's bad)
        $services->get('csrf')->enforce($token);
    }
}
