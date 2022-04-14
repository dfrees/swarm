<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Http;

use Api\IRequest;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Stdlib\ParametersInterface;

/**
 * Class SwarmRequest
 *
 * @package Application\Http
 */
class SwarmRequest extends Request implements InvokableService
{
    public function __construct(ContainerInterface $services, array $options = null)
    {
        parent::__construct();
    }

    /**
     * An overridden method for getQuery to support fallback on older parameters.
     * Return value for new query param if only new query param exists.
     * Return value for old query param if only old query param exists.
     * Return value for new query param if both new and old query param exists.
     * @param  string|null $name    Parameter name to retrieve, or null to get the whole container.
     * @param  mixed|null  $default Default value to use when the parameter is missing.
     * @return ParametersInterface|mixed
     */
    public function getQuery($name = null, $default = null)
    {
        $param = parent::getQuery($name, $default);

        return (isset(IRequest::UPGRADED_PARAMS[$name]) && !$param) ?
            parent::getQuery(IRequest::UPGRADED_PARAMS[$name], $default) : $param;
    }
}
