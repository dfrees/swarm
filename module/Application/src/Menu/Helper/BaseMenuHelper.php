<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Menu\Helper;

use Application\Helper\BooleanHelper;
use Interop\Container\ContainerInterface;

/**
 * Provide a default menu helper that is capable of generating very simple menu item data for menus within swarm
 * Class BaseMenuHelper
 * @package Application\Menu\Helper
 */
class BaseMenuHelper implements IMenuHelper
{
    const NO_MENU       = 'application';
    protected $context  = null;
    protected $cssClass = null;
    protected $enabled  = null;
    protected $id       = null;
    protected $priority = null;
    protected $services = null;
    protected $title    = null;
    protected $target   = null;
    protected $roles    = null;

    /**
     * Build a MenuHelper object to store any options provided and give access to configured services. As a basic
     * default, all properties default to null
     * BaseMenuHelper constructor.
     * @param ContainerInterface $container
     * @param array|null $options
     */
    public function __construct(ContainerInterface $container, array $options = null)
    {
        $this->services = $container;
        $this->id       = $options[self::MENU_ID]??$options[self::NAME]??null;
        $this->cssClass = $options[self::CSS_CLASS]??null;
        $this->context  = $options[self::CONTEXT]??null;
        $this->enabled  = $options[self::ENABLED]??true;
        $this->priority = $options[self::PRIORITY]??null;
        $this->target   = $options[self::TARGET]??null;
        $this->title    = $options[self::TITLE]??null;
        $this->roles    = $options[self::ROLES]??null;
    }

    /**
     * Returns a menu if no context is specified. If a context is specified then it is the context-aware menu helper's
     * job to build the menus and this returns null
     * @return array|null
     */
    public function getMenu()
    {
        return empty($this->context) ? $this->buildMenu() : null;
    }

    /**
     * Convert the properties of this menu helper into an array of name/value pairs that can be used to
     * build a menuitem by a client; e.g. a ui component
     * @return array|null
     */
    protected function buildMenu()
    {
        $id = $this->getMenuId();
        return $id !== self::NO_MENU ? [
            self::MENU_ID   => $id,
            self::ENABLED   => BooleanHelper::isTrue($this->enabled),
            self::TARGET    => $this->target?:"/$id/",
            self::CSS_CLASS => $this->cssClass?:$id,
            self::TITLE     => $this->title?:$id,
            self::PRIORITY  => $this->priority?:10000,
            self::ROLES     => $this->roles?:null,
        ] : null;
    }

    /**
     * Derive the id value for this helper; the priority of possible id values is the:
     *  - id passed as an option
     *  - class name of a specialised menu helper; e.g. ReviewsMenuHelper -> reviews
     *  - requested name given as an option
     * @return string|null
     */
    protected function getMenuId()
    {
        return $this->id??mb_strtolower(preg_replace('/\\\\.*/', '', get_called_class()));
    }
}
