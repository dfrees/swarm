<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Activity\Controller\IndexController;
use Application\Controller\IndexControllerFactory;
use Application\View\Helper\ViewHelperFactory;
use Laminas\Router\Http\Segment;
use Laminas\Router\Http\Method;
use Projects\Menu\Helper\ProjectAwareMenuHelper;
use Activity\Controller\ActivityApi;
use Api\Controller\IndexController as ApiController;
use Api\IRequest;
use Laminas\Http\Request;
use Activity\Filter\IParameters;
use Activity\Filter\Parameters;
use Activity\Filter\StreamParameters;
use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Activity\Model\ActivityDAO;

return [
    'activity' => [
        'ignored_users' => ['git-fusion-user']
    ],
    'router' => [
        'routes' => [
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => ApiController::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'activity_base' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/activity[/]',
                            'constraints' => [IRequest::VERSION => 'v11'],
                            'defaults' => [
                                'controller' => ActivityApi::class
                            ]
                        ],
                        'child_routes' => [
                            'all_activity' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ],
                            ],
                            'activity_by_type' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => ':type[/]',
                                ],
                                'child_routes' => [
                                    'get_by_type' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                            'defaults' => [
                                                'action' => 'getByType'
                                            ]
                                        ],
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'activity_streams' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/:stream/:streamId/activity[/]',
                            'constraints' => [
                                IRequest::VERSION => 'v11',
                                'stream' => 'reviews'
                            ],
                            'defaults' => [
                                'controller' => ActivityApi::class
                            ]
                        ],
                        'child_routes' => [
                            'get_by_stream' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                    'defaults' => [
                                        'action' => 'getByStream'
                                    ]
                                ],
                            ]
                        ]
                    ]
                ]
            ],
            'activity' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/activity[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'activity-rss' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/activity/rss[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'activityData',
                        'rss'        => true
                    ],
                ],
            ],
            'activity-stream' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/activity/streams[/:stream][/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'activityData'
                    ],
                ],
            ],
            'activity-stream-rss' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/activity/streams/[:stream/]rss[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'activityData',
                        'rss'        => true
                    ],
                ],
            ]
        ],
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                IndexController::class,
                ActivityApi::class
            ],
            IndexControllerFactory::class
        ),
    ],
    'view_helpers' => [
        'factories' => [
            ViewHelperFactory::ACTIVITY => ViewHelperFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'menu_helpers' => [
        'activity' => [
            'class'    => ProjectAwareMenuHelper::class,
            'priority' => 120
        ]
    ],
    'service_manager' => [
        'aliases' => [
            IModelDAO::ACTIVITY_DAO  => ActivityDAO::class,
            IParameters::ACTIVITY_PARAMETERS_FILTER => Parameters::class,
            IParameters::ACTIVITY_STREAM_PARAMETERS_FILTER => StreamParameters::class,
        ],
        'factories' => array_fill_keys(
            [
                Parameters::class,
                ActivityDAO::class,
                StreamParameters::class,
            ],
            InvokableServiceFactory::class
        )
    ],
];
