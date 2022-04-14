<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Service;

use Application\Service\P4Command;
use P4\Command\IDescribe;
use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface;

/**
 * Class File
 * @package Files\Service
 */
class File extends P4Command
{
    const DIFF2_COMMAND = 'diff2';
    const FSTAT_COMMAND = 'fstat';

    /**
     * Run diff2
     * @param ConnectionInterface   $connection       the connection
     * @param array                 $options          the options
     * @param array                 $files            files
     * @return CommandResult
     */
    public function diff2(ConnectionInterface $connection, array $options, array $files)
    {
        return $this->run($connection, self::DIFF2_COMMAND, $options, $files);
    }

    /**
     * Run fstat
     * @param ConnectionInterface   $connection       the connection
     * @param array                 $options          the options
     * @param string                $file             file
     * @return CommandResult
     */
    public function fstat($connection, array $options, string $file)
    {
        return $this->run($connection, self::FSTAT_COMMAND, $options, [$file]);
    }

    /**
     * Determine if the type value in the fstat output indicates a ktext file
     * @param mixed         $fstatOutput        the fstat output
     * @return false|int 1 if the type does indicate ktext, 0 if not, false if an error
     */
    public function isKText($fstatOutput)
    {
        // ktext filetypes include things like: ktext, text+ko, text+mko, kxtext, etc.
        return $fstatOutput
            && isset($fstatOutput[IDescribe::TYPE])
            && preg_match('/kx?text|.+\+.*k/i', $fstatOutput[IDescribe::TYPE]);
    }
}
