<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class RouteMatch extends AbstractHelper
{
    protected $routeString = null;
    const ROUTE_VALUE      = ['project','group','change','file','review','user'];
    const MESSAGE          = " does not exist, or you do not have permission to view it.";
    const PAGE_NOT_FOUND   = "Page Not Found.";

    /**
     *
     * @param   string|null     Route string
     * @return  string          Error message
     */
    public function __invoke($value)
    {
        $this->routeString = $value;
        return $this->getErrorMessage($this->routeString);
    }

    /**
     * Create the message for not found error
     *
     * @param   string       Route name value
     * @param   string       Partial message string
     * @return  string       Error message
     */
    public function getErrorMessage($matchedRouteName)
    {
        foreach (self::ROUTE_VALUE as $route) {
            if (strpos($matchedRouteName, $route) !== false) {
                return ucfirst($route) . self::MESSAGE;
            }
        }

        return self::PAGE_NOT_FOUND;
    }
}
