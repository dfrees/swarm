<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Listener;

use Events\Listener\AbstractEventListener;
use Laminas\Http\Request;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\Parameters;
use Laminas\Router\Http\RouteMatch;

class RouteListener extends AbstractEventListener
{
    public function handleApiUrl(MvcEvent $event)
    {
        $route = $event->getRouteMatch() ? $event->getRouteMatch()->getMatchedRouteName() : '';
        if (strpos($route, 'api/') === 0) {
            $event->getRequest()->getQuery()->set('format', 'json');
        }
    }

    public function handleMultiP4d(MvcEvent $event)
    {
        if (MULTI_P4_SERVER !== true || P4_SERVER_ID !== null) {
            return;
        }

        $servers         = unserialize(P4_SERVER_VALID_IDS);
        $defaultServerId = reset($servers);
        $response        = $event->getResponse();

        // we are in Multi-P4D mode and there is no Server ID specified
        // for GET requests, determine the first valid server and redirect
        // the user to it
        $request = $event->getRequest();
        $uri     = $request->getRequestUri();
        if ($uri === '/') {
            // swarm/ maps to overview dashboard for multip4d, let the index controller deal with it
            return;
        } elseif ($request->getMethod() == Request::METHOD_GET) {
            $response->getHeaders()->addHeaderLine(
                'Location',
                '/' . $defaultServerId .  (strpos($uri, '/api') === 0 ? $uri : '')
            );
            $response->setStatusCode(302);
        } else {
            // all other HTTP methods get a 404
            $response->setStatusCode(404);
        }

        return $response;
    }

    public function handleRequireLogin(MvcEvent $event)
    {
        $config = $this->services->get('config');
        if (isset($config['security']['require_login']) && $config['security']['require_login'] ||
            (
                (MULTI_P4_SERVER === true && P4_SERVER_ID !== null || MULTI_P4_SERVER !== true) &&
                $this->services->get('permissions')->is('authenticated')
                && $this->services->get('p4_user')->getMFAStatus()
                && $this->services->get('p4_user')->getMFAStatus() !== 'validated')
        ) {
            $config              = $this->services->get('config');
            $routeMatch          = $event->getRouteMatch();
            $config['security'] += ['login_exempt' => []];
            $exemptRoutes        = array_merge(
                (array)$config['security']['login_exempt'],
                (array)$config['security']['mfa_routes'],
                array_filter(
                    (array)$config['security']['multiserver_login_exempt_routes'],
                    function () {
                        return MULTI_P4_SERVER === true && P4_SERVER_ID === null;
                    }
                )
            );

            // continue if route is login exempt
            if (in_array($routeMatch->getMatchedRouteName(), $exemptRoutes)) {
                return;
            }

            // continue if allowed to login just with cookie
            if (in_array($routeMatch->getMatchedRouteName(), $config['security']['login_with_cookie'])) {
                if (isset($_COOKIE['Swarm-Token'])) {
                    // value of token from the cookie.
                    $cookieToken = $_COOKIE['Swarm-Token'];
                    // array of tokens we have configured.
                    $tokens = $this->services->get('queue')->getTokens();
                    foreach ($tokens as $token) {
                        if ($cookieToken === $token) {
                            return;
                        }
                    }
                }
            }

            // forward to login method if the user isn't logged in
            if (!$this->services->get('permissions')->is('authenticated') ||
                ($this->services->get('p4_user')->getMFAStatus()
                    && $this->services->get('p4_user')->getMFAStatus() !== 'validated')
            ) {
                $routeMatch = new RouteMatch(
                    [
                        'controller' => \Users\Controller\IndexController::class,
                        'action'     => 'login'
                    ]
                );

                // clear out the post and query parameters, preserving the "format" if it specifies JSON
                $query = new Parameters;
                $post  = new Parameters;
                if (strtolower($event->getRequest()->getQuery('format')) === 'json') {
                    $query->set('format', 'json');
                }

                $routeMatch->setMatchedRouteName('login');
                $event->setRouteMatch($routeMatch);
                $event->getRequest()->setPost($post)->setQuery($query);
                $event->getResponse()->setStatusCode(401);
            }
        }
    }
}
