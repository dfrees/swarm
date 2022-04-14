<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory to build Invokable Services
 * @package Application\Factory
 */
final class InvokableServiceFactory implements FactoryInterface
{
    /**
     * Builds an instance of requestedName passing it services and options (if provided).
     * @param ContainerInterface        $services       application services
     * @param string                    $requestedName  class name to construct, must implement InvokableService
     * @param array|null                $options        options
     * @return InvokableService
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null) : InvokableService
    {
        return (null === $options) ? new $requestedName($services) : new $requestedName($services, $options);
    }
}
