<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Application\Escaper\Escaper;

class Csrf extends AbstractHelper
{
    /**
     * Returns the CSRF token in use.
     *
     * @return string   the CSRF token
     */
    public function __invoke()
    {
        $csrf    = $this->services->get('csrf');
        $escaper = new Escaper;
        return $escaper->escapeHtml($csrf->getToken());
    }
}
