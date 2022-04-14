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
 * Returns the current request object for use in views.
 */
class Request extends AbstractHelper
{
    public function __invoke()
    {
        return $this->services->get('request');
    }
}
