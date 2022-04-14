<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace ShortLinks;

use Application\Filter\ExternalUrl;
use Record\Exception\NotFoundException;
use ShortLinks\Model\ShortLink;
use Laminas\Mvc\MvcEvent;

class Module
{
    /**
     * If a short hostname is set, requests for short link ids at the root should
     * first try to match a short-link and if found, redirect to the stored URI.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $config      = $services->get('config');

        // normalize and lightly validate the shortlink external_url if one is set.
        if (!empty($config['short_links']['external_url'])) {
            // bail if standard host is not defined via an external_url (should not happen)
            if (empty($config['environment']['external_url'])) {
                // log a warning and return (it will most likely result in 404)
                $services->get('logger')->warn(
                    "Environment external_url must be set if short_links external_url is set."
                );
                return;
            }

            $enforceHttps = isset($config['security']['https_strict']) && $config['security']['https_strict'];
            $filter       = new ExternalUrl($enforceHttps);
            $url          = $filter->filter($config['short_links']['external_url']);
            if (!$url) {
                throw new \RuntimeException(
                    'Invalid short_links external_url value in config.php'
                );
            }

            $config['short_links']['external_url'] = $url;
            $config['short_links']['hostname']     = parse_url($url, PHP_URL_HOST);
            $services->setService('config', $config);
        }

        // nothing more to do if no short-host has been set
        if (empty($config['short_links']['hostname'])) {
            return;
        }

        // normalize short-host to ensure no scheme and no port
        $shortHost = $config['short_links']['hostname'];
        preg_match('#^([a-z]+://)?(?P<hostname>[^:]+)?#', $shortHost, $matches);
        $shortHost                         = isset($matches['hostname']) ? $matches['hostname'] : null;
        $config['short_links']['hostname'] = $shortHost;
        $services->setService('config', $config);

        // we should only honor short-links at the root if the request is on the short-host
        // and the short-host differs from the standard host
        $uri           = $application->getRequest()->getUri();
        $isOnShortHost = isset($url) ? stripos($uri->toString(), $url) === 0 : $uri->getHost() === $shortHost;
        $envUrl        = $config['environment']['external_url'] ?: $config['environment']['hostname'];
        $shortLinksUrl = $config['short_links']['external_url'] ?: $shortHost;
        if (!$isOnShortHost || $envUrl === $shortLinksUrl) {
            return;
        }

        // at this point, we know a short-host is set, and the request is for the short-host
        // if the requested path looks like a short-link ID, try to look it up
        $baseUrl = $config['environment']['base_url'];
        $pattern = '#^' . ($baseUrl ? preg_quote($baseUrl) : '') . '/+([a-z0-9]{4,})/?$#i';
        if (preg_match($pattern, $uri->getPath(), $matches)) {
            try {
                $link     = ShortLink::fetchByObfuscatedId($matches[1], $services->get('p4_admin'));
                $qualify  = $services->get('ViewHelperManager')->get('qualifiedUrl');
                $redirect = ShortLink::qualifyUri($link->getUri(), $qualify());
            } catch (NotFoundException $e) {
                // we expected this could happen
            }
        }

        // if we didn't match a short-link, we still want to get off the short-host
        // rewrite the original request URI to use the standard hostname
        if (!isset($redirect)) {
            $redirect = $config['environment']['external_url']
                ? $config['environment']['external_url'] . $uri->getPath()
                : $uri->setHost($config['environment']['hostname'])->toString();
        }

        // we need to stop the regular route/dispatch processing and send a redirect header
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $redirect);
        $response->setStatusCode(302);
        $response->sendHeaders();

        exit();
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
