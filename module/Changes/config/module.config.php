<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Factory\InvokableServiceFactory;
use Changes\Filter\GetFiles as GetFilesFilter;
use Changes\Filter\GetChanges as GetChangesFilter;
use Changes\Filter\IChange;
use Changes\Service\Change;
use Application\Config\Services;
use Application\Model\IModelDAO;
use Changes\Model\ChangeDAO;
use Changes\Service\ChangeComparator;
use Changes\Controller\ChangeApi;
use Api\IRequest;
use Application\Controller\IndexControllerFactory;
use Changes\Controller\IndexController;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;

return [
    'router' => [
        'routes' => [
            'changes' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/changes?(/(?P<path>.*))?',
                    'spec'     => '/changes/%path%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'changes',
                        'path'       => null
                    ],
                ],
            ],
            'change' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/changes?(/(?P<change>[0-9]+))/?',
                    'spec'     => '/changes/%change%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'change',
                        'change'     => null
                    ],
                ],
            ],
            'change-fixes' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/changes?/(?P<change>[^/]+)/fixes(/(?P<mode>(add|delete)))?/?',
                    'spec'     => '/changes/%change%/fixes/%mode%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'fixes',
                        'change'     => null,
                        'mode'       => null
                    ],
                ],
            ],
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => '/api',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'changes'=> [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/changes',
                            'constraints' => [IRequest::VERSION => 'v1[01]'],
                            'defaults' => [
                                'controller' => ChangeApi::class
                            ],
                        ],
                        'child_routes' => [
                            'list' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ],
                            ],
                            'change-id' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id'
                                ],
                                'child_routes' => [
                                    'change' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                        ],
                                    ],
                                    'jobs' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/jobs'
                                        ],
                                        'child_routes' => [
                                            'jobs' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'jobs'
                                                    ],
                                                ],
                                            ],
                                            'add-remove-job' => [
                                                'type' => Segment::class,
                                                'options' => [
                                                    'route' => '/:jobid'
                                                ],
                                                'child_routes' => [
                                                    'add-job' => [
                                                        'type' => Method::class,
                                                        'options' => [
                                                            'verb' => Request::METHOD_PUT,
                                                            'defaults' => [
                                                                'action' => 'addJob'
                                                            ],
                                                        ],
                                                    ],
                                                    'remove-job' => [
                                                        'type' => Method::class,
                                                        'options' => [
                                                            'verb' => Request::METHOD_DELETE,
                                                            'defaults' => [
                                                                'action' => 'removeJob'
                                                            ],
                                                        ],
                                                    ]
                                                ]
                                            ]
                                        ],
                                    ],
                                    'files-by-change-range' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/files'
                                        ],
                                        'child_routes' => [
                                            'jobs' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'files'
                                                    ],
                                                ],
                                            ],
                                        ],
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
            ChangeApi::class       => IndexControllerFactory::class
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'changes/index/index'  => __DIR__ . '/../view/changes/index/change.phtml',
        ],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'service_manager' => [
        'aliases' => [
            Services::CHANGE_SERVICE    => Change::class,
            Services::CHANGE_COMPARATOR => ChangeComparator::class,
            IModelDAO::CHANGE_DAO       => ChangeDAO::class,
            IChange::GET_FILES_FILTER   => GetFilesFilter::class,
            IChange::GET_CHANGES_FILTER => GetChangesFilter::class
        ],
        'factories' => array_fill_keys(
            [
                Change::class,
                ChangeDAO::class,
                ChangeComparator::class,
                GetFilesFilter::class,
                GetChangesFilter::class,
            ],
            InvokableServiceFactory::class
        ),
    ],
    'menu_helpers' => [
        'changes' => [
            'class'    => '\Projects\Menu\Helper\ProjectAwareMenuHelper',
            'priority' => 160
        ]
    ]
];
