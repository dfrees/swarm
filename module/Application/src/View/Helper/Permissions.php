<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

/**
 * A convenience helper to expose the permissions service to the view.
 */
class Permissions extends AbstractHelper
{
    /**
     * Simply returns the permissions service.
     *
     * @return Application\Permissions\Permissions  the permissions class
     */
    public function __invoke()
    {
        return $this->services->get('permissions');
    }
}
