<?php
/**
* Perforce Swarm
*
* @copyright   2013-2022 Perforce Software. All rights reserved.
* @license     Please see LICENSE.txt in top-level readme folder of this distribution.
* @version     2022.1/2268697
*/

namespace Groups\View\Helper;

use Application\View\Helper\AbstractHelper;

class Avatars extends AbstractHelper
{
    public function __invoke($groups, $columns = 5, $size = null, $link = true, $class = null)
    {
        // re-index users so that keys are reliably numeric
        $groups = array_values((array) $groups);
        $html   = '<div class="avatars">';
        $total  = count($groups);
        foreach ($groups as $index => $group) {
            $html .= ($index % $columns == 0) ? "<div>" : "";
            $html .= '<span class="border-box">' . $this->getView()->groupAvatar($group, $size, $link, $class)
                  . "</span>";
            $html .= (($index + 1) % $columns == 0 || $index + 1 >= $total) ? "</div>" : "";
        }
        $html .= '</div>' . PHP_EOL;

        return $html;
    }
}
