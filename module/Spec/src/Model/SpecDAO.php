<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Spec\Model;

use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface;
use P4\Spec\Definition;

/**
 * Class SpecDAO
 * @package Spec\Model
 */
class SpecDAO implements InvokableService
{
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Fetch spec by its type.
     * @param string    $type   type to fetch, for example job, change
     * @param ConnectionInterface|null $connection
     * @return mixed the spec
     */
    public function fetch(string $type, ConnectionInterface $connection = null)
    {
        return Definition::fetch($type, $connection);
    }
}
