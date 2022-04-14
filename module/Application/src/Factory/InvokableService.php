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

/**
 * Interface describing a Swarm service.
 * @package Application\Factory
 */
interface InvokableService
{
    /**
     * Constructor for the service.
     * @param ContainerInterface    $services   application services
     * @param array                 $options    options
     */
    public function __construct(ContainerInterface $services, array $options = null);
}
