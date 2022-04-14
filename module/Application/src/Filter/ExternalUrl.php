<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Laminas\Filter\AbstractFilter;

class ExternalUrl extends AbstractFilter
{
    protected $enforceHttps = false;

    /**
     * Optionally specify https strict mode. If set, then https scheme will be
     * enforced by the filter() method.
     *
     * @param   bool    $enforceHttps   optional - if true then https scheme will be
     *                                  enforced by the filter() method (false by default)
     */
    public function __construct($enforceHttps = false)
    {
        $this->setEnforceHttps($enforceHttps);
    }

    /**
     * Normalize the passed value to an url with scheme, host and (optionally) port, e.g.
     * http://example.com:8488.
     *
     * Invalid inputs are converted to false.
     *
     * @param   string          $value  url to normalize
     * @return  string|false    normalized url or false if input $value is invalid
     */
    public function filter($value)
    {
        $url           = (array) parse_url($value) + ['scheme' => '', 'host' => '', 'port' => ''];
        $url['scheme'] = $this->enforceHttps ? 'https' : $url['scheme'];
        if (!in_array(strtolower($url['scheme']), ['http', 'https']) || !$url[ 'host']) {
            return false;
        }

        $port = $url['port'];
        $port = $port == 80  && $url['scheme'] == 'http'  ? '' : $port;
        $port = $port == 443 && $url['scheme'] == 'https' ? '' : $port;

        return $url['scheme'] . '://' . $url['host'] . ($port ? ':' . $port : '');
    }

    /**
     * Set whether https scheme should be enforced by filter().
     *
     * @param   bool            $enforceHttps   if true then https scheme will be enforced by filter().
     * @return  ExternalUrl     to maintain fluent interface
     */
    public function setEnforceHttps($enforceHttps)
    {
        $this->enforceHttps = (bool) $enforceHttps;
        return $this;
    }
}
