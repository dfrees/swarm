<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Application\Config\ConfigManager;
use Application\Helper\BooleanHelper;
use Application\Model\IModelDAO;
use Application\View\Helper\Avatar;
use P4\Exception;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Log\Logger;
use Projects\Model\Project as ProjectModel;
use Users\Model\User;
use Laminas\View\Model\JsonModel;

/**
 * Swarm users
 */
class UsersController extends AbstractApiController
{
    const AVATAR = "avatar";
    /**
     * Get a list of Swarm users, with options to query by groups/users/current-user and to limit the fields returned
     * @throws \Application\Config\ConfigException
     * @return mixed
     */
    public function getList()
    {
        $request    = $this->getRequest();
        $services   = $this->services;
        $userDao    = $services->get(IModelDAO::USER_DAO);
        $groupDao   = $services->get(IModelDAO::GROUP_DAO);
        $fields     = $request->getQuery(self::FIELDS);
        $fetchUsers = $request->getQuery(self::USERS);
        $group      = $request->getQuery(self::GROUP);
        $current    = $request->getQuery(self::CURRENT, false);
        $p4Admin    = $services->get('p4_admin');
        $config     = $services->get('config');

        // If we are requesting for just current user
        if ($current) {
            return $this->currentUser($services, $fields);
        }

        // If required_login is set to true we shouldn't allow non logged in user to access users.
        if (ConfigManager::getValue($config, ConfigManager::SECURITY_REQUIRE_LOGIN) === true) {
            $services->get('permissions')->enforce('authenticated');
        }

        // if requested, get only users for a specified group
        $groupUsers = $group ? $groupDao->fetchAllMembers($group, null, null, null, $p4Admin) : null;

        $utf8    = new Utf8Filter;
        $users   = [];
        $options = [];
        if ($fetchUsers) {
            $options = [User::FETCH_BY_NAME => is_string($fetchUsers) ? explode(',', $fetchUsers) : $fetchUsers];
        } elseif ($group) {
            $options = [User::FETCH_BY_NAME => $groupUsers];
        }

        $mode              = isset($config['mentions']['mode']) ? $config['mentions']['mode'] : 'global';
        $ignoreExcludeList = BooleanHelper::isTrue($request->getQuery(self::IGNORE_EXCLUDE_LIST));
        $isExcluded        = $mode == 'disabled' || $ignoreExcludeList;

        $options[self::IGNORE_EXCLUDE_LIST] = $isExcluded;

        foreach ($userDao->fetchAll($options, $p4Admin) as $user) {
            // though unexpected, some fields (User or FullName) can include invalid UTF-8 sequences
            // so we filter them, otherwise json encoding could crash with an error
            $data      = [];
            $badFields = [];
            $message   = "";
            $fields    = $fields ? is_string($fields) ? explode(',', $fields) : $fields : $user->getFields();
            foreach ($fields as $field) {
                try {
                    if ($field === self::AVATAR) {
                        $data[$field] = Avatar::getAvatarDetails($config, $user->getId(), $user->getEmail());
                    } else {
                        $data[ $field ] = $utf8->filter($user->get($field));
                    }
                } catch (Exception $e) {
                    // We have encountered an fields we don't know about.
                    $badFields[] = $field;
                    $message     = $e->getMessage();
                    unset($e);
                }
            }
            if (!empty($badFields)) {
                $stringBadFields = implode(', ', $badFields);
                $response        = $this->getResponse();
                $response->setStatusCode(400);
                return new JsonModel(
                    [
                        self::IS_VALID => false,
                        'message' =>  $message. " [".$stringBadFields."]",
                        'code'    => 400
                    ]
                );
            }

            $users[] = $data;
        }

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($users, $fields)
            : $this->prepareErrorModel($users);
    }

