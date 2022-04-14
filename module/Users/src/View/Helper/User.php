<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\View\Helper;

use Application\View\Helper\AbstractHelper;

class User extends AbstractHelper
{
    /**
     * Provides access to the current user from the view.
     */
    public function __invoke()
    {
        try {
            return $this->services->get('user');
        } catch (\Exception $e) {
            return new \Users\Model\User;
        }
    }
}
