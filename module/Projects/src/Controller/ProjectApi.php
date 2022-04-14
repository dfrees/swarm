<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Model\IModelDAO;
use Application\Permissions\Permissions;
use Exception;
use P4\Model\Fielded\Iterator;
use Projects\Model\IProject;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Projects\Model\Project;
use Record\Exception\NotFoundException as RecordNotFoundException;

/**
 * Class ProjectApi
 * @package Projects\Controller
 */
class ProjectApi extends AbstractRestfulController
{
    const DATA_PROJECTS = 'projects';

    /**
     * Gets a project
     * Example success response
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "projects": [
     *          {
     *              "name": Jam,
     *              "defaults": {
     *                 reviewers: [],
     *              },
     *              "description: "This is the Jam project.",
     *              "members": [
     *                "bruno",
     *                "rupert",
     *              ],
     *              "subgroups": [],
     *              "owners": [],
     *              "branches": [
     *                 {
     *                    id: "main",
     *                    name: "Main",
     *                    workflow: null,
     *                    paths: [],
     *                    ...
     *                 },
     *              ],
     *              "jobView": "",
     *              "emailFlags": {
     *                  change_email_project_users: 1,
     *                  review_email_project_members: 1,
     *               },
     *              "tests": {
     *                  enabled: false,
     *                  url: "",
     *                  postBody: "",
     *                  postFormat: "",
     *               },
     *              "deploy": {
     *                  enabled: false,
     *                  url: "",
     *               },
     *              "delete": false,
     *              "private": false,
     *              "workflow": null,
     *              "retainDefaultReviewers": false,
     *              "minimumUpVotes": null,
     *              "id": "Jam",
     *          }
     *         ]
     *    }
     * }
     *
     * Query parameters supported:
     *  fields - filter by fields
     *  metadata - include a metadata field for each project containing extra information for ease of use
     *      "metadata": {
     *          "userRoles": [ each role user has ],
     *      }
     *
     *
     * Example error response
     *
     * Unauthorized response 401, if require_login is true
     * {
     *   "error": "Unauthorized"
     * }
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @param mixed $id The Project ID
     * @return mixed|JsonModel
     */
    public function get($id)
    {
        $p4Admin     = $this->services->get(ConnectionFactory::P4_ADMIN);
        $dao         = $this->services->get(IModelDAO::PROJECT_DAO);
        $errors      = null;
        $project     = null;
        $projectData = null;
        try {
            $project     = $dao->fetchById($id, $p4Admin, true);
            $fields      = $this->getRequest()->getQuery(IRequest::FIELDS);
            $projectData = $this->modelsToArray([$project], []);
            $projectData = $this->limitFieldsForAll($projectData, $fields);
        } catch (RecordNotFoundException $e) {
            // Project id is good but no record found or private project filtered from dao
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($errors) {
            $json = $this->error([$errors], $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([self::DATA_PROJECTS => $projectData]);
        }
        return $json;
    }

    /**
     * Gets all projects data according to logged in user and return all project that the
     * user has permission for based on  project visibility
     * Example success response
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "projects": [
     *          {
     *              "name": Jam,
     *              "defaults": {
     *                 reviewers: [],
     *              },
     *              "description: "This is the Jam project.",
     *              "members": [
     *                "bruno",
     *                "rupert",
     *              ],
     *              "subgroups": [],
     *              "owners": [],
     *              "branches": [
     *                 {
     *                    id: "main",
     *                    name: "Main",
     *                    workflow: null,
     *                    paths: [],
     *                    ...
     *                 },
     *              ],
     *              "jobView": "",
     *              "emailFlags": {
     *                  change_email_project_users: 1,
     *                  review_email_project_members: 1,
     *               },
     *              "tests": {
     *                  enabled: false,
     *                  url: "",
     *                  postBody: "",
     *                  postFormat: "",
     *               },
     *              "deploy": {
     *                  enabled: false,
     *                  url: "",
     *               },
     *              "delete": false,
     *              "private": false,
     *              "workflow": null,
     *              "retainDefaultReviewers": false,
     *              "minimumUpVotes": null,
     *              "id": "Jam",
     *          },
     *          ...
     *          ...
     *         ]
     *    }
     * }
     *
     * Query parameters supported:
     *  fields - filter by fields
     *
     *
     * Example error response
     *
     * Unauthorized response 401, if require_login is true
     * {
     *   "error": "Unauthorized"
     * }
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @return mixed|JsonModel
     */
    public function getList()
    {
        $p4Admin       = $this->services->get(ConnectionFactory::P4_ADMIN);
        $errors        = null;
        $projectsArray = [];
        $projects      = null;
        $request       = $this->getRequest();
        $query         = $request->getQuery();
        try {
            $filter  = $this->services->get(Services::GET_PROJECTS_FILTER);
            $options = $query->toArray();
            $fields  = $query->get(IRequest::FIELDS);

            $filter->setData($options);
            if ($filter->isValid()) {
                $options = $filter->getValues();
                $this->editOptions($options);
                $dao           = $this->services->get(IModelDAO::PROJECT_DAO);
                $projects      = $dao->fetchAll($options, $p4Admin);
                $projectsArray = $this->limitFieldsForAll($this->modelsToArray($projects, $options), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    self::DATA_PROJECTS => $projectsArray,
                ]
            );
        }
        return $json;
    }

    /**
     * Helper to set the filter private and include deleted projects to true
     * Add any other options here later as required.
     * @param array $options Update the options with new values.
     */
    private function editOptions(&$options)
    {
        $options[IModelDAO::FILTER_PRIVATES]     = true;
        $options[Project::FETCH_INCLUDE_DELETED] = true;
    }

    /**
     * Convert an iterator of Projects to an array representation merging in any required metadata
     * @param Iterator|array      $projects           iterator of projects
     * @param array         $options            options for merging arrays. Supports IRequest::METADATA to merge in
     *                                          metadata
     * @param array         $metadataOptions    options for metadata. Supports:
     *      IProject::FIELD_USERROLES    summary of user roles for project
     * @return array
     */
    public function modelsToArray($projects, $options, $metadataOptions = [])
    {
        $projectsArray = [];
        if (isset($options) && isset($options[IRequest::METADATA]) && $options[IRequest::METADATA] === true) {
            $metadataOptions += [
                IProject::FIELD_USERROLES => true,
            ];
            $dao              = $this->services->get(IModelDAO::PROJECT_DAO);
            $metadata         = $dao->fetchAllMetadata($projects, $metadataOptions);
            if ($metadata) {
                $count = 0;
                foreach ($projects as $project) {
                    $projectData = $project->toArray();
                    // ensure only admin/super or project members/owners can see tests/deploy
                    $checks = $project->hasOwners()
                        ? ['admin', 'owner' => $project]
                        : ['admin', 'member' => $project];

                    $this->unsetPrivilegedFields($projectData, $checks);
                    $projectsArray[] = array_merge($projectData, $metadata[$count++]);
                }
            }
        } else {
            foreach ($projects as $project) {
                $projectData = $project->toArray();
                // ensure only admin/super or project members/owners can see tests/deploy
                $checks = $project->hasOwners()
                    ? ['admin', 'owner' => $project]
                    : ['admin', 'member' => $project];

                $this->unsetPrivilegedFields($projectData, $checks);
                $projectsArray[] = $projectData;
            }
        }
        return array_values($projectsArray);
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
