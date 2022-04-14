<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Controller;

use Api\IRequest;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Config\Setting;
use Application\Connection\ConnectionFactory;
use Application\Controller\AbstractIndexController;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Notifications\Settings;
use P4\Connection\ConnectionInterface as Connection;
use P4\Spec\Exception\NotFoundException;
use Projects\Model\Project as ProjectModel;
use Users\Model\Config;
use Users\Model\User;
use Users\Settings\ReviewPreferences;
use Users\Settings\TimePreferences;
use Users\View\Helper\NotificationSettings as SettingsForm;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\InputFilter\InputFilter;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Users\View\Helper\Settings as UserSettings;
use Users\Authentication\IHelper;

class IndexController extends AbstractIndexController implements IRequest
{
    const FORM_NAME_PARAMETER = 'formName';
    const NOTIFICATIONS_FORM  = 'notificationForm';
    const SETTINGS_FORM       = 'settingsForm';

    public function indexAction()
    {
        // Must get out quickly for multi-server dashboard as nothing is initialised.
        if (MULTI_P4_SERVER && P4_SERVER_ID === null) {
            $config = $this->services->get('config');
            $view   = new ViewModel();
            $view->setTemplate('users/index/global-dashboard')->setTerminal(true);
            return $view;
        }
        $user       = $this->services->get('user');
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $groupDAO   = $this->services->get(IModelDAO::GROUP_DAO);
        $allGroups  = $groupDAO->fetchAll([], $this->services->get('p4_admin'))->toArray(true);
        $myProjects = (array)$this->services->get('projects_filter')->filter(
            $projectDAO->fetchAll(
                [],
                $this->services->get('p4_admin')
            )
        )->filterByCallback(
            function (ProjectModel $project) use ($user, $allGroups) {
                return $project->isInvolved(
                    $user,
                    [
                        ProjectModel::MEMBERSHIP_LEVEL_OWNER,
                        ProjectModel::MEMBERSHIP_LEVEL_MEMBER,
                        ProjectModel::MEMBERSHIP_LEVEL_MODERATOR
                    ],
                    $allGroups
                );
            }
        )->invoke('getId');

        return new ViewModel(
            [
                'myProjects'    => $myProjects
            ]
        );
    }

