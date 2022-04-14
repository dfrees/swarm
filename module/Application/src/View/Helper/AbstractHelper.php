<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper as ZendAbstractHelper;

/**
 * AbstractHelper to provide access to services.
 * @package Application\View\Helper
 */
class AbstractHelper extends ZendAbstractHelper
{
    protected $services = null;

    /**
     * AbstractHelper constructor.
     * @param $services
     */
    public function __construct($services)
    {
        $this->services = $services;
    }

    /**
     * Get application services
     * @return mixed services
     */
    public function getServices()
    {
        return $this->services;
    }
}
