<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Menu\Helper;

/**
 * Interface IMenuHelper
 * Describe the functionality expected from implementing MenuHelper classes
 * @package Application\Menu\Helper
 */
interface IMenuHelper
{
    const MENU_ID   = 'id';
    const CSS_CLASS = 'cssClass';
    const CONTEXT   = 'context';
    const ENABLED   = 'enabled';
    const NAME      = 'name';
    const PRIORITY  = 'priority';
    const TARGET    = 'target';
    const TITLE     = 'title';
    const ROLES     = 'roles';

    /**
     * Get the menu item data for a helper; this is expected to be a name/value pair array
     * @return array|null
     */
    public function getMenu();
}
