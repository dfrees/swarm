<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Groups\Menu\Helper;

use Application\Config\ConfigManager;
use Application\Menu\Helper\BaseMenuHelper;
use Application\Permissions\Permissions;
use Interop\Container\ContainerInterface;

/**
 * Provide a Group menu helper that is capable of dealing with the config options to disable.
 * Class BaseMenuHelper
 * @package Application\Menu\Helper
 */
class GroupsMenuHelper extends BaseMenuHelper
{
    public function __construct(ContainerInterface $container, array $options = null)
    {
        parent::__construct($container, $options);
        $config    = $this->services->get(ConfigManager::CONFIG);
        $superOnly = ConfigManager::getValue(
            $config,
            ConfigManager::GROUPS_SUPER_ONLY,
            false
        );
        if ($superOnly) {
            $this->roles = array_unique(array_merge((array)$this->roles, [Permissions::SUPER]));
        }
    }
}
