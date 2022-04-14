<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\Controller\IndexController as ApiController;
use Api\IRequest;
use Application\Config\ConfigManager;
use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use Markdown\Settings as MarkdownSettings;
use Projects\Controller\ProjectApi;
use Projects\Filter\GetProjects;
use Projects\Filter\Project as ProjectFilter;
use Projects\Controller\IndexController;
use Application\View\Helper\ViewHelperFactory;
use Application\Controller\IndexControllerFactory;
use Projects\Helper\Readme;
use Redis\Model\ProjectDAO;
use Application\Config\Services;
use Projects\Helper\FindAffected;

return [
    'projects' => [
        ConfigManager::MAINLINES       => ['main', 'mainline', 'master', 'trunk'],  // common mainline branch ids
        ConfigManager::ADD_ADMIN_ONLY  => null,     // if enabled only admin users can create projects
        ConfigManager::ADD_GROUPS_ONLY => null,     // if set, only members of given groups can create projects
        'edit_name_admin_only'         => false,    // if enabled only admin users can edit project name
        'edit_branches_admin_only'     => false,    // if enabled only admin users can add/edit/remove project branches
        'private_by_default'           => false,    // if enabled then new projects will have 'private' option checked
        ConfigManager::MAX_README_SIZE => null,     // if enabled this will limit readme.md size. Size in Bytes.
        ConfigManager::README_MODE     => MarkdownSettings::ENABLED, // Read me files allow markdown by default, the
                                                                     // parse level is defaulted in
                                                                     // markdown->markdown (safe)
        ConfigManager::FETCH => [ConfigManager::MAXIMUM => 0], // 0 === get all projects, set to > 0 when there are lots
        ConfigManager::ALLOW_VIEW_SETTINGS => false,
        ConfigManager::RUN_TESTS_ON_UNCHANGED_SUBMIT => false,
    ],
    'router' => [
        'routes' => [
            'project' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/:project[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'project'
                    ],
                ],
            ],
            'project-overview' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/projects/:project/overview[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'project'
                    ],
                ],
            ],

            'add-project' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/add[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'add',
                    ],
                ],
            ],
            'legacy-edit-project' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/edit/:project[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'edit'
                    ],
                ],
            ],

            'edit-project' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/projects/:project/settings[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'edit'
                    ],
                ],
            ],
            'delete-project' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/delete/:project[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'delete'
                    ],
                ],
            ],
            'project-activity' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/:project/activity[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'activity'
                    ],
                ],
            ],
            'project-reviews' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/project[s]/:project/reviews[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'reviews'
                    ],
                ],
            ],
            'project-jobs' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/projects?/(?P<project>.+)/jobs?(/(?P<job>.*))?',
                    'spec'     => '/projects/%project%/jobs/%job%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'jobs',
                        'job'        => null
                    ],
                ],
            ],
            'project-browse' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    // We specifically do not want to match on projects/edit/ so that we do not
                    // interfere with the legacy-edit-project endpoint if we happen to have projects
                    // named files|view|download|changes
                    'regex'    => '/projects?/(?P<project>(\b(?!edit)\b\S+))/'
                                . '(?P<mode>(files|view|download|changes))(/(?P<path>.*))?',
                    'spec'     => '/projects/%project%/%mode%/%path%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'browse',
                        'path'       => null
                    ],
                ],
            ],
            'project-archive' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/projects?/(?P<project>[^/]+)/archives?/(?P<path>.+)\.zip',
                    'spec'     => '/projects/%project%/archives/%path%.zip',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'archive'
                    ],
                ],
            ],
            'projects' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/projects[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'projects'
                    ],
                ],
            ],
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => ApiController::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'api-projects' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/projects',
                            'constraints' => [IRequest::VERSION => 'v1[0-1]'],
                            'defaults' => [
                                'controller' => ProjectApi::class
                            ],
                        ],
                        'child_routes' => [
                            'get-all-projects' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ]
                            ],
                            'project-id' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id',
                                ],
                                'child_routes' => [
                                    'project' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                        ]
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => IndexControllerFactory::class,
            ProjectApi::class      => IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'aliases' => [
            IModelDAO::PROJECT_DAO       => ProjectDAO::class,
            Services::AFFECTED_PROJECTS  => FindAffected::class,
            Services::GET_PROJECT_README => Readme::class,
            Services::GET_PROJECTS_FILTER => GetProjects::class,
        ],
        'factories' => [
            ProjectDAO::class    => InvokableServiceFactory::class,
            ProjectFilter::class => InvokableServiceFactory::class,
            FindAffected::class  => InvokableServiceFactory::class,
            Readme::class        => InvokableServiceFactory::class,
            GetProjects::class   => InvokableServiceFactory::class,
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'projects/index/project'    => __DIR__ . '/../view/projects/index/project.phtml',
            'projects/index/add'        => __DIR__ . '/../view/projects/index/add.phtml',
            'projects/index/activity'   => __DIR__ . '/../view/projects/index/activity.phtml'
        ],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => array_fill_keys(
            [
                ViewHelperFactory::PROJECT_LIST,
                ViewHelperFactory::PROJECT_SIDEBAR,
            ],
            ViewHelperFactory::class
        )
    ],
    'input_filters' => [
        'factories'  => [
            ProjectFilter::class => InvokableServiceFactory::class,
        ],
    ],
    'menu_helpers' => [
        'overview' => [
            'class'    => '\Projects\Menu\Helper\ProjectContextMenuHelper',
            'priority' => 100
        ],
        'projects' => ['priority' => 140],
        'settings' => [
            'class' => '\Projects\Menu\Helper\ProjectContextMenuHelper',
            'priority' => 200
        ]
    ]
];
