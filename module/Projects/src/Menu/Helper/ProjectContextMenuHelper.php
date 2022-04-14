<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Menu\Helper;

use Interop\Container\ContainerInterface;

/**
 * Context-only menu helper that will modify a menuitem to reflect a project context or discard it if there is no
 * project in context
 * Class ProjectContextMenuHelper
 * @package Application\Menu\Helper
 */
class ProjectContextMenuHelper extends ProjectAwareMenuHelper
{
    /**
     * Modifies an item's target if the context is for a project, the project supports the item and
     * the item already has a target. Otherwise, nullify the item
     * @return array|null
     */
    public function getMenu()
    {
        $item = parent::buildMenu();
        // Allow project characteristics to determine menu item availability
        return !empty($this->project) && $this->isDisabled()===false ? $item : null;
    }
}
