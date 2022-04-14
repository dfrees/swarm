<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Cache;

interface ICacheStatus
{
    const STATUS_RUNNING     = 'Running';
    const STATUS_FAILED      = 'Failed';
    const STATUS_QUEUED      = 'Queued';
    const STATUS_NOT_RUNNING = 'Not Running';
    const STATUS_NOT_QUEUED  = 'Not Queued';

    /**
     * Get the value of the status.
     *
     * @return bool returns the status or false if not running.
     */
    public function getIntegrityStatus();

    /**
     * Set the value of the status.
     *
     * @param string $status
     * @return bool returns the true or false.
     */
    public function setIntegrityStatus($status = self::STATUS_QUEUED);
}
