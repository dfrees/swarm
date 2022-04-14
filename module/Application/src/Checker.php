<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application;

use Interop\Container\ContainerInterface;
use Application\Factory\InvokableService;

/**
 * Class Checker. Abstract checker for subclassing
 * @package Application
 */
abstract class Checker implements InvokableService
{
    const CHECKERS     = 'checkers';
    const NAME         = 'name';
    const OPTIONS      = 'options';
    const RETURN_VALUE = 'returnValue';

    protected $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Carry out a check
     * @param string            $check      name of the check
     * @param array|null        $options    optional data to assist the check
     */
    abstract protected function check(string $check, array $options = null);
}
