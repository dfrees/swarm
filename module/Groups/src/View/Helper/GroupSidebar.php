<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\View\Helper;

use Application\Model\IModelDAO;
use Groups\Model\Group;
use Application\View\Helper\AbstractHelper;
use Application\View\Helper\Avatar as AvatarHelper;

class GroupSidebar extends AbstractHelper
{
    /**
     * Returns the markup for a group sidebar.
     *
     * @param   Group|string  $group  the group to render sidebar for
     * @return  string        markup for the group sidebar
     */
    public function __invoke($group)
    {
        $view     = $this->getView();
        $services = $this->services;
        $groupDAO = $services->get(IModelDAO::GROUP_DAO);
        $group    = $group instanceof Group ? $group : $groupDAO->fetchById($group, $services->get('p4_admin'));
        $owners   = $group->getOwners();
        $members  = $groupDAO->fetchAllMembers($group->getId());
        $user     = $services->get('user');
        $isMember = in_array($user->getId(), $members);

        $name = $group->getConfig()->getName();
        if ($name !== $group->getId()) {
            $name = $group->getId() . ' (' . $group->getConfig()->getName() . ')';
        }
        $avatarHtml = AvatarHelper::getAvatar(
            $services,
            $view,
            $group->getId(),
            $group->getConfig()->get('emailAddress'),
            $name,
            AvatarHelper::SIZE_256,
            false,
            AvatarHelper::ROUNDED,
            true,
            AvatarHelper::GROUPS_AVATAR
        );

        $html = '<div class="span3 profile-sidebar group-sidebar">'
              .   '<div class="profile-info">'
              .     '<div class="title pad2 padw3">'
              .       '<h4 class="force-wrap">' . $view->escapeHtml($group->getConfig()->getName()) . '</h4>'
              .     '</div>'
              .     '<div class="body">';

        $html .= '<div class="pad2" title="' . $view->escapeHtml($group->getConfig()->getName()) . '">'
              .      $avatarHtml
              .  '</div>';

        $description = $group->getConfig()->getDescription();
        if ($description) {
            $html .= '<div class="description force-wrap pad3">'
                  .    $view->preformat($description)
                  .  '</div>';
        }

        $html .=     '<div class="metrics pad2 padw4">'
              .        '<ul class="force-wrap clearfix">'
              .          '<li class="owners pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($owners) . '</span><br>'
              .            $view->tpe('Owner', 'Owners', count($owners))
              .          '</li>'
              .          '<li class="members pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($members) . '</span><br>'
              .            $view->tpe('Member', 'Members', count($members))
              .          '</li>'
              .        '</ul>'
              .      '</div>'
              .    '</div>';

        if ($group->getConfig()->get('useMailingList')) {
            $html .= '<div id="groupEmailAddress" class="emailAddress profile-block">'
                .  '    <div class="title pad1 padw0">' . $view->te('Email address') . '</div>'
                .  '    <small>' . ($group->getConfig()->get('emailAddress') ?:
                            "<span class=\"muted\">" . $view->te('<unset>') . "</span>")
                . '     </small>'
                .  '</div>';
        }
        // Close the profile-info section
        $html .=  '</div>';

        if ($owners) {
            $html .= '<div class="owners profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Owners') . '</div>'
                  .    $view->avatars($owners, 5)
                  .  '</div>';
        }

        if ($members) {
            $html .= '<div class="members profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Members') . '</div>'
                  .    $view->avatars($members, 5)
                  .  '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
