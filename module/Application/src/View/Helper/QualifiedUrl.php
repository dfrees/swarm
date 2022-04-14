<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

/**
 * Behaves just like the url view helper, but returns fully qualified urls.
 * For example: http://some-host:8080/path/to/action
 */
class QualifiedUrl extends AbstractHelper
{
    public function __invoke($name = null, array $params = [], $options = [], $reuseMatchedParams = false)
    {
        $view     = $this->getView();
        $services = $this->services;
        $config   = $services->get('config');
        $strict   = isset($config['security']['https_strict']) && $config['security']['https_strict'];
        $uri      = $services->get('request')->getUri();
        $scheme   = $strict ? 'https' : ($uri->getScheme() ?: 'http');
        $origin   = $config['environment']['external_url'] ?: null;
        $host     = $config['environment']['hostname']     ?: $uri->getHost();
        $host     = $host ?: 'localhost';
        $port     = $uri->getPort() ?: ($scheme == 'https' ? 443 : 80);
        $port     = $scheme == 'https' && isset($config['security']['https_port']) && $config['security']['https_port']
            ? $config['security']['https_port']
            : $port;

        // detect if a custom origin has been specified
        // if arguments were not given, exit early with just the raw origin + basePath
        // otherwise, assemble the qualified URL
        if ($origin) {
            $origin = $view->escapeFullUrl($origin);
            if (!func_num_args()) {
                return $origin . $view->basePath();
            }

            $url = ltrim($view->url($name, $params, $options, $reuseMatchedParams), '/\\');
            return $origin . '/' . $url;
        }

        // assemble the default origin
        $origin = $scheme . '://' . $host . ($port && $port != 80 && $port != 443 ? ':' . $port : '');
        $origin = $view->escapeFullUrl($origin);

        // if no arguments given, return the baseUrl (origin + basePath)
        if (!func_num_args()) {
            return $origin . $view->basePath();
        }

        return $origin . $view->url($name, $params, $options, $reuseMatchedParams);
    }
}
