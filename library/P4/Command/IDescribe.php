<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\Command;

/**
 * Interface IDescribe, some common values typically returned by a describe command
 * @package P4\Command
 */
interface IDescribe
{
    const DEPOT_FILE = 'depotFile';
    const DIGEST     = 'digest';
    const FILE_SIZE  = 'fileSize';
    const TYPE       = 'type';
    const REV        = 'rev';
    const ACTION     = 'action';
    const DIFF_FROM  = 'diffFrom';
    const DIFF_TO    = 'diffTo';
}
