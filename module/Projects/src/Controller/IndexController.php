<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Controller;

use Application\Connection\ConnectionFactory;
use Application\Config\Services;
use Application\Controller\AbstractIndexController;
use Application\Filter\Preformat;
use Application\Config\ConfigManager;
use Application\Helper\ArrayHelper;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Application\Permissions\Permissions;
use Application\Permissions\PrivateProjects;
use Events\Listener\ListenerFactory;
use InvalidArgumentException;
use P4\Connection\ConnectionInterface as Connection;
use Projects\Filter\Project as ProjectFilter;
use Projects\Model\Project as ProjectModel;
use Projects\Model\Project;
use Queue\Manager;
use Record\Exception\NotFoundException;
use Laminas\Stdlib\RequestInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractIndexController
{
    public function addAction()
    {
        // ensure user is permitted to add projects
        $this->services->get(Permissions::PERMISSIONS)->enforce(Permissions::PROJECT_ADD_ALLOWED);

        // force the 'id' field to have the value of name
        // the input filtering will reformat it for us.
        $request = $this->getRequest();
        $request->getPost()->set('id', $request->getPost('name'));

        return $this->doAddEdit(ProjectFilter::MODE_ADD);
    }

    public function editAction()
    {
        // before we call the doAddEdit method we need to ensure the
        // project exists and the user has rights to edit it.
        $project = $this->getRequestedProject();
        if (!$project) {
            return null;
        }
        // First get the config option.
        $services    = $this->services;
        $config      = $services->get(ConfigManager::CONFIG);
        $allowView   = ConfigManager::getValue($config, ConfigManager::PROJECTS_ALLOW_VIEW_SETTINGS);
        $permissions = $services->get(Permissions::PERMISSIONS);
        // Setup who we want to allow to edit the project and check they are one of them roles.
        $hasOwner = ['admin', 'owner'  => $project];
        $noOwner  = ['admin', 'member' => $project];
        $checks   = $project->hasOwners() ? $hasOwner : $noOwner;
        $canEdit  = $permissions->isOne($checks);
        // If the allowView is set update the checks list to includes members
        if ($allowView) {
            $checks = array_merge($checks, ['member' => $project]);
        }
        $permissions->enforceOne($checks);
        // Two possible modes that can be set. View is for members only. Else admin/owner can edit.
        $mode = $allowView && !$canEdit ? ProjectFilter::MODE_VIEW : ProjectFilter::MODE_EDIT ;
        // ensure the id in the post is the value passed in the url.
        // we don't want to risk having differing opinions.
        $this->getRequest()->getPost()->set('id', $project->getId());

        // hydrate members and subgroups to ensure accuracy when editing
        $project->getMembers();

        return $this->doAddEdit($mode, $project);
    }

    public function deleteAction()
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);

        // request must be a post or delete
        $request = $this->getRequest();
        if (!$request->isPost() && !$request->isDelete()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST or HTTP DELETE required.')
                ]
            );
        }

        // attempt to retrieve the specified project to delete
        $project = $this->getRequestedProject([ProjectModel::FETCH_INCLUDE_DELETED => true]);
        if (!$project) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Cannot delete project: project not found.')
                ]
            );
        }

        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        // ensure only admin/super or project members/owners can delete the entry
        $checks = $project->hasOwners()
            ? ['admin', 'owner' => $project]
            : ['admin', 'member' => $project];
        $this->services->get(Permissions::PERMISSIONS)->enforceOne($checks);

        // shallow delete the project - we don't permanently remove the record, but set the 'deleted' field
        // to true so the project becomes hidden in general view
        // Expect -d action=undelete/delete as the primary use case
        $deleteMode = $request->getPost('action');
        if ($deleteMode === null) {
            // Look for action as a query parameter, if it wasn't already found
            $deleteMode = $request->getQuery()->get('action')?:"delete";
        }
        $project->setDeleted("delete" === $deleteMode);
        $projectDAO->save($project);

        return new JsonModel(
            [
                'isValid' => true,
                'id'      => $project->getId()
            ]
        );
    }

    /**
     * This is a shared method to power both add and edit actions.
     *
     * @param string       $mode    one of 'add' or 'edit'
     * @param ProjectModel $project only passed on edit, the project for starting values
     * @return  ViewModel       the data needed to render an add/edit view
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     * @throws \P4\File\Exception\Exception
     * @throws \P4\File\Exception\NotFoundException
     */
    protected function doAddEdit($mode, ProjectModel $project = null)
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $config  = $this->services->get(ConfigManager::CONFIG);
        $request = $this->getRequest();
        $readme  = $project ? $this->services->get(Services::GET_PROJECT_README)->getReadme($project) : '';

        // decide whether user can edit project name/branches
        $nameAdminOnly     = isset($config[ConfigManager::PROJECTS]['edit_name_admin_only'])
            ? (bool) $config[ConfigManager::PROJECTS]['edit_name_admin_only']
            : false;
        $branchesAdminOnly = isset($config[ConfigManager::PROJECTS]['edit_branches_admin_only'])
            ? (bool) $config[ConfigManager::PROJECTS]['edit_branches_admin_only']
            : false;
        $canEditName       = !$nameAdminOnly     || $this->services->get(Permissions::PERMISSIONS)->is('admin');
        $canEditBranches   = !$branchesAdminOnly || $this->services->get(Permissions::PERMISSIONS)->is('admin');

        if ($request->isPost()) {
            $model = $this->doPost($mode, $project, $request, $canEditName, $canEditBranches, $p4Admin, $readme);
            if ($model->getVariable('isValid')) {
                $this->addTask($model, $mode);
            }
            return $model;
        }

        // prepare view for form.
        $privateByDefault = isset($config[ConfigManager::PROJECTS]['private_by_default'])
            ? (bool) $config[ConfigManager::PROJECTS]['private_by_default']
            : false;
        $view             = new ViewModel;
        $view->setVariables(
            [
                'mode'             => $mode,
                'project'          => $project ?: new ProjectModel($p4Admin),
                'canEditName'      => $canEditName,
                'canEditBranches'  => $canEditBranches,
                'privateByDefault' => $privateByDefault,
                'readme'           => $readme
            ]
        );

        return $view;
    }

    /**
     * Applies a number of filters to and validates a post request. After which it saves and returns the Json model
     *
     * @param string              $mode               'add' or 'edit'
     * @param ProjectModel|null   $project            project model being added or updated
     * @param RequestInterface    $request            request to add or update the project model
     * @param bool                $canEditName        whether the requesting user can edit the project's name
     * @param bool                $canEditBranches    whether the requesting user can edit the project's branches
     * @param Connection          $p4Admin            P4 admin connection
     * @param string              $readme             The data needed to render the readme
     * @return JsonModel
     * @throws \P4\Exception
     */
    protected function doPost(
        $mode,
        $project,
        RequestInterface $request,
        bool $canEditName,
        bool $canEditBranches,
        $p4Admin,
        $readme
    ): JsonModel {
        $data = $request->getPost();
        // set up our filter with data and the add/edit mode
        $filter = $this->services->get('InputFilterManager')->get(ProjectFilter::class);
        $filter->setMode($mode)
            ->setCheckWorkflowPermission(false)
            ->setData($data);

        // mark name/branches fields not-allowed if user cannot modify them
        // this will cause an error if data for these fields are posted
        if ($project) {
            !$canEditName && $filter->setNotAllowed('name');
            !$canEditBranches && $filter->setNotAllowed('branches');
        }

        // if we are in edit mode, set the validation group to process
        // only defined fields we received posted data for
        if ($filter->isEdit()) {
            $filter->setValidationGroupSafe(array_keys($data->toArray()));
        }

        // if the data is valid, setup the project and save it
        $isValid = $filter->isValid();
        if ($isValid) {
            $values  = $filter->getValues();
            $project = $project ?: new ProjectModel($p4Admin);
            $project->set($values);
            $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
            $projectDAO->save($project);

            // remove followers for private projects
            if ($project->isPrivate()) {
                $userDao = $this->services->get(IModelDAO::USER_DAO);

                foreach ($project->getFollowers() as $user) {
                    $config = $userDao->fetchById($user, $p4Admin)->getConfig();
                    $config->removeFollow($project->getId(), 'project')->save();
                }
            }
        }

        if ($project) {
            $projectData           = $project->get();
            $projectData['tests']  = $project->getTests();
            $projectData['deploy'] = $project->getDeploy();
        }

        // Work out where to redirect
        $redirect = $request->getUri()->getPath() . '?saved=true';
        if ($mode === ProjectFilter::MODE_ADD) {
            // Only redirect to project page if its a newly created project.
            $redirect = $this->getRequest()->getBaseUrl() . '/projects/' . $filter->getValue('id');
        }
        return new JsonModel(
            [
                'isValid' => $isValid,
                'messages' => $filter->getMessages(),
                'redirect' => $redirect,
                'project' => isset($projectData) ? $projectData : null,
                'readme' => $readme,
                'mode' => $mode
            ]
        );
    }

    /**
     * Adds a task to the queue manager for an action
     *
     * @param JsonModel     $project    the project model in JSON form
     * @param string        $mode       the type of action performed
     */
    protected function addTask(JsonModel $project, $mode)
    {
        if ($mode == ProjectFilter::MODE_EDIT) {
            $task = ListenerFactory::PROJECT_UPDATED;
        } elseif ($mode == ProjectFilter::MODE_ADD) {
            $task = ListenerFactory::PROJECT_CREATED;
        }

        if (!isset($task)) {
            return;
        }

        $this->services->get(Manager::SERVICE)->addTask(
            $task,
            $project->getVariable('project')['id'],
            [
                'user' => $this->services->get(ConnectionFactory::P4_USER)->getUser()
            ]
        );
    }

    public function projectAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        // Fetch readme file and process output.
        $readme = $this->services->get(Services::GET_PROJECT_README)->getReadme($project);

        if ($this->getRequest()->getQuery()->get('format') === 'json') {
            $projectData = $project->get();

            // ensure only admin/super or project members/owners can see tests/deploy
            $checks = $project->hasOwners()
                ? ['admin', 'owner' => $project]
                : ['admin', 'member' => $project];

            $this->unsetPrivilegedFields($projectData, $checks);


            return new JsonModel(['project' => $projectData, 'readme' => $readme]);
        }

        return new ViewModel(['project' => $project, 'readme' => $readme]);
    }

    /**
     * Gets a list of projects.
     * @return JsonModel|ViewModel
     * @throws \Exception
     */
    public function projectsAction()
    {
        $data     = [];
        $fields   = null;
        $query    = $this->getRequest()->getQuery();
        $keywords = $query->get(ProjectModel::FETCH_BY_KEYWORDS);

        if ($query->get('format') !== 'json') {
            return new ViewModel(
                [
                    'maximum' => ConfigManager::getValue(
                        $this->services->get(ConfigManager::CONFIG),
                        ConfigManager::PROJECTS_FETCH_MAXIMUM,
                        50
                    ),
                    'keywords' => $keywords
                ]
            );
        }
        try {
            $fields = $this->validateFields($query, new Project());
        } catch (InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(400);
            // Empty model for default 'Bad Request' message
            return new JsonModel();
        }
        // $fieldsQuery is a string if called via the API and an array from the UI
        $options = [];
        if (isset($keywords) && strlen($keywords) > 0) {
            $options[ProjectModel::FETCH_BY_KEYWORDS]     = $keywords;
            $options[ProjectModel::FETCH_KEYWORDS_FIELDS] = [ProjectModel::FIELD_NAME, ProjectModel::FIELD_DESCRIPTION];
        }
        $max = $query->get(ProjectModel::FETCH_MAXIMUM);
        if (isset($max) && (int)$max>0) {
            $options[ProjectModel::FETCH_MAXIMUM] = (int)$max;
        }
        $after = $query->get(ProjectModel::FETCH_AFTER);
        if (isset($after) && strlen($after)>0) {
            $options[ProjectModel::FETCH_AFTER] = $after;
        }

        $user    = $this->services->get(ConnectionFactory::USER)->getId();
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // Exclude branches to limit the impact on resources by default
        $options[IModelDAO::FETCH_SUMMARY] = [ProjectModel::FIELD_BRANCHES];

        $workflow = $query->get(ProjectModel::FIELD_WORKFLOW);
        if (null !== $workflow) {
            $options[ProjectModel::FETCH_BY_WORKFLOW] = (string) $workflow;
            // If we are getting by workflow we cannot exclude branches but we want to exclude paths
            $options[IModelDAO::FETCH_SUMMARY] = [ProjectModel::FIELD_BRANCH_PATHS];
        }

        // fetch all projects
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $projects   = $projectDAO->fetchAll($options, $p4Admin);
        if (null !== $workflow) {
            $data[ProjectModel::FETCH_TOTAL_COUNT] = count($projects);
        }

        // filter out projects not accessible to the current user
        $projects = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($projects);

        // prepare data for output
        // include a virtual isMember field
        // by default, html'ize the description and provide the count of followers and members
        // pass listUsers   = true to instead get the listing of follower/member ids
        // pass disableHtml = true to stop html'izing the description
        $preformat   = new Preformat($this->services, $this->getRequest()->getBaseUrl());
        $listUsers   = (bool)$query->get('listUsers', false);
        $disableHtml = (bool)$query->get('disableHtml', false);
        $allFields   = (bool)$query->get('allFields', false);
        $idsOnly     = (bool)$query->get('idsOnly', false);
        // if asked for ids only, return list with projects ids
        if ($idsOnly) {
            return new JsonModel($projects->invoke('getId'));
        }
        $caseSensitive    = $p4Admin->isCaseSensitive();
        $data['projects'] = [];
        foreach ($projects as $project) {
            $values = $allFields
                ? $project->get()
                : [
                    'id' => $project->getId(), 'name' => $project->getName(), 'isPrivate' => $project->isPrivate()
                ];
            // Return the fields, if they specified allFields as well (which would be daft) we would already have them
            if ($fields && !$allFields) {
                foreach ($fields as $field) {
                    $values[$field] = $project->get($field);
                }
            }

            // get list of members, but flipped so we can easily check if user is a member
            // in the API route case (allFields = true), we will already have them
            // We always need to getAllMembers to consider groups as members on the project (subgroups)
            $members           = $project->getAllMembers(true);
            $values['members'] = $listUsers ? array_flip($members) : count($members);

            $owners           = isset($values['owners'])
                ? array_flip($values['owners'])
                : $project->getOwners(true);
            $values['owners'] = $listUsers ? array_flip($owners) : count($owners);

            if ($user) {
                $values['isMember'] = ArrayHelper::keyExists($user, $members, $caseSensitive);
                $values['isOwner']  = ArrayHelper::keyExists($user, $owners, $caseSensitive);
            }

            $values['description'] = $disableHtml
                ? $project->getDescription()
                : $preformat->filter($project->getDescription());

            // As we removed hidden from project model these are now returned.
            $this->unsetPrivilegedFields($values);
            $data['projects'][] = $values;
        }

        // Return results as Json model of projects, plus an optional count for workflows
        return new JsonModel($data);
    }

    public function activityAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        if ($this->getRequest()->getQuery()->get('format') === 'json') {
            $projectData = $project->get();

            // ensure only admin/super or project members/owners can see tests/deploy
            $checks = $project->hasOwners()
                ? ['admin', 'owner' => $project]
                : ['admin', 'member' => $project];

            if ($this->services->get(Permissions::PERMISSIONS)->isOne($checks)) {
                $projectData['tests']  = $project->getTests();
                $projectData['deploy'] = $project->getDeploy();
            }

            return new JsonModel(['project' => $projectData]);
        }

        return new ViewModel(
            [
                'project' => $project,
                'readme'  => $this->services->get(Services::GET_PROJECT_README)->getReadme($project)
            ]
        );
    }

    public function reviewsAction()
    {
        $query   = $this->getRequest()->getQuery();
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        // forward json requests to the reviews module
        if ($query->get('format') === 'json') {
            // if query doesn't already contain a filter for project, add one
            $query->set('project', $query->get('project') ?: $project->getId());

            return $this->forward()->dispatch(
                \Reviews\Controller\IndexController::class,
                ['action' => 'index', 'activeProject' => $project->getId()]
            );
        }

        return new ViewModel(
            [
                'project' => $project,
                'readme'  => $this->services->get(Services::GET_PROJECT_README)->getReadme($project)
            ]
        );
    }

    public function jobsAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }
        $readme = $this->services->get(Services::GET_PROJECT_README)->getReadme($project);

        return $this->forward()->dispatch(
            \Jobs\Controller\IndexController::class,
            [
                'action'    => 'job',
                'project'   => $project,
                'job'       => $this->getEvent()->getRouteMatch()->getParam('job'),
                'readme'    => $readme
            ]
        );
    }

    public function browseAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        $route  = $this->getEvent()->getRouteMatch();
        $mode   = $route->getParam('mode');
        $path   = $route->getParam('path');
        $readme = $this->services->get(Services::GET_PROJECT_README)->getReadme($project);

        // based on the mode, redirect to changes or files
        if ($mode === 'changes') {
            return $this->forward()->dispatch(
                \Changes\Controller\IndexController::class,
                [
                    'action'    => 'changes',
                    'path'      => $path,
                    'project'   => $project,
                    'readme'    => $readme
                ]
            );
        } else {
            return $this->forward()->dispatch(
                \Files\Controller\IndexController::class,
                [
                    'action'    => 'file',
                    'path'      => $path,
                    'project'   => $project,
                    'view'      => $mode === 'view'     ? true : null,
                    'download'  => $mode === 'download' ? true : null,
                    'readme'    => $readme
                ]
            );
        }
    }

    public function archiveAction()
    {
        $project = $this->getRequestedProject();
        if (!$project) {
            return;
        }

        $route = $this->getEvent()->getRouteMatch();
        $path  = $route->getParam('path');

        // archiving is handled by the Files module
        return $this->forward()->dispatch(
            \Files\Controller\IndexController::class,
            [
                'action'  => 'archive',
                'path'    => $path,
                'project' => $project,
            ]
        );
    }

    /**
     * Helper method to return model of requested project or false if project
     * id is missing, invalid or the project is not accessible for the current user.
     *
     * @return  ProjectModel|false   project model or false if project id is missing or invalid
     * @throws \Exception
     */
    protected function getRequestedProject($options = [])
    {
        $id         = $this->getEvent()->getRouteMatch()->getParam('project');
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $project    = null; // In some cases fetchall could return empty.

        // attempt to retrieve the specified project
        // translate invalid/missing id's into a 404
        $options += [ProjectModel::FETCH_BY_IDS => [$id], IModelDAO::FETCH_SUMMARY => []];
        try {
            $project = $projectDAO->fetchAll($options, $p4Admin)->first();
        } catch (NotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // return the project if its accessible for the current user, otherwise treat
        // inaccessible projects as "not found" to prevent information leakage
        if ($project && $this->services->get(PrivateProjects::PROJECTS_FILTER)->canAccess($project)) {
            return $project;
        }

        $this->getResponse()->setStatusCode(404);
        return false;
    }

    /**
     * Remove fields that should be hidden but have been unhidden to enable us to cache
     * the full model. So instead we now put fields into this function that should be
     * hidden to be removed from the return output.
     *
     * @param array $data   This is the array format of the full project.
     * @param array $checks The checks we should validate before un setting data.
     */
    private function unsetPrivilegedFields(&$data, $checks = [])
    {
        // check if we are one of the required permissions to see the data.
        if ($this->services->get(Permissions::PERMISSIONS)->isOne($checks)) {
            return;
        }
        unset($data['tests']);
        unset($data['deploy']);
    }
}
