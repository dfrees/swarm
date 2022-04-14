<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\View\Helper;

use Application\Model\IModelDAO;
use Users\Model\User as UserModel;
use Application\View\Helper\AbstractHelper;
use Application\View\Helper\Avatar as AvatarHelper;

/**
 * Class Avatar
 * @package Users\View\Helper
 */
class Avatar extends AbstractHelper
{
    /**
     * Renders a image tag and optional link for the given user's avatar.
     *
     * @param   string|UserModel|null   $user   a user id or user object (null for anonymous)
     * @param   string|int              $size   the size of the avatar (e.g. 64, 128)
     * @param   bool                    $link   optional - link to the user (default=true)
     * @param   bool                    $class  optional - class to add to the image
     * @param   bool                    $fluid  optional - match avatar size to the container
     * @return string
     */
    public function __invoke($user = null, $size = null, $link = true, $class = null, $fluid = true)
    {
        $view     = $this->getView();
        $services = $this->services;
        $isModel  = $user instanceof UserModel;

        if (!$isModel) {
            $userDao = $services->get(IModelDAO::USER_DAO);
            $p4Admin = $services->get('p4_admin');
            if ($user && $userDao->exists($user, $p4Admin)) {
                $user    = $userDao->fetchById($user, $p4Admin);
                $isModel = true;
            } else {
                $user = $user ?: null;
                $link = false;
            }
        }

        $id       = $isModel ? $user->getId()       : $user;
        $email    = $isModel ? $user->getEmail()    : null;
        $fullName = $isModel ? $id . ' (' .$user->getFullName() . ')' : $user;

        return AvatarHelper::getAvatar($services, $view, $id, $email, $fullName, $size, $link, $class, $fluid);
    }
}