    /**
     * Check if the user is logged in and then return the user object
     *
     * @param mixed     $services   application services
     * @param array     $fields     fields to restrict
     *
     * @return JsonModel
     */
    private function currentUser($services, $fields)
    {
        $userDao = $services->get(IModelDAO::USER_DAO);
        $error   = $authUser = false;
        try {
            $p4User = $services->get('p4_user');
            if ($p4User->isAuthenticated()) {
                $user     = $userDao->fetchById($p4User->getUser(), $p4User);
                $authUser = $fields
                    ? array_intersect_key($user->getValuesArray(), array_flip($fields))
                    : $user->getValuesArray();
            } else {
                $authUser = false;
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "Service with name \"p4_user\" could not be created") === 0) {
                $error = "User not logged in";
            } else {
                $error = $e->getMessage();
            }
        }

        return new JsonModel(
            [
                self::IS_VALID => $authUser ? true : false,
                self::MESSAGES => $error ? [$error] : [],
                'user'         => $authUser,
            ]
        );
    }

    /**
     * Clear the list of scopes followed by a user
     * @return  JsonModel
     * @throws \Exception
     */
    public function unfollowAllAction()
    {
        // only allow logged in users to unfollow
        $services = $this->services;
        $userDao  = $services->get(IModelDAO::USER_DAO);
        $services->get('permissions')->enforce('authenticated');
        $translator = $services->get('translator');
        $isValid    = false;

        // Get the admin and current logged in user.
        $p4Admin     = $services->get('p4_admin');
        $currentUser = $services->get('user');
        $user        = $this->getEvent()->getRouteMatch()->getParam('user');

        // Check if the user that is being attempted to process exist else return.
        if ($userDao->exists($user, $p4Admin)) {
            $user   = $userDao->fetchById($user, $p4Admin);
            $userId = $user->getId();
        } else {
            $messages = $translator->t("User '%s' does not exist", [$user]);
            return new JsonModel(
                [
                    self::IS_VALID => false,
                    self::MESSAGES => $messages,
                ]
            );
        }

        // Check if the current logged in user is on their own page else check if admin.
        if ($currentUser->getId() !== $userId) {
            $services->get('permissions')->enforce('admin');
        }

        try {
            $projectDAO = $services->get(IModelDAO::PROJECT_DAO);
            // Get the followed projects
            $config   = $user->getConfig();
            $projects = $projectDAO->fetchAll(
                [ProjectModel::FETCH_COUNT_FOLLOWERS => true],
                $p4Admin
            );
            // filter out projects not accessible to the current user
            $projects = $services->get('projects_filter')->filter($projects);
            // Now filter the projects to only the projects we are following.
            $projects->filterByCallback(
                function (ProjectModel $project) use ($userId) {
                    return $project->isFollowing($userId) === true;
                }
            );
            // Fetch the following users as well.
            $followingUsers = $config->getFollows('user');
            // Now remove all the users that this user is following
            foreach ($followingUsers as $user) {
                $config->removeFollow($user, 'user')->save();
            }
            // Now remove all the projects this user is following.
            foreach ($projects as $project) {
                $config->removeFollow($project->getId(), 'project')->save();
            }
            // Now check that all user and projects are removed
            $projects->filterByCallback(
                function (ProjectModel $project) use ($userId) {
                    return $project->isFollowing($userId) === true;
                }
            );
            // Fetch the following users as well.
            $followingUsers = $config->getFollows('user');
            // Now check that we have removed all following items and set isValid to true.
            if (count($followingUsers) === 0 && count($projects) === 0) {
                $isValid = true;
            }
        } catch (Exception $error) {
            // Do nothing right now.
            Logger::log(Logger::ERR, "UserAPI: UnfollowAll : We ran into a problem :" . $error->getMessage());
            throw new \RuntimeException($error->getMessage());
        }
        $messages = $translator->t("User %s is no longer following any Projects or Users.", [$userId]);
        return new JsonModel(
            [
                self::IS_VALID => $isValid,
                self::MESSAGES => $messages,
            ]
        );
    }

    /**
     * Determine if a value evaluates to true as a string or as a bool
     *
     * @param  mixed $value
     * @return bool
     */
    protected static function boolOrStringTrue($value)
    {
        if ($value === true || (is_string($value) && strtolower($value) == 'true')) {
            return true;
        }

        return false;
    }
}
