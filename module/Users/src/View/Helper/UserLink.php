<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\View\Helper;

use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\View\Helper\AbstractHelper;
use Application\Config\ConfigManager;
use Application\Config\ConfigException;

class UserLink extends AbstractHelper
{
    /**
     * Outputs the user ID and linkifies it if the user exists
     *
     * @param   string  $user       the user id to output and, if able, link to
     * @param   bool    $strong     optional, if false (default) not strong, if true user id wrapped in strong tag
     * @param   string  $baseUrl    optional, if specified, given string will be pre-pended to links
     * @return  string  the user id as a link if the user exists
     * @throws ConfigException
     */
    public function __invoke($user, $strong = false, $baseUrl = null)
    {
        $view     = $this->getView();
        $services = $this->services;
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $label    = $view->escapeHtml($user);

        if ($strong) {
            $label = '<strong>' . $label . '</strong>';
        }

        $userDao = $services->get(IDao::USER_DAO);
        if (!$userDao->exists($user, $p4Admin)) {
            return $label;
        }
        $fullName        = $userDao->fetchById($user, $p4Admin)->getFullName();
        $displayFullName = ConfigManager::getValue(
            $services->get(IConfigDefinition::CONFIG),
            ConfigManager::USER_DISPLAY_FULLNAME
        );
        $label           = $displayFullName ? $fullName : $label;
        $title           = $displayFullName ? $fullName . " (" .$user .")" : $fullName;


        return '<a alt="'.$title.'" title="'.$title.'" href="' . $baseUrl
            . $view->url('user', ['user' => $user]) . '">'. $label . '</a>';
    }
}
