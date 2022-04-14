<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Controller;

use Api\IModelFields;
use Api\IRequest;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Config\Setting;
use Application\Connection\ConnectionFactory;
use Application\Controller\AbstractIndexController;
use Application\Helper\ArrayHelper;
use Application\Helper\BooleanHelper;
use Application\InputFilter\InputFilter;
use Application\Model\IModelDAO;
use Application\Permissions\ConfigCheck;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Groups\Model\Group;
use Groups\Model\Config;
use Groups\View\Helper\NotificationSettings;
use Notifications\Settings;
use Projects\Model\Project as ProjectModel;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractIndexController implements IModelFields, IRequest
{
    public function groupsAction()
    {
        $services    = $this->services;
        $permissions = $services->get('permissions');
        $p4          = $services->get(ConnectionFactory::P4_ADMIN);
        $user        = $services->get('user')->getId();
        $groupDAO    = $services->get(IModelDAO::GROUP_DAO);
        $request     = $this->getRequest();
        $format      = $request->getQuery(self::FORMAT);
        $keywords    = $request->getQuery(self::KEYWORDS);
        $viewHelpers = $services->get('ViewHelperManager');
        $appConfig   = $services->get('config');

        // for non-json requests, render the template and exit
        if ($format !== 'json') {
            // if super_only is set and only super users can view groups
            $this->checkGroupSettings();
            return new ViewModel(
                [
                    'keywords'     => $keywords,
                    'canAddGroups' => $permissions->is('groupAddAllowed')
                ]
            );
        }

        $max             = $request->getQuery(self::MAX);
        $after           = $request->getQuery(self::AFTER);
        $fields          = $request->getQuery(self::FIELDS);
        $sort            = $request->getQuery(self::SORT);
        $excludeProjects = $request->getQuery(self::EXCLUDE_PROJECTS);
        $ignoreBlacklist = BooleanHelper::isTrue($request->getQuery(self::IGNORE_EXCLUDE_LIST));

        // normalize sort parameter(s)
        $sort   = is_array($sort) ? $sort : array_filter(explode(',', $sort));
        $sortBy = [];
        foreach ($sort as $field) {
            $reverse = substr($field, 0, 1) === '-';
            $field   = $reverse ? substr($field, 1) : $field;
            $sortBy += [$field => $reverse];
        }

        // do not allow sub-sorting by isInGroup (can only be a primary sort)
        if (isset($sortBy['isInGroup']) && key($sortBy) !== 'isInGroup') {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => "Cannot sub-sort by isInGroup field."]);
        }

        if ($sortBy) {
            $groups = $groupDAO->fetchAll([IModelDAO::SORT => $sortBy], $p4);
        } else {
            $groups = $groupDAO->fetchAll([IModelDAO::SORT => ['name' => false]], $p4);
        }
        // getArrayCopy is shallow like toArray(false), we do not want a deep copy, just id -> model
        $groups = $groups->getArrayCopy();
        if ($user && isset($sortBy['isInGroup'])) {
            $reverse    = $sortBy['isInGroup'];
            $userGroups = $groupDAO->fetchAll(
                [Group::FETCH_BY_USER => $user, Group::FETCH_BY_USER_MODE => Group::USER_MODE_ALL],
                $p4
            );
            $userGroups = array_intersect_key($groups, $userGroups->getArrayCopy());
            $groups     = ($reverse ? $userGroups + $groups : $groups + $userGroups);
        }

        // now that we have groups (optionally sorted), let's seek past 'after'
        // we do this outside of the groups loop below because it avoids unserializing
        if ($after) {
            $position = ArrayHelper::getKeyIndex($groups, $after);
            $groups   = array_slice($groups, $position ? ($position + 1) : sizeof($groups));
        }

        // split keywords into words.
        $keywords = array_filter(preg_split('/[\s,]+/', $keywords), 'strlen');

        // prepare list of fields to include in result
        $groupFields = $groups ? current($groups)->getFields() : [];
        $extraFields = [
            'config',
            'name',
            'description',
            Config::FIELD_EMAIL_FLAGS,
            'notificationSettings',
            'ownerAvatars',
            'memberCount',
            'isEmailEnabled',
            'isMember',
            'isInGroup'
        ];
        $fields      = (array) $fields ?: array_keys(array_flip(array_merge($groupFields, $extraFields)));
        $projectDAO  = $this->services->get(IModelDAO::PROJECT_DAO);
        // if we include projects, prepare list with project ids accessible for the current user
        if (!$excludeProjects) {
            $projects           = $projectDAO->fetchAll([], $p4);
            $accessibleProjects = $services->get('projects_filter')->filter($projects)->invoke('getId');
        }

        // build the result set
        $result        = [];
        $utf8          = new Utf8Filter;
        $avatar        = $viewHelpers->get('avatar');
        $preformat     = $viewHelpers->get('preformat');
        $caseSensitive = $p4->isCaseSensitive();

        // Get list of blacklisted groups
        $mode        = isset($appConfig['mentions']['mode']) ? $appConfig['mentions']['mode'] : 'global';
        $blacklisted =
            $mode == 'disabled' || $ignoreBlacklist ?
                [] : ConfigManager::getValue($appConfig, ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST);

        foreach ($groups as $group) {
            // if we have surpassed our max limit, bail.
            if ($max && count($result) >= $max) {
                break;
            }

            // if we have enabled mentions,
            // and the group id is on the groups blacklist - skip this group
            // check against all values in groupBlacklist
            if (ConfigCheck::isExcluded($group->getId(), $blacklisted, $caseSensitive)) {
                continue;
            }

            $isProject = strpos($group->getId(), ProjectModel::KEY_PREFIX) === 0;
            $projectId = $isProject ? substr($group->getId(), strlen(ProjectModel::KEY_PREFIX)) : null;

            // skip project group if we exclude projects or the project is not accesible
            if ($isProject && ($excludeProjects || !in_array($projectId, $accessibleProjects))) {
                continue;
            }

            $name = null;
            if ($isProject) {
                foreach ($projects as $project) {
                    if (ProjectModel::KEY_PREFIX . $project->getId() === $group->getId()) {
                        $name = $project->getName();
                        break;
                    }
                }
            }

            $config = $group->getConfig();

            // optionally match keywords against id, name and description
            if ($keywords) {
                foreach ($keywords as $keyword) {
                    if (stripos($group->getId(), $keyword) === false
                        && stripos($config->getName(), $keyword) === false
                        && stripos($config->getDescription(), $keyword) === false
                    ) {
                        continue 2;
                    }
                }
            }

            $data = [];
            foreach ($fields as $field) {
                switch ($field) {
                    case 'config':
                        $value = $config->get();
                        break;
                    case 'name':
                        $value = $name ? $name : $config->getName();
                        break;
                    case 'description':
                        $value = (string) $preformat($config->getDescription());
                        break;
                    case Config::FIELD_EMAIL_FLAGS:
                        $value = $group->getConfig()->getEmailFlags();
                        break;
                    case 'isEmailEnabled':
                        $value = (bool) array_filter($config->getEmailFlags());
                        break;
                    case 'isMember':
                        $value = $user
                            ? $groupDAO->isMember(
                                $user,
                                $group->getId(),
                                true,
                                $p4,
                                null,
                                Group::USER_MODE_USER,
                                $groups
                            )
                            : null;
                        break;
                    case 'isInGroup':
                        if (!$user) {
                            $value = null;
                        } elseif (isset($userGroups)) {
                            $value = isset($userGroups[$group->getId()]);
                        } else {
                            $value =
                                $groupDAO->isMember(
                                    $user,
                                    $group->getId(),
                                    true,
                                    $p4,
                                    null,
                                    Group::USER_MODE_USER,
                                    $groups
                                )
                                || in_array($user, $group->getOwners());
                        }
                        break;
                    case 'memberCount':
                        $value = count($groupDAO->fetchAllMembers($group->getId(), false, $groups, null, $p4));
                        break;
                    case 'notificationSettings':
                        $value = $group->getConfig()->getNotificationSettings();
                        break;
                    case 'ownerAvatars':
                        $value = [];
                        foreach ($group->getOwners() as $owner) {
                            $value[] = $avatar($owner, 32, true, null, false);
                        }
                        break;
                    default:
                        // skip invalid fields
                        if (!$group->hasField($field)) {
                            continue 2;
                        }

                        // though unexpected, some fields (Group) can include invalid UTF-8 sequences
                        // so we filter them, otherwise json encoding could crash with an error
                        $value = $utf8->filter($group->get($field));
                }
                $data[$field] = $value;
            }

            $result[] = $data;
            $lastSeen = $group->getId();
        }

        $model = new JsonModel(
            [
                'groups'   => $result,
                'lastSeen' => isset($lastSeen) ? $lastSeen : null
            ]
        );
        return $model;
    }

    public function addAction()
    {
        // ensure user is permitted to add groups
        $this->services->get('permissions')->enforce('groupAddAllowed');

        // if super_only is set and only super users and admin can view groups
        $this->checkGroupSettings();

        // by default add generates the id from the name
        $route      = $this->getEvent()->getRouteMatch();
        $idFromName = $route->getParam('idFromName', true);

        return $this->doAddEdit(InputFilter::MODE_ADD, null, $idFromName);
    }

    public function editAction()
    {
        // if super_only is set and only super users and admin can view groups
        $this->checkGroupSettings();

        // before we call the doAddEdit method we need to ensure the
        // group exists and the user has rights to edit it.
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        // only Perforce super users or group owners can edit the group
        $this->services->get('permissions')->enforceOne(['super', 'owner' => $group]);

        // ensure the id in the post is the value passed in the url.
        // we don't want to risk having differing opinions.
        $this->getRequest()->getPost()->set('Group', $group->getId());

        return $this->doAddEdit(InputFilter::MODE_EDIT, $group);
    }

    /**
     * Prepare a model for the notification settings page and pass control to the common
     * addEdit method for further processing.
     *
     * Validates that the group exists and that the user has the permission to modify it.
     *
     * @return void|ViewModel
     */
    public function notificationsAction()
    {
        // if super_only is set and only super users and admin can view groups
        $this->checkGroupSettings();

        // before we call the doAddEdit method we need to ensure the
        // group exists and the user has rights to edit it.
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        // only Perforce super users or group owners can edit the group
        $this->services->get('permissions')->enforceOne(['super', 'owner' => $group]);

        // ensure the id in the post is the value passed in the url.
        // we don't want to risk having differing opinions.
        $this->getRequest()->getPost()->set('Group', $group->getId());

        // Ensure group/subgroup/owner cannot be set via notifications
        if ($this->getRequest()->isPost()) {
            $this->getRequest()->getPost()->set('Owners', null)->set('Subgroups', null)->set('Users', null);
        }

        $response = $this->doAddEdit(InputFilter::MODE_EDIT, $group);
        return $response;
    }

    public function groupAction()
    {
        // Get list of blacklisted groups
        $ignoreBlacklist = BooleanHelper::isTrue($this->getRequest()->getQuery(self::IGNORE_EXCLUDE_LIST));
        $caseSensitive   = $this->services->get('p4_admin')->isCaseSensitive();
        $appConfig       = $this->services->get('config');
        $mode            = isset($appConfig['mentions']['mode']) ? $appConfig['mentions']['mode'] : 'global';
        $blacklisted     =
            $mode == 'disabled' || $ignoreBlacklist ?
                [] : ConfigManager::getValue($appConfig, ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST);

        $group = $this->getRequestedGroup();
        if (!$group || ConfigCheck::isExcluded($group->get('Group'), $blacklisted, $caseSensitive)) {
            if ($this->getRequest()->getQuery(self::FORMAT) !== 'json') {
                return new ViewModel(['group' => null]);
            }

            return new JsonModel(['group' => null]);
        }

        if ($this->getRequest()->getQuery(self::FORMAT) !== 'json') {
            return new ViewModel(['group' => $group]);
        }

        return new JsonModel(
            ['group' => $group->get() + ['config' => $group->getConfig()->get()]]
        );
    }

    public function deleteAction()
    {
        $services    = $this->services;
        $translator  = $services->get('translator');
        $permissions = $services->get('permissions');
        $p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
        $p4User      = $services->get(ConnectionFactory::P4_USER);
        $groupDAO    = $services->get(IModelDAO::GROUP_DAO);

        // if super_only is set and only super users and admin can view groups
        $this->checkGroupSettings();

        // request must be a post or delete
        $request = $this->getRequest();
        if (!$request->isPost() && !$request->isDelete()) {
            return new JsonModel(
                [
                    self::IS_VALID   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST or HTTP DELETE required.')
                ]
            );
        }

        // attempt to retrieve the specified group to delete
        $group = $this->getRequestedGroup();
        if (!$group) {
            return new JsonModel(
                [
                    self::IS_VALID   => false,
                    'error'     => $translator->t('Cannot delete group: group not found.')
                ]
            );
        }

        // ensure only super users or group owners can delete the entry
        $permissions->enforceOne(['super', 'owner' => $group]);

        // delete the group in Perforce and associated config
        // pass the user's connection for spec delete so the -a flag is used if necessary
        // TODO: forcing the user should not be necessary
        $groupDAO->delete($group->setConnection($p4User));

        return new JsonModel(
            [
                self::IS_VALID => true,
                'id'      => $group->getId()
            ]
        );
    }

    public function reviewsAction($format = null)
    {

        $query = $this->getRequest()->getQuery();
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        // forward json requests to the reviews module
        if ($query->get('format') === 'json' || $format === 'json') {
            // if query doesn't already contain a filter for group, add one
            $query->set('group', $query->get('group') ?: $group->getId());

            return $this->forward()->dispatch(
                \Reviews\Controller\IndexController::class,
                ['action' => 'index']
            );
        }

        return new ViewModel(
            [
                'group' => $group
            ]
        );
    }

    /**
     * Populates post data with missing Users, Owners, Subgroups. If a command line API post
     * just has 'Users[]=' to clear the users we want to make sure for validation that it will
     * be valid to remove them.
     * @param $group
     * @param $data
     * @return mixed
     */
    public function populateMissingData($group, $data)
    {
        // isset() in PHP 5.3.3 returns true for null values in objects,
        // while later versions return false (as expected).
        // Do not use for example $data['Owners'] == null as sometimes a empty
        // string is used to clear values and then == null is truthy which
        // results in values being set to previous
        if (!isset($data['Owners']) || is_null($data['Owners'])) {
            $data['Owners'] = $group->getOwners();
        }
        if (!isset($data['Users']) || is_null($data['Users'])) {
            $data['Users'] = $group->getUsers();
        }
        if (!isset($data['Subgroups']) || is_null($data['Subgroups'])) {
            $data['Subgroups'] = $group->getSubgroups();
        }
        return $data;
    }

    /**
     * This is a shared method to power both add and edit actions.
     *
     * @param   string          $mode           one of 'add' or 'edit'
     * @param   Group|null      $group          only passed on edit, the group for starting values
     * @param   bool            $idFromName     only passed on add, use the name to generate an id
     * @return  ViewModel       the data needed to render an add/edit view
     */
    protected function doAddEdit($mode, Group $group = null, $idFromName = false)
    {
        $services    = $this->services;
        $permissions = $services->get('permissions');
        $p4User      = $services->get(ConnectionFactory::P4_USER);
        $p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
        $config      = $services->get('config');
        $groupDAO    = $services->get(IModelDAO::GROUP_DAO);
        $request     = $this->getRequest();
        $groupConfig = null;
        // Get the current notification settings from the global config
        $globalConfigSettings = $config[Settings::NOTIFICATIONS];
        // decide whether user can edit group name
        $nameAdminOnly = isset($config['groups']['edit_name_admin_only'])
            ? (bool) $config['groups']['edit_name_admin_only']
            : false;
        $canEditName   = !$nameAdminOnly || $permissions->is('admin');

        // if super_only is set and only super users and admin can view groups
        $this->checkGroupSettings();

        if ($request->isPost()) {
            $data = $request->getPost();
            // The API does not populate all required data for validation if not specifically set so
            // populate it so that we can validate correctly
            if ($group) {
                $this->populateMissingData($group, $data);
            }
            $filter = $services->build(
                Services::GROUP_FILTER,
                [Config::FIELD_USE_MAILING_LIST => $data[Config::FIELD_USE_MAILING_LIST]]
            );
            // optionally set the id from the name
            if (isset($data['name']) && $idFromName) {
                $data['Group'] = $filter->nameToId($data['name']);
            }

            // set up our filter with data and the add/edit mode
            $filter->setMode($mode)
                   ->verifyNameAsId($idFromName)
                   ->setData($data);

            // mark name field not-allowed if user cannot modify it
            // this will cause an error if data for this field is posted
            if ($group && !$canEditName) {
                $filter->setNotAllowed('name');
            }

            // if we are in edit mode, set the validation group to process
            // only defined fields we received posted data for
            // any other fields specified in data are ignored.
            if ($filter->isEdit()) {
                $filter->setValidationGroupSafe(array_keys($data->toArray()));
            }

            // if the data is valid, setup the group and save it
            $isValid = $filter->isValid();
            if ($isValid) {
                $values = $filter->getValues();

                if (isset($values[Config::FIELD_USE_MAILING_LIST]) && $values[Config::FIELD_USE_MAILING_LIST]) {
                    if (!isset($values[Config::GROUP_NOTIFICATION_SETTINGS])) {
                        // Force clear the notification settings if there are none and useMailingList is set
                        $values[Config::GROUP_NOTIFICATION_SETTINGS] =
                            NotificationSettings::buildFromFlatArray([]);
                    }
                } else {
                    // If the mailing list checkbox is not set and the email was cleared pass that through
                    if (!isset($values['emailAddress']) && isset($values['hiddenEmailAddress'])) {
                        $values['emailAddress'] = $values['hiddenEmailAddress'];
                    }
                }

                // save the group
                // limit the values we set to just those that are explicitly defined
                // this keeps spec fields and config fields separate and avoids tainting the config
                $group        = $group ?: new Group($p4Admin);
                $groupConfig  = $group->getConfig();
                $editAsOwner  = $filter->isEdit() && $permissions->is(['owner' => $group]);
                $addAsAdmin   = $filter->isAdd()  && $permissions->is('admin') && !$permissions->is('super');
                $groupFields  = array_flip($group->getDefinedFields());
                $configFields = array_flip($groupConfig->getDefinedFields());
                $groupConfig->set(array_intersect_key($values, $configFields));
                $group->setId($values['Group'])->set(array_intersect_key($values, $groupFields))
                    ->setConnection($p4User);
                $group->getConfig()->setConnection($group->getConnection());
                $groupDAO->save($group, $editAsOwner, $addAsAdmin);
            }

            // Work out where to redirect
            $redirect = $request->getUri()->getPath().'?saved=true';
            if ($mode === InputFilter::MODE_ADD) {
                // Only redirect to group page if its a newly created group.
                $redirect = $this->url()->fromRoute('group', ['group' => $filter->getValue('Group')]);
            }
            return new JsonModel(
                [
                    self::IS_VALID  => $isValid,
                    self::MESSAGES => $filter->getMessages(),
                    'group'    => $group ? $group->get() + ['config' => $group->getConfig()->get()] : null,
                    'redirect' => $redirect
                ]
            );
        }

        // prepare view for form
        $view  = new ViewModel;
        $group = $group ?: new Group;
        $view->setVariables(
            [
                'mode'        => $mode,
                'group'       => $group,
                'canEditName' => $canEditName,
                'notificationSettings' => $this->buildNotificationSettings(
                    $globalConfigSettings,
                    $group->getConfig()->getNotificationSettings()
                )
            ]
        );

        return $view;
    }

    /**
     * Helper method to return model of requested group or false if group
     * id is missing or invalid.
     *
     * @return  Group|false  group model or false if group id is missing or invalid
     */
    protected function getRequestedGroup()
    {
        $services = $this->services;
        $id       = $this->getEvent()->getRouteMatch()->getParam('group');
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $groupDAO = $services->get(IModelDAO::GROUP_DAO);

        // attempt to retrieve the specified group
        // translate invalid/missing id's into a 404
        // also deny access to project groups as they are managed by Swarm and should not be modified by users
        try {
            $group = $groupDAO->fetchById($id, $p4Admin);
            if (strpos($group->getId(), ProjectModel::KEY_PREFIX) !== 0) {
                return $group;
            }
        } catch (SpecNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        $this->getResponse()->setStatusCode(404);
        return false;
    }
    /**
     * Helper method to check if required_login is enabled and ensure we don't
     * block users from seeing groups.
     *
     */
    protected function checkGroupSettings()
    {
        $services = $this->services;
        $config   = $services->get('config');
        // if super_only is set and only super users can view groups
        if ($config['groups']['super_only'] === true) {
            $services->get('permissions')->enforce('super');
        }
    }

    /**
     * Merge the currently effective notification settings into a single nested array which guarantees
     * name values pairs that can be used to enable and set checkbox values on a settings page.
     *
     * @param array $globalSettings out of the application config
     * @param $groupSettings out of the group key data
     * @return array merged values for the ui
     */
    protected function buildNotificationSettings(array $globalSettings, $groupSettings)
    {
        $settings = [];

        foreach (NotificationSettings::$settings as $settingGroup => $group) {
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
                 *   Undefined and disabled group settings will result in the default value being used
                 *
                 */
                $settings[$settingGroup][$settingProperties['id']] = [
                    'disabled'=> $disabled ? "disabled" : "",
                    'default' => $default,
                    'value' => ! $disabled && isset($groupSettings[$settingGroup][$settingID])
                        ? $groupSettings[$settingGroup][$settingID] === Setting::ENABLED ? "checked" : ""
                        : $default
                ];
            }
        }
        return $settings;
    }
}