    public function userAction()
    {
        $request     = $this->getRequest();
        $p4Admin     = $this->services->get('p4_admin');
        $user        = $this->getEvent()->getRouteMatch()->getParam('user');
        $currentUser = $this->services->get('user');
        $userDAO     = $this->services->get(IModelDAO::USER_DAO);
        // Get the current notification settings from the global config
        $globalConfig         = $this->services->get('config');   // php5.3 compliance
        $globalConfigSettings = $globalConfig[Settings::NOTIFICATIONS];

        try {
            $user = $userDAO->fetchById($user, $p4Admin);
            if ($request->isPost()) {
                // Only allow the current user to update their own settings
                if ($user->getId() === $currentUser->getId()) {
                    $formName = $request->getPost(self::FORM_NAME_PARAMETER);
                    if ($formName === self::NOTIFICATIONS_FORM) {
                        // Process form data into config
                        $user->getConfig()->set(
                            Config::USER_NOTIFICATION_SETTINGS,
                            $this->generateNotificationsSettingsFromRequest($globalConfigSettings, $request)
                        )->save();
                    } elseif ($formName === self::SETTINGS_FORM) {
                        $user->getConfig()->set(
                            Config::USER_SETTINGS,
                            $this->generateSettingsFromRequest($request)
                        )->save();
                    }
                } else {
                    throw new \Application\Permissions\Exception\ForbiddenException(
                        "Please don't try to update other user's settings."
                    );
                    return;
                }
            }
        } catch (NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // turn our exception into a more appropriate 404 if
        // we cannot locate the requested user
        if (!$user instanceof User) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $config     = $user->getConfig();
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $projects   = $projectDAO->fetchAll(
            [ProjectModel::FETCH_COUNT_FOLLOWERS => true],
            $p4Admin
        );
        // filter out projects not accessible to the current user
        $projects  = $this->services->get('projects_filter')->filter($projects);
        $userId    = $user->getId();
        $groupDAO  = $this->services->get(IModelDAO::GROUP_DAO);
        $allGroups = $groupDAO->fetchAll([], $p4Admin)->toArray(true);

        $projects->filterByCallback(
            function (ProjectModel $project) use ($user, $allGroups) {
                return $project->isInvolved($user, null, $allGroups);
            }
        );
        $projects->sortByCallback(
            function ($a, $b) use ($userId, $projects, $allGroups) {
                return $a->getMembershipLevelForSort($userId, $allGroups)
                    - $b->getMembershipLevelForSort($userId, $allGroups) ?:
                    strcmp($a->getName(), $b->getName());
            }
        );

        $myProjects = (array)$projects->filterByCallback(
            function (ProjectModel $project) use ($user, $allGroups) {
                return $project->isInvolved($user, null, $allGroups);
            }
        )->invoke('getId');

        // Check if the user is admin or super.
        $isAdmin = $this->services->get('permissions')->isOne(['admin']);

        return new ViewModel(
            [
                'allGroups'            => $allGroups,
                'user'                 => $user,
                'config'               => $config,
                'projects'             => $projects,
                'myprojects'           => $myProjects,
                'following'            => $config->getFollows('user'),
                'followers'            => $config->getFollowers(),
                'projectfollowing'     => $config->getFollows('project'),
                'userFollows'          => $config->isFollower($currentUser->getId()),
                'isCurrentUser'        => $currentUser->getId() == $user->getId(),
                'isAdmin'              => $isAdmin,
                'notificationSettings' => $this->buildNotificationSettings(
                    $globalConfigSettings,
                    $config->get(Config::USER_NOTIFICATION_SETTINGS)
                ),
                'userSettings'         => $this->buildUserSettings(
                    $globalConfig,
                    $user
                )
            ]
        );
    }

    /**
     * Merge the currently effective settings into a single nested array which guarantees
     * name values pairs that can be used to enable and set checkbox values on a settings page.
     * @param array $globalConfig
     * @param $userSettings
     * @return array
     * @throws \Application\Config\ConfigException
     * @throws \Exception
     */
    private function buildUserSettings(array $globalConfig, $user)
    {
        $returnedSettings  = [
            ConfigManager::SETTINGS => [
                ReviewPreferences::REVIEW_PREFERENCES => [],
                TimePreferences::TIME_PREFERENCES     => []
            ]
        ];
        $reviewPreferences = ReviewPreferences::getReviewPreferences($globalConfig, $user);
        $timePreferences   = TimePreferences::getTimePreferences($globalConfig, $user);


        $settings = &$returnedSettings[ConfigManager::SETTINGS][ReviewPreferences::REVIEW_PREFERENCES];
        $default  = ConfigManager::getValue(
            $globalConfig,
            ConfigManager::USER_SETTINGS . '.' . ReviewPreferences::REVIEW_PREFERENCES
        );
        foreach (UserSettings::$userReviewPreferences as $preference) {
            $settings[$preference]['default'] = $default[$preference];
            $settings[$preference]['value']   = $reviewPreferences[$preference] === true ? 'checked' : '';
        }


        $settings = &$returnedSettings[ConfigManager::SETTINGS][TimePreferences::TIME_PREFERENCES];
        $default  = ConfigManager::getValue(
            $globalConfig,
            ConfigManager::USER_SETTINGS . '.' . TimePreferences::TIME_PREFERENCES
        );
        foreach (UserSettings::$userTimePreferences as $preference) {
            $settings[$preference]['default'] = $default[$preference];
            $settings[$preference]['value']   = $timePreferences[$preference];
        }
        return $returnedSettings;
    }

    /**
     * Merge the currently effective notification settings into a single nested array which guarantees
     * name values pairs that can be used to enable and set checkbox values on a notifications page.
     *
     * @param array $globalSettings out of the application config
     * @param $userSettings out of the user key dataa
     * @return array merged values for the ui
     */
    protected function buildNotificationSettings(array $globalSettings, $userSettings)
    {
        $settings = [];

        foreach (SettingsForm::$userSettings as $settingGroup => $group) {
            foreach ($group['settings'] as $settingProperties) {
                // Get the id for the current value
                $settingID = $settingProperties['id'];
                $disabled  = ! isset($globalSettings[$settingGroup][$settingID]) ||
                    Setting::FORCED_DISABLED === $globalSettings[$settingGroup][$settingID] ||
                    Setting::FORCED_ENABLED === $globalSettings[$settingGroup][$settingID];
                $default   = isset($globalSettings[$settingGroup][$settingID]) && (
                    Setting::FORCED_ENABLED === $globalSettings[$settingGroup][$settingID] ||
                    Setting::ENABLED === $globalSettings[$settingGroup][$settingID]) ? "checked" : "";
                /*
                 * Determine the currently effective settings for the notification preference:
                 *
                 *   When the administrator has configured a forced... value, the ui disables the checkbox
                 *   The default is taken from the setting in the swarm configuration, or unset if undefined
                 *   Undefined and disabled user settings will result in the default value being used
                 *
                 */
                $settings[$settingGroup][$settingProperties['id']] = [
                    'disabled'=> $disabled ? "disabled" : "",
                    'default' => $default,
                    'value' => ! $disabled && isset($userSettings[$settingGroup][$settingID])
                        ? $userSettings[$settingGroup][$settingID]=== Setting::ENABLED ? "checked" : ""
                        : $default
                ];
            }
        }
        return $settings;
    }

    /**
     * Generates user settings from posted request parameters.
     * @param $request
     * @return array
     */
    private function generateSettingsFromRequest($request)
    {
        $returnedSettings  =
            [ConfigManager::SETTINGS => []];
        $reviewPreferences = [];
        $timePreferences   = [];
        foreach (UserSettings::$userReviewPreferences as $preference) {
            $reviewPreferences[$preference] = $request->getPost($preference) === 'on' ? true : false;
        }
        foreach (UserSettings::$userTimePreferences as $preference) {
            $timePreferences[$preference] = $request->getPost($preference);
        }
        $returnedSettings[ConfigManager::SETTINGS][ReviewPreferences::REVIEW_PREFERENCES] = $reviewPreferences;
        $returnedSettings[ConfigManager::SETTINGS][TimePreferences::TIME_PREFERENCES]     = $timePreferences;

        return $returnedSettings;
    }

    /**
     * Take the form values from a post request body and copy them into a user config object ready to
     * be saved to the server.
     * @param $globalConfigSettings
     * @param $request mixed the http request object
     * @return array
     */
    protected function generateNotificationsSettingsFromRequest($globalConfigSettings, $request)
    {
        $newSettings = [];

        foreach (SettingsForm::$userSettings as $settingGroup => $group) {
            $newSettings[$settingGroup] = [];
            foreach ($group['settings'] as $settingProperties) {
                // Get the id for the current value
                $settingID = $settingProperties['id'];
                // Get the global config value for this setting
                $userCanChange = ! isset($globalConfigSettings[$settingGroup][$settingID]) || (
                        Setting::FORCED_DISABLED !== $globalConfigSettings[$settingGroup][$settingID] &&
                        Setting::FORCED_ENABLED !== $globalConfigSettings[$settingGroup][$settingID]);
                // Set the value from the optional form value
                if ($userCanChange) {
                    $newSettings[$settingGroup][$settingID] =
                        "on" === $request->getPost($settingGroup . '_' . $settingID)
                            ? Setting::ENABLED
                            : Setting::DISABLED;
                } else {
                    $newSettings[$settingGroup][$settingID] = $globalConfigSettings[$settingGroup][$settingID];
                }
            }
        }
        return $newSettings;
    }

    /**
     * Process the login.
     * @return JsonModel|ViewModel|Response
     * @throws \Application\Config\ConfigException
     */
    public function loginAction()
    {
        $request    = $this->getRequest();
        $session    = $this->services->get(IHelper::SESSION);
        $authHelper = $this->services->get(Services::AUTH_HELPER);
        if ($request->isPost()) {
            $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
            $user     = $request->getPost(IHelper::USER);
            $password = $request->getPost(IHelper::PASSWORD);
            $remember = $request->getPost(IHelper::REMEMBER);
            $logger   = $this->services->get(SwarmLogger::SERVICE);
            $error    = null;

            // clear any/all existing session data on login
            // note we need to explicitly restart the session (it's closed by default)
            $session->start();
            $session->getStorage()->clear();
            $samlRequest = isset($_REQUEST[IHelper::SAML_REQUEST]);
            if ($samlRequest) {
                $result = $authHelper->handleSamlResponse();
            } else {
                $result = $authHelper->authenticateCandidates($user, $password);
            }
            $authUser = $result[IHelper::AUTH_USER];
            $adapter  = $result[IHelper::ADAPTER];
            $isValid  = $result[IHelper::IS_VALID];
            // include the logged in version of layout/toolbar in the response
            $toolbar = null;
            if ($isValid) {
                $request                 = $this->getRequest();
                $data[IHelper::USERNAME] = $user;
                $data[IHelper::REMEMBER] = $remember;
                $data[IHelper::BASE_URL] = $request->getBaseUrl();
                $data[IHelper::HTTPS]    = $request instanceof Request
                    ? $request->getUri()->getScheme() == 'https' : false;
                $authHelper->setCookieInformation($data);

                $renderer    = $this->services->get('ViewRenderer');
                $toolbarView = new ViewModel;
                $toolbarView->setTemplate('layout/session');
                $toolbar  = $renderer->render($toolbarView);
                $authUser = $authHelper->invalidateCache($authUser, $p4Admin);
            } else {
                $logger->err($result[IHelper::ERROR]);
                $error = $authHelper->createErrorMessage($this->getResponse(), $p4Admin, $user);
            }

            $avatar = $this->services->get('ViewHelperManager')->get('avatar');

            // done modifying the session now (remember we explicitly open/close it)
            $session->writeClose();
            if ($samlRequest) {
                $referrer = $authHelper->getLoginReferrer();
                if ($referrer) {
                    return $this->redirect()->toUrl($referrer);
                } else {
                    return $this->redirect()->toRoute('home');
                }
            } else {
                // figure out the json model before we close up the session as
                // getting the CSRF token would otherwise re-open/close it.
                return new JsonModel(
                    [
                        'isValid' => $isValid,
                        'error' => !$isValid ? $error : null,
                        'toolbar' => $toolbar ?: null,
                        'info' => $isValid ? $adapter->getUserP4()->getInfo() : null,
                        'csrf' => $isValid ? $this->services->get('csrf')->getToken() : null,
                        'user' => $isValid ? [
                            'id' => $authUser->getId(),
                            'name' => $authUser->getFullName(),
                            'email' => $authUser->getEmail(),
                            'avatar' => $avatar($authUser, 64),
                            'isAdmin' => $adapter->getUserP4()->isAdminUser(true),
                            'isSuper' => $adapter->getUserP4()->isSuperUser(),
                            'addProjectAllowed' => $this->services->get('permissions')->is('projectAddAllowed'),
                            'mfa' => $adapter->getUserP4()->getMFAStatus(),
                            'groups' => $authUser->getGroups()
                        ] : null
                    ]
                );
            }
        }
        if ($authHelper->getSSO(P4_SERVER_ID) === ConfigManager::ENABLED) {
            $authHelper->handleSamlLogin($this->getRequest());
        } else {
            // prepare view for login form
            $user    = isset($_COOKIE[IHelper::REMEMBER]) ? $_COOKIE[IHelper::REMEMBER] : '';
            $partial = $request->getQuery(self::FORMAT) === 'partial';
            $view    = new ViewModel(
                [
                    'partial'         => $partial,
                    IHelper::USER     => $user,
                    IHelper::REMEMBER => strlen($user) != 0,
                    'statusCode'      => $this->getResponse()->getStatusCode()
                ]
            );
            $view->setTerminal($partial);

            return $view;
        }
    }

    /**
     * Logout from Swarm.
     * @return \Laminas\Http\Response
     * @throws \Application\Config\ConfigException
     */
    public function logoutAction()
    {
        $request    = $this->getRequest();
        $authHelper = $this->services->get(Services::AUTH_HELPER);
        $result     = $authHelper->logout($request, $this->getResponse());
        return $result;
    }

    public function followAction($unfollow = false)
    {
        // only allow logged in users to follow/unfollow
        $this->services->get('permissions')->enforce('authenticated');

        $p4Admin = $this->services->get('p4_admin');
        $user    = $this->services->get('user');
        $user->setConnection($p4Admin);

        // validate the type and id of the thing to follow
        $type    = $this->getEvent()->getRouteMatch()->getParam('type');
        $id      = $this->getEvent()->getRouteMatch()->getParam('id');
        $filter  = $this->getFollowFilter($p4Admin);
        $isValid = $filter->setData(['type' => $type, 'id' => $id])->isValid();

        // if this is not a post, indicate if the current user is
        // following the specified thing (type/id) or not.
        if (!$this->getRequest()->isPost()) {
            $config = $user->getConfig();
            return new JsonModel(['isFollowing' => $config->isFollowing($id, $type)]);
        }

        // add follow entry and save user's config if valid
        if ($isValid) {
            $config = $user->getConfig();
            if ($unfollow) {
                $config->removeFollow($id, $type)->save();
            } else {
                $config->addFollow($id, $type)->save();
            }
        }

        return new JsonModel(
            [
                'isValid'  => $isValid,
                'messages' => $filter->getMessages()
            ]
        );
    }

    public function unfollowAction()
    {
        // follow will enforce permissions
        return $this->followAction(true);
    }

    /**
     * Get filter for follow input data.
     *
     * @return  InputFilter     filter for new following record
     */
    protected function getFollowFilter(Connection $p4)
    {
        $filter     = new InputFilter;
        $userDAO    = $this->services->get(IModelDAO::USER_DAO);
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);

        // declare type field
        $filter->add(
            [
                'name'          => 'type',
                'required'      => true,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (!in_array($value, ['user', 'project'])) {
                                    return "Follow type ('$value') must be either 'user' or 'project'.";
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        // declare user/project id field
        $filter->add(
            [
                'name'          => 'id',
                'required'      => true,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($filter, $p4, $userDAO, $projectDAO) {
                                $type = $filter->getValue('type');
                                if ($type == 'user' && !$userDAO->exists($value, $p4)) {
                                    return "User ('$value') does not exist.";
                                }
                                if ($type == 'project' && !$projectDAO->exists($value, $p4)) {
                                    return "Project ('$value') does not exist.";
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        return $filter;
    }
}
