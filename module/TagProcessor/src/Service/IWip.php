<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TagProcessor\Service;

/**
 * Interface IWip
 * @package TagProcessor\Service
 */
interface IWip
{
    const WIP_SERVICE = 'WorkInProgress';

    /**
     * Check if the ID contains the Wip keyword is in the description and return if matched.
     * the event.
     *
     * @param mixed $id
     */
    public function checkWip($id);
}
