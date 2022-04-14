<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Changes\Service;

use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface as Connection;
use P4\Spec\Change as P4Change;

/**
 * Interface IChange. Describe responsibilities and values of a change service
 * @package Changes\Service
 */
interface IChange
{
    const ROOT_PATH        = '//...';
    const DESCRIBE_COMMAND = 'describe';
    const REOPEN_COMMAND   = 'reopen';
    const SHELVE_COMMAND   = 'shelve';
    const UNSHELVE_COMMAND = 'unshelve';
    const AFFECTED_FILES   = 'Affected files ...';
    const SHELVED_FILES    = 'Shelved files ...';
    const FILE_LINE_PREFIX = '#';
    const STREAM_FIELD     = 'stream';
    // Flags
    const FILES_ONLY         = '-Af';
    const PENDING_CHANGELIST = '-s';
    const QUIET_DIFF         = '-q';

    /**
     * Describe the change
     * @param Connection $connection the connection
     * @param array $options options for the P4 command
     * @param P4Change $change the change
     * @return CommandResult
     */
    public function describe(Connection $connection, array $options, P4Change $change);

    /**
     * Get a file list for the change
     * @param Connection $connection the connection
     * @param array $options options for the P4 command
     * @param P4Change $change the change
     * @return array
     */
    public function getFileList(Connection $connection, array $options, P4Change $change);

    /**
     * Get the stream for a given changelist if present.
     * @param Connection $connection The P4 connection.
     * @param P4Change $change The changelist we want to get the spec for.
     * @return string | null
     */
    public function getStream(Connection $connection, P4Change $change);

    /**
     * Running the reopen. The path is apart of the options input then flags ar apart of the options flags.
     * @param Connection $connection
     * @param array $options
     * @param P4Change $change
     * @return CommandResult
     */
    public function reopen(Connection $connection, array $options, P4Change $change);

    /**
     * Running the shelve. The path is apart of the options input then flags ar apart of the options flags.
     * @param Connection $connection
     * @param array $options
     * @param P4Change $change
     * @return CommandResult
     */
    public function shelve(Connection $connection, array $options, P4Change $change);

    /**
     * Running the unshelve. The path is apart of the options input then flags ar apart of the options flags.
     * @param Connection $connection
     * @param array $options
     * @param P4Change $change
     * @return CommandResult
     */
    public function unshelve(Connection $connection, array $options, P4Change $change);

    /**
     * Works out if the content has changed between two changes
     * @param Connection    $connection     connection to use
     * @param mixed         $changeId1      first change id
     * @param mixed         $changeId2      second change id
     * @return bool true if the content of the two changes differs, false otherwise. Checks the files statuses for
     * 'identical' and returns true early if any are found not to be identical
     */
    public function hasContentChanged(Connection $connection, $changeId1, $changeId2);

    /**
     * Get a common path based on the depot files returned by getFileData
     * @param Connection    $connection     connection to determine case sensitivity
     * @param P4Change      $change         the change
     * @return string a common path for all the change files
     * @see File::getCommonPath()
     */
    public function getCommonPath(Connection $connection, P4Change $change);
}
