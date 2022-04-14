<?php
/**
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Laminas\Filter\AbstractFilter;

/**
 * Filter class able to use services
 * @package Application\Filter
 */
abstract class ServiceAwareFilter extends AbstractFilter
{
    private $services;

    /**
     * @inheritDoc
     */
    public function filter($value)
    {
    }

    /**
     * Set the services
     * @param mixed $services application services
     */
    public function setServices($services)
    {
        $this->services = $services;
    }

    /**
     * Gets the application services
     * @return mixed
     */
    public function getServices()
    {
        return $this->services;
    }
}
