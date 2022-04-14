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

class GroupToolbar extends AbstractHelper
{
    /**
     * Returns the markup for a group toolbar.
     *
     * @param   Group|string    $group  the group to render toolbar for
     * @return  string          markup for the group toolbar
     */
    public function __invoke($group)
    {
        $view        = $this->getView();
        $services    = $this->services;
        $permissions = $services->get('permissions');
        $event       = $services->get('Application')->getMvcEvent();
        $route       = $event->getRouteMatch()->getMatchedRouteName();
        $group       = $group instanceof Group
            ? $group : $services->get(IModelDAO::GROUP_DAO)->fetchById($group, $services->get('p4_admin'));

        // declare group links
        $links = [
            [
                'label'  => 'Activity',
                'url'    => $view->url('group', ['group' => $group->getId()]),
                'active' => $route === 'group',
                'class'  => 'overview-link',
                'icon'   => 'list'
            ],
            [
                'label'  => 'Reviews',
                'url'    => $view->url('group-reviews', ['group' => $group->getId()]),
                'active' => $route === 'group-reviews' || $route === 'review',
                'class'  => 'review-link',
                'icon'   => 'th-list'
            ]
        ];

        // add group settings links if user has permission
        if ($permissions->isOne(['super', 'owner' => $group])) {
            $links[] = [
                'label'  => 'Settings',
                'url'    => $view->url('edit-group', ['group' => $group->getId()]),
                'active' => $route === 'edit-group',
                'class'  => 'settings',
                'icon'   => 'wrench'
            ];
            $links[] = [
                'label'  => 'Notifications',
                'url'    => $view->url('edit-notifications', ['group' => $group->getId()]),
                'active' => $route === 'edit-notifications',
                'class'  => 'notifications',
                'icon'   => 'envelope'
            ];
        }

        // render list of links
        $list = '';
        foreach ($links as $link) {
            $list .= '<li class="' . ($link['active'] ? 'active' : '') . '">'
                  . '<a href="' . $link['url'] . '" class="' . $link['class'] . '">'
                  . '<i class="icon-' . $link['icon'] . '"></i> '
                  . $view->te($link['label'])
                  . '</a>'
                  . '</li>';
        }

        // render group toolbar
        $name = $view->escapeHtml($group->getConfig()->getName());
        $url  = $view->url('group', ['group' => $group->getId()]);
        return '<div class="group-navbar " data-group="' . $group->getId() . '">'
             . '  <ul class="nav nav-tabs">' . $list . '</ul>'
             . '</div>';
    }
}
