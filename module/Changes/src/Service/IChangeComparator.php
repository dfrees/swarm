<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Service;

use P4\Connection\ConnectionInterface;
use P4\Spec\Change;

/**
 * Interface IChangeComparator
 *
 * @package Change\Service
 */
interface IChangeComparator
{
    const NO_DIFFERENCE    = 0;
    const DIFFERENCE       = 1;
    const OTHER_DIFFERENCE = 2;

    /**
     * Determine if files in the given changes (pending or submitted) are different in any meaningful way.
     * We compare following properties:
     *  - file names
     *  - file contents (digests)
     *  - file types
     *  - actions
     *  - working (head) revs
     *  - resolved/unresolved states
     * and return an integer based on the results:
     *  0 if changes don't differ in any of compared properties
     *  1 if any file names, contents or types differ
     *  2 if changes differ in any other compared properties.
     *
     * @param Change|int           $a          pending or submitted change to compare
     * @param Change|int           $b          pending or submitted change to compare
     * @param ConnectionInterface  $connection The P4 connection
     * @return  int         0 if changes don't differ
     *                      1 if changes differ in file names, types or digests
     *                      2 if changes differ in any other compared fields
     */
    public function compare($a, $b, ConnectionInterface $connection);

    /**
     * Determine if the stream spec in the given changes are different. We use the diff2 command only.
     * If no streams in both changelists, we have no differences to check.
     * If the streams are different between the two changelists automatically just declare there is a difference.
     * If the streams are the same in both changelists, now run diff2 to see if the content is the same.
     *
     * @param Change|int           $a          pending or submitted change to compare
     * @param Change|int           $b          pending or submitted change to compare
     * @param ConnectionInterface  $connection The P4 connection
     * @return  int         0 if spec's don't differ
     *                      1 if spec differ
     */
    public function compareStreamSpec($a, $b, ConnectionInterface $connection);
}
