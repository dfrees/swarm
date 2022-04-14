<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Menu\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Connection\ConnectionFactory;
use Application\Menu\Helper\IMenuHelper;
use Application\Model\IModelDAO;
use P4\Connection\Exception\CommandException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Exception;

/**
 * Class MenuApi
 * @package Menu\Controller
 */
class MenuApi extends AbstractRestfulController
{
    const CONTEXT_METADATA = 'contextMetadata';
    const CONTEXT_NAME     = 'contextName';

    /**
     * Gets a list of menu items according to the 'menu_helpers' config setting.
     *
     * This setting has a default configuration found across several modules' config files. It can be customized in
     * data/config.php. The 'menu_helpers' setting specifies a name, id, priority and, optionally, a class. If no class
     * is specified, the class is assumed to be the BaseMenuHelper class. Currently, the available classes are:
     *     BaseMenuHelper           - Returns a menu item not associated with a context.
     *                                It will return null if a context is specified.
     *                                Use when you only want the item appearing when there is no context.
     *     ProjectAwareMeuHelper    - Returns a menu item associated with a context, if  a context is specified.
     *                                It will return the same as the BaseMenuHelper if no context is specified
     *                                Use when you want the item to appear properly with or without a context.
     *     ProjectContextMenuHelper - Returns a menu item associated with a context, if a context is specified.
     *                                It will return null if no context is specified.
     *                                Use when you only want the item appearing when there is a context.
     * Note: There will be more MenuHelpers added. They will follow the form of *AwareMeuHelper and *ContextMenuHelper
     * Currently it has just been implemented for Projects.
     *
     * Given the above settings, context-based menu items are also filtered, according to whether the actual context
     * supports them. For example, if we are dealing with a project-context and the project doesn't have a readme file,
     * then the Overview menu item will be set to null and not returned in the list of menu items. If the project
     * doesn't have any branches, then the Files and Changes menu items will be set to null and not returned in the list
     * of menu items.
     *
     * Example without context:
     * GET /api/v10/menus
     * {
     *      "error": null,
     *      "messages": [],
     *      "data": {
     *          "menu": [
     *              {
     *                  "id": "reviews",
     *                  "enabled": true,
     *                  "target": "/reviews/",
     *                  "cssClass": "reviews",
     *                  "title": "Reviews",
     *                  "priority": 130,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "projects",
     *                  "enabled": true,
     *                  "target": "/projects/",
     *                  "cssClass": "projects",
     *                  "title": "Projects",
     *                  "priority": 140,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "files",
     *                  "enabled": true,
     *                  "target": "/files/",
     *                  "cssClass": "files",
     *                  "title": "Files",
     *                  "priority": 150,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "changes",
     *                  "enabled": true,
     *                  "target": "/changes/",
     *                  "cssClass": "changes",
     *                  "title": "Changes",
     *                  "priority": 160,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "jobs",
     *                  "enabled": true,
     *                  "target": "/jobs/",
     *                  "cssClass": "jobs",
     *                  "title": "Jobs",
     *                  "priority": 170,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "groups",
     *                  "enabled": true,
     *                  "target": "/groups/",
     *                  "cssClass": "groups",
     *                  "title": "Groups",
     *                  "priority": 180,
     *                  "roles": null
     *              },
     *              {
     *                  "id": "workflows",
     *                  "enabled": true,
     *                  "target": "/workflows/",
     *                  "cssClass": "workflows",
     *                  "title": "Workflows",
     *                  "priority": 190,
     *                  "roles": null
     *              }
     *          ]
     *      }
     *  }
     *
     * Example with project context, where the project doesn't have a readme or ay jobs:
     * GET /api/v10/menus?project=jam
     * {
     *     "error": null,
     *     "messages": [],
     *     "data": {
     *         "menu": [
     *             {
     *                 "id": "reviews",
     *                 "enabled": true,
     *                 "target": "/projects/jam/reviews/",
     *                 "cssClass": "reviews",
     *                 "title": "Reviews",
     *                 "priority": 130,
     *                 "roles": null
     *             },
     *             {
     *                 "id": "files",
     *                 "enabled": true,
     *                 "target": "/projects/jam/files/",
     *                 "cssClass": "files",
     *                 "title": "Files",
     *                 "priority": 150,
     *                 "roles": null
     *             },
     *             {
     *                 "id": "changes",
     *                 "enabled": true,
     *                 "target": "/projects/jam/changes/",
     *                 "cssClass": "changes",
     *                 "title": "Changes",
     *                 "priority": 160,
     *                 "roles": null
     *             }
     *         ],
     *         "contextMetadata": {
     *             "contextName": "Jam"
     *         }
     *     }
     * }
     *
     * Example where the project is not found or the user doesn't have permissions
     * GET  /api/v10/menus?project=badname
     * {
     *     "error": 404,
     *     "messages": [
     *         {
     *             "code": 404,
     *             "text": "Cannot fetch entry. Id does not exist."
     *         }
     *     ],
     *     "data": null
     * }
     *
     * @return JsonModel
     * @throws Exception
     */
    public function getList()
    {
        $menu            = null;
        $error           = null;
        $exception       = null;
        $context         = null;
        $contextMetadata = [];
        $services        = $this->services;
        $projectId       = $this->getRequest()->getQuery()->get(IRequest::PROJECT);
        $projectName     = null;

        if ($projectId) {
            $projectDao = $services->get(IModelDAO::PROJECT_DAO);
            $error      = null;
            // Verify that the project exists
            try {
                $project = $projectDao->fetchById($projectId, $services->get(ConnectionFactory::P4), true);
                $context = $project;
                // Return the project name as the context name via metadata
                $contextMetadata[self::CONTEXT_NAME] = $project->getName();
            } catch (RecordNotFoundException $ex) {
                $error = $ex;
            } catch (CommandException $ex) {
                $error = $ex;
            }
            if ($error) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                $error = $this->buildMessage(Response::STATUS_CODE_404, $error->getMessage());
                return $this->error([$error], Response::STATUS_CODE_404);
            }
        }

        $menuDao = $services->get(IModelDAO::MENU_DAO);
        $menu    = $menuDao->fetchAll([IMenuHelper::CONTEXT=> $context]);
        $data    = ['menu' => $menu, self::CONTEXT_METADATA => $contextMetadata];
        return $this->success($data);
    }
}
