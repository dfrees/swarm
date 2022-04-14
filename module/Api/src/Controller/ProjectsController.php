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
use Api\Converter\Reviewers;
use Projects\Model\Project as ProjectModel;
use Laminas\Http\Request;
use Laminas\View\Model\JsonModel;

/**
 * Swarm Projects
 *
 * @SWG\Resource(
 *   apiVersion="v9",
 *   basePath="/api/v9/"
 * )
 */
class ProjectsController extends AbstractApiController
{
    /**
     * Provide a list of projects, with options to: query by linked workflow, include user details, and limit fields
     * @return mixed
     */
    public function getList()
    {
        $fields   = $this->getRequest()->getQuery(self::FIELDS);
        $workflow = $this->getRequest()->getQuery(self::WORKFLOW);
        $result   = $this->forward(
            \Projects\Controller\IndexController::class,
            'projects',
            null,
            [
                'fields'                => $fields,
                'disableHtml'           => true,
                'listUsers'             => true,
                'allFields'             => $fields && strlen($fields) > 0 ? false : true,
                'idsOnly'               => $fields && $fields === ProjectModel::FIELD_ID,
                ProjectModel::FIELD_WORKFLOW => $workflow
            ]
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(
                $result->getVariables(),
                $fields
            )
            : $this->prepareErrorModel($result);
    }

    /**
     * Get an individual project, with an option to limit fields
     * @param   string  $id     Project ID to fetch
     * @return  mixed
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery(self::FIELDS);
        $result = $this->forward(
            \Projects\Controller\IndexController::class,
            'project',
            ['project' => $id]
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Create a new project using the data provided
     * @param mixed $data
     * @return JsonModel
     */
    public function create($data)
    {
        $supportedResult = $this->isSupported($data);
        if ($supportedResult === true) {
            $data = $this->filterOutQuotation($data, ['members', 'owners']);
            $this->collapseDefaultReviewers($data);
            $result = $this->forward(\Projects\Controller\IndexController::class, 'add', null, null, $data);

            if (!$result->getVariable('isValid')) {
                $this->getResponse()->setStatusCode(400);
                return $this->prepareErrorModel($result);
            }
        } else {
            return $supportedResult;
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Validates that the data provided is supported for the given API version.
     * @param $data
     * @return bool|JsonModel
     */
    private function isSupported($data)
    {
        $version                       = $this->getEvent()->getRouteMatch()->getParam('version');
        $modGroupsNotPermittedVersions = ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'];
        $defaultsNotPermittedVersions  = array_merge($modGroupsNotPermittedVersions, ['v7']);
        $defaultsError                 = 'defaults for projects and branches are only supported for v8+ of the API';

        // defaults on projects are only supported in API v8 and up
        if (isset($data['defaults']) && in_array($version, $defaultsNotPermittedVersions)) {
            $this->response->setStatusCode(405);
            return $this->prepareErrorModel(
                new JsonModel(
                    [
                        'error' => $defaultsError
                    ]
                )
            );
        }

        if (isset($data['branches']) && is_array($data['branches'])) {
            foreach ($data['branches'] as $branch) {
                // moderators-groups on branches are only supported in API v7 and up
                if (is_array($branch) && isset($branch['moderators-groups']) &&
                    in_array($version, $modGroupsNotPermittedVersions)) {
                    $this->response->setStatusCode(405);
                    return $this->prepareErrorModel(
                        new JsonModel(
                            [
                                'error' => 'moderators-groups for branches are only supported for v7+ of the API'
                            ]
                        )
                    );
                }
                // defaults on branches are only supported in API v8 and up
                if (is_array($branch) && isset($branch['defaults']) &&
                    in_array($version, $defaultsNotPermittedVersions)) {
                    $this->response->setStatusCode(405);
                    return $this->prepareErrorModel(
                        new JsonModel(
                            [
                                'error' => $defaultsError
                            ]
                        )
                    );
                }
            }
        }
        return true;
    }

    /**
     * Modify part of an existing project, replacing the content of any field names provided with the new values
     * @param mixed $data
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $supportedResult = $this->isSupported($data);
        if ($supportedResult === true) {
            $request  = $this->getRequest();
            $response = $this->getResponse();
            $request->setMethod(Request::METHOD_POST);
            $data = $this->filterOutQuotation($data, ['members', 'owners']);
            $this->collapseDefaultReviewers($data);
            $result = $this->forward(
                \Projects\Controller\IndexController::class,
                'edit',
                ['project' => $id],
                null,
                $data
            );
            if (!$result->getVariable('isValid')) {
                if ($response->isOK()) {
                    $this->getResponse()->setStatusCode(400);
                }

                return $this->prepareErrorModel($result);
            }

            if (!$result->getVariable('isValid')) {
                $this->getResponse()->setStatusCode(400);
                return $this->prepareErrorModel($result);
            }
        } else {
            return $supportedResult;
        }
        return $this->prepareSuccessModel($result);
    }

    /**
     * Convert default reviewers on branches and projects if set.
     *
     * 'groups' => array('group1' => array('required':'1')
     * 'users'  => array('user1'  => array())
     *
     * would be converted to
     *
     * 'swarm-group-group1' => array('required':'1')
     * 'user1'              => array()
     * @param $project
     */
    private function collapseDefaultReviewers(&$project)
    {
        if (isset($project['branches']) && is_array($project['branches'])) {
            foreach ($project['branches'] as &$branch) {
                if (isset($branch['defaults']) && isset($branch['defaults']['reviewers'])) {
                    $branch['defaults']['reviewers'] =
                        Reviewers::collapseUsersAndGroups($branch['defaults']['reviewers']);
                }
            }
        }
        if (isset($project['defaults']) && isset($project['defaults']['reviewers'])) {
            $project['defaults']['reviewers'] = Reviewers::collapseUsersAndGroups($project['defaults']['reviewers']);
        }
    }

    /**
     * Delete an existing project
     * @param mixed $id
     * @return JsonModel
     */
    public function delete($id)
    {
        $response = $this->getResponse();
        $result   = $this->forward(\Projects\Controller\IndexController::class, 'delete', ['project' => $id]);

        if (!$result->getVariable('isValid')) {
            if ($response->isOK()) {
                $this->getResponse()->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of project data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        $project = $model->getVariable('project');
        if ($project) {
            $model->setVariable('project', $this->normalizeProject($model, $project, $limitEntityFields));
        }

        // if a list of projects is present, normalize each one
        $projects = $model->getVariable('projects');
        if ($projects) {
            foreach ($projects as $key => $project) {
                $projects[$key] = $this->normalizeProject($model, $project, $limitEntityFields);
            }

            $model->setVariable('projects', $projects);
        }

        return $model;
    }

    protected function normalizeProject(&$model, &$project, $limitEntityFields = null)
    {
        unset($project['isMember']);
        unset($project['isOwner']);
        $project = $this->limitEntityFields($project, $limitEntityFields);
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        $modGroupsNotPermittedVersions = ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'];
        $defaultsNotPermittedVersions  = array_merge($modGroupsNotPermittedVersions, ['v7']);
        // Remove moderators-groups from the branch if not supported
        if (isset($project['branches'])) {
            foreach ($project['branches'] as &$branch) {
                if (isset($branch['moderators-groups']) &&
                    in_array($version, $modGroupsNotPermittedVersions)) {
                    unset($branch['moderators-groups']);
                }
                // Remove defaults from project branches unless v8 of the API or higher
                if (isset($branch['defaults']) &&
                    in_array($version, $defaultsNotPermittedVersions)) {
                    unset($branch['defaults']);
                }
                if (isset($branch['defaults']) && isset($branch['defaults']['reviewers'])) {
                    $branch['defaults']['reviewers'] =
                        Reviewers::expandUsersAndGroups($branch['defaults']['reviewers']);
                }
            }
        }
        // Remove defaults from projects unless v8 of the API or higher
        if (isset($project['defaults']) &&
            in_array($version, $defaultsNotPermittedVersions)) {
            unset($project['defaults']);
        }

        if (isset($project['defaults']) && isset($project['defaults']['reviewers'])) {
            $project['defaults']['reviewers'] = Reviewers::expandUsersAndGroups($project['defaults']['reviewers']);
        }
        // readme is not an entity field (special case) but is returned as part of the model.
        // We want to remove it if fields are limited and we have not specified it
        if ($limitEntityFields) {
            if (is_string($limitEntityFields)) {
                $limitEntityFields = explode(',', $limitEntityFields);
            }
            if (is_array($limitEntityFields) && !in_array('readme', $limitEntityFields)) {
                $model->__unset('readme');
            }
        }
        return $this->sortEntityFields($project);
    }
}
