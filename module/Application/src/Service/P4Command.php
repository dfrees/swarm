<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Service;

use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface;

/**
 * Class P4Command
 * @package Application\Service
 */
class P4Command implements InvokableService
{
    protected $services;
    const COMMAND_FLAGS = 'flags';
    const TAGGED        = 'tagged';
    const IGNORE_ERRORS = 'ignore_errors';
    const INPUT         = 'input';

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Run the p4 command
     * @param Connection    $connection     the connection
     * @param string        $command        the command
     * @param array         $options        the options. Valid options are
     *                                      COMMAND_FLAGS => [],
     *                                      TAGGED => true|false
     *                                      IGNORE_ERRORS => true|false
     *                                      INPUT => null
     * @param array         $args
     * @return CommandResult
     * @see ConnectionInterface
     */
    protected function run(Connection $connection, string $command, array $options = [], array $args = [])
    {
        $options = $options + [
            self::COMMAND_FLAGS => [],
            self::TAGGED        => true,
            self::IGNORE_ERRORS => false,
            self::INPUT         => null
        ];
        $args    = $args ?: [];

        return $connection->run(
            $command,
            array_merge($options[self::COMMAND_FLAGS], $args),
            $options[self::INPUT],
            $options[self::TAGGED],
            $options[self::IGNORE_ERRORS]
        );
    }
}
