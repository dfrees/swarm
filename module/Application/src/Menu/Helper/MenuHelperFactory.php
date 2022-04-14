<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Menu\Helper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MenuHelperFactory implements FactoryInterface
{
    /**
     * Instantiate a new MenuHelper of the requested type
     * @param ContainerInterface $container The configured service container
     * @param string             $name      The name of a menu item
     * @param array|null         $options   Configuration options to be given to the helper constructor
     * @return object|null                  A ...MenuHelper instance
     */
    public function __invoke(ContainerInterface $container, $name, array $options = null)
    {
        $helperClass = $options['class']??$this->buildClassName($name);
        try {
            return new $helperClass($container, [IMenuHelper::NAME=>$name]+(array)$options);
        } catch (\Error $ex) {
            return new BaseMenuHelper($container, [IMenuHelper::NAME=>$name]+(array)$options);
        }
    }

    /** Derive a module based menu helper class name from the requested value
     * @param  string  $name  The name of the module to which this helper belongs
     * @return string         A class name that can be used to instantiate a menu helper
     */
    protected function buildClassName($name)
    {
        $className = ucfirst($name);
        return "$className\Menu\Helper\\$className".'MenuHelper';
    }
}
