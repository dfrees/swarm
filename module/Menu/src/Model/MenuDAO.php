<?php


namespace Menu\Model;

use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Exception\NotImplementedException;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Menu\Helper\IMenuHelper;
use Application\Menu\Helper\MenuHelperFactory;
use Application\Model\AbstractDAO;
use Application\Permissions\Permissions;
use P4\Connection\ConnectionInterface;

class MenuDAO extends AbstractDAO
{
    const MESSAGE = 'This method is not supported and implemented.';

    /**
     * This method returns the menu item for each module with a 'menu_helpers' setting. It also checks whether the menu
     * helper is enabled or not. If enabled, it will return it's menuItem, else it will skip it. Finally, the menu items
     * are sorted by their priority field.
     * @param array                    $options options for the menu item
     * @param ConnectionInterface|null $connection
     * @return array|mixed
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        $logger    = $this->services->get(SwarmLogger::SERVICE);
        $menuItems = [];
        $factory   = new MenuHelperFactory();
        foreach ($this->services->get(ConfigManager::CONFIG)['menu_helpers'] as $helperName => $helperOptions) {
            $helper = $factory->__invoke(
                $this->services,
                is_array($helperOptions) ? $helperName : $helperOptions,
                (array)$helperOptions + $options
            );
            if ($helper) {
                $menuItem = $helper->getMenu();
                if ($menuItem && !$this->hasAccess($menuItem[ IMenuHelper::ROLES])) {
                    try {
                        $p4User   = $this->services->get(ConnectionFactory::P4_USER);
                        $username = $p4User->getUser();
                        $logger->debug(
                            'User "' . $username . '" does not have role(s) '
                            . var_export($menuItem[IMenuHelper::ROLES], true) .' to view menu item '
                            . $menuItem[IMenuHelper::MENU_ID]
                        );
                    } catch (\Exception $userError) {
                        // Do nothing as this is only for debug purpose
                    }
                    continue;
                }

                if ($menuItem && $menuItem[IMenuHelper::ENABLED]) {
                    $menuItems[] = $menuItem;
                }
            }
        }
        // sort the menu items by priority level.
        usort(
            $menuItems,
            function ($a, $b) {
                return $a[IMenuHelper::PRIORITY] <=> $b[IMenuHelper::PRIORITY];
            }
        );
        return $menuItems;
    }

    /**
     * @inheritDoc
     * @throws NotImplementedException
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        throw new NotImplementedException($translator->t(self::MESSAGE));
    }

    /**
     * Check if the user has the Perforce Permissions to be able to view this menu item. Currently we do not have
     * the idea of roles but if we choose to add that later this can be plug in here.
     * @param array $roles  The roles we are to allow i.e. super or admin
     * @return bool
     */
    protected function hasAccess($roles)
    {
        $hasPermission = true;
        if ((array)$roles) {
            try {
                $hasPermission = $this->services->get(Permissions::PERMISSIONS)->isOne($roles);
            } catch (\Exception $err) {
                $logger = $this->services->get(SwarmLogger::SERVICE);
                $logger->err($err->getMessage());
                $hasPermission = false;
            }
        }
        return $hasPermission;
    }
}
