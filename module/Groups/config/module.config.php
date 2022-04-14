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
use Application\Config\Services;
use Application\Model\IModelDAO;
use Groups\Filter\GetGroups;
use Redis\Model\GroupDAO;
use Groups\Controller\IndexController;
use Application\View\Helper\ViewHelperFactory;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Groups\Controller\GroupApi;
use Groups\Filter\Group as GroupFilter;
use Laminas\Router\Http\Method;
use Laminas\Http\Request;
use Laminas\Router\Http\Segment;

return [
    'groups' => [
        'edit_name_admin_only' => false,    // if enabled only admin users can edit group name
        'super_only'           => false,    // if enabled only super users can view groups pages
    ],
    'router' => [
        'routes' => [
            'group' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/group(s?)/(?P<group>.*[^/])(/?)',
                    'spec'     => '/groups/%group%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'group'
                    ],
                ],
            ],
            'add-group' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/group[s]/add[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'add',
                    ],
                ],
            ],
            'edit-group' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/group(s?)/(?P<group>.*[^/])(/?)/settings(/?)',
                    'spec'     => '/groups/%group%/settings/',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'edit'
                    ],
                ],
            ],
            'edit-notifications' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/group(s?)/(?P<group>.*[^/])(/?)/notifications(/?)',
                    'spec'     => '/groups/%group%/notifications/',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'notifications'
                    ],
                ],
            ],
            'delete-group' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/group(s?)/delete/(?P<group>.*[^/])(/?)',
                    'spec'     => '/groups/delete/%group%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'delete'
                    ],
                ],
            ],
            'group-reviews' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/group(s?)/(?P<group>.*[^/])(/?)/reviews(/?)',
                    'spec'     => '/groups/%group%/reviews/',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'reviews'
                    ],
                ],
            ],
            'groups' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/groups[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'groups'
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
                    // Note this key cannot be 'groups' else it will clash with the v9 definition in the Api module
                    'groups-api' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/groups[/]',
                            'constraints' => [IRequest::VERSION => 'v1[0-1]'],
                            'defaults' => [
                                'controller' => GroupApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'get-all-groups' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ]
                            ],
                        ],
                    ],
                ],
            ]
        ],
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                IndexController::class,
                GroupApi::class
            ],
            IndexControllerFactory::class
        ),
    ],
    'service_manager' => [
        'aliases' => [
            IModelDAO::GROUP_DAO        => GroupDAO::class,
            Services::GROUP_FILTER      => GroupFilter::class,
            Services::GET_GROUPS_FILTER => GetGroups::class
        ],
        'factories' => array_fill_keys(
            [
                GroupDAO::class,
                GroupFilter::class,
                GetGroups::class
            ],
            InvokableServiceFactory::class
        ),
    ],
    'view_manager' => [
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => array_fill_keys(
            [
                ViewHelperFactory::GROUP_TOOLBAR,
                ViewHelperFactory::GROUP_SIDEBAR,
                ViewHelperFactory::GROUP_AVATAR,
                ViewHelperFactory::GROUP_AVATARS,
                ViewHelperFactory::GROUP_NOT_SETTINGS
            ],
            ViewHelperFactory::class
        )
    ],
    'menu_helpers' => ['groups' => ['priority'=>180]]
];
