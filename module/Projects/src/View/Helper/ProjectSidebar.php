<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\View\Helper;

use Application\Connection\ConnectionFactory;
use Application\Helper\ArrayHelper;
use Application\Model\IModelDAO;
use Projects\Model\Project as ProjectModel;
use Application\View\Helper\AbstractHelper;
use Application\Config\ConfigManager;
use Projects\Model\Project;

class ProjectSidebar extends AbstractHelper
{
    /**
     * Returns the markup for a project sidebar.
     *
     * @param ProjectModel|string $project the project to render sidebar for
     * @return  string              markup for the project sidebar
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     */
    public function __invoke($project)
    {
        $view       = $this->getView();
        $services   = $this->services;
        $groupDao   = $services->get(IModelDAO::GROUP_DAO);
        $groups     = $groupDao->fetchAll([], $services->get(ConnectionFactory::P4_ADMIN));
        $owners     = $project->getOwners();
        $moderators = $project->getModeratorsWithGroups();
        $members    = $project->getAllMembers(false, $groups);
        $followers  = $project->getFollowers($members, $groups);
        $user       = $services->get(ConnectionFactory::USER);
        $isMember   = in_array($user->getId(), $members);
        $isFollower = in_array($user->getId(), $followers);

        // Get all the branches and mainlines for the given project. Then sort the list of branches into
        // alphabetical order with mainlines first in order.
        $allBranches   = $project->getBranches();
        $config        = $services->get(ConfigManager::CONFIG);
        $mainlinesPats = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAINLINES, []);
        $mainlines     = ArrayHelper::findMatchingStrings(
            $mainlinesPats,
            array_column($allBranches, ProjectModel::FIELD_NAME)
        );
        $branches      = $project->sortBranches($allBranches, Project::FIELD_NAME, $mainlines);

        $usersAndGroups  = $project->getUsersAndSubgroups();
        $userMembers     = isset($usersAndGroups['Users'])  ? $usersAndGroups['Users']  : [];
        $groupMembers    = isset($usersAndGroups['Groups']) ? $usersAndGroups['Groups'] : [];
        $userModerators  = isset($moderators['Users'])  ? $moderators['Users']  : [];
        $groupModerators = isset($moderators['Groups']) ? $moderators['Groups'] : [];

        $html = '<div class="span3 profile-sidebar project-sidebar">'
              .   '<div class="profile-info">'
              .     '<div class="title pad2 padw3">'
              .       '<h4 class="force-wrap">' . $view->te('About') . '</h4>'
              .     '</div>'
              .     '<div class="body">';

        if ($project->getDescription()) {
            $html .= '<div class="description force-wrap pad3">'
                  .    $view->preformat($project->getDescription())
                  .  '</div>';
        }

        if (!$isMember) {
            $click = "swarm.user.follow('project', '" . $view->escapeJs($project->getId()) . "', this);";
            $html .= '<div class="privileged buttons ' . ($project->getDescription() ? 'pad1' : 'pad2') . ' padw2">'
                  .    '<button type="button" '
                  .           'class="btn btn-primary btn-block ' . ($isFollower ? 'following' : '') . '" '
                  .         'onclick="' . $click . '">'
                  .      $view->te($isFollower ? 'Unfollow' : 'Follow')
                  .    '</button>'
                  .  '</div>';
        }

        $html .=     '<div class="metrics pad2">'
              .        '<ul class="force-wrap clearfix">'
              .          '<li class="members pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($members) . '</span><br>'
              .            $view->tpe('Member', 'Members', count($members))
              .          '</li>'
              .          '<li class="followers pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($followers) . '</span><br>'
              .            $view->tpe('Follower', 'Followers', count($followers))
              .          '</li>'
              .          '<li class="branches pull-left border-box pad2 padw0">'
              .            '<span class="count">' . count($branches) . '</span><br>'
              .            $view->tpe('Branch', 'Branches', count($branches))
              .          '</li>'
              .        '</ul>'
              .      '</div>'
              .    '</div>'
              .  '</div>';

        if ($owners) {
            $html .= '<div class="owners profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Owners') . '</div>'
                  .    $view->avatars($owners, 5)
                  .  '</div>';
        }

        if ($userModerators || $groupModerators) {
            $html .= '<div class="moderators profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Moderators') . '</div>';
            if ($groupModerators) {
                $html .= $view->groupAvatars(array_flip(array_flip($groupModerators)), 5, null, true, 'groupAvatars');
            }
            if ($userModerators) {
                $html .= $view->avatars(array_flip(array_flip($userModerators)), 5);
            }

            $html .=  '</div>';
        }

        if ($userMembers || $groupMembers) {
            $html .= '<div class="members profile-block">'
                  . '<div class="title pad1 padw0">' . $view->te('Members') . '</div>';
            if ($groupMembers) {
                $html .= $view->groupAvatars($groupMembers, 5, null, true, 'groupAvatars');
            }
            if ($userMembers) {
                $html .= $view->avatars($userMembers, 5);
            }

            $html .=  '</div>';
        }

        $html .= '<div class="followers profile-block ' . (!$followers ? 'hidden' : '') . '">'
              .    '<div class="title pad1 padw0">' . $view->te('Followers') . '</div>'
              .    $view->avatars($followers, 5)
              .  '</div>';

        if ($branches) {
            $html .= '<div class="branches profile-block">'
                  .    '<div class="title pad1 padw0">' . $view->te('Branches') . '</div>'
                  .      '<ul>';
            foreach ($branches as $branch) {
                $main = in_array($branch['name'], $mainlines);
                $url  = $view->url(
                    'project-browse',
                    ['project' => $project->getId(), 'mode' => 'files', 'path' => $branch['id']]
                );
                // Combine the moderators into a single array
                $moderators = array_merge(
                    array_flip(array_flip($branch["moderators-groups"]?:[])),
                    array_flip(array_flip($branch["moderators"]?:[]))
                );
                // Add moderators as a hover title if there are any
                $html .= '<li><a href="' . $url . '"'
                      .    ($moderators
                        ? ' title="' . $view->te('Moderators').': '.implode('&comma; ', $moderators).'"'
                        :'')
                      .    '>'
                      .    ($main ? '<strong>' : '')
                      .    $view->escapeHtml($branch['name'])
                      .    ($main ? '</strong>' : '')
                      .  '</a></li>';
            }
            $html .=   '</ul>'
                  .  '</div>';
        }

        $html .= '</div>';

        // truncate the description
        $html .= <<<EOT
          <script type="text/javascript">
              $(function(){
                  $('.profile-info .description').expander({slicePoint: 250});
              });
          </script>
EOT;

        return $html;
    }
}
