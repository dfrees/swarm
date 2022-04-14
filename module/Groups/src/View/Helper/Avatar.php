<?php
/**
 * Group avatar.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\View\Helper;

use Application\Model\IModelDAO;
use Application\View\Helper\AbstractHelper;
use Application\View\Helper\Avatar as AvatarHelper;
use Groups\Model\Group as GroupModel;

class Avatar extends AbstractHelper
{
    /**
     * Renders a image tag and optional link for the given group's avatar.
     *
     * @param   string|GroupModel|null  $group  a group id or user object (null for anonymous)
     * @param   string|int              $size   the size of the avatar (e.g. 64, 128)
     * @param   bool                    $link   optional - link to the user (default=true)
     * @param   bool                    $class  optional - class to add to the image
     * @param   bool                    $fluid  optional - match avatar size to the container
     * @return string
     * @throws \Application\Config\ConfigException
     */
    public function __invoke($group = null, $size = null, $link = true, $class = null, $fluid = true)
    {
        $view     = $this->getView();
        $services = $this->services;
        $isModel  = $group instanceof GroupModel;

        if (!$isModel) {
            $p4Admin  = $services->get('p4_admin');
            $groupDAO = $services->get(IModelDAO::GROUP_DAO);
            if ($group && $groupDAO->exists($group, $p4Admin)) {
                $group   = $groupDAO->fetchById($group, $p4Admin);
                $isModel = true;
            } else {
                $group = $group ?: null;
                $link  = false;
            }
        }
        $id    = $isModel ? $group->getId()                             : $group;
        $email = $isModel ? $group->getConfig()->get('emailAddress')    : null;
        $name  = $isModel ? $group->getConfig()->getName()              : $group;
        if ($isModel && $name !== $group->getId()) {
            $name = $group->getId() . ' (' . $name . ')';
        }

        return AvatarHelper::getAvatar(
            $services,
            $view,
            $id,
            $email,
            $name,
            $size,
            $link,
            $class,
            $fluid,
            AvatarHelper::GROUPS_AVATAR
        );
    }
}
