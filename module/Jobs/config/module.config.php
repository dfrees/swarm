<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Jobs\Model\JobDAO;
use Application\Config\IDao;
use Application\Factory\InvokableServiceFactory;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use Api\IRequest;
use Jobs\Controller\JobApi;
use Laminas\Http\Request;
use Application\Controller\IndexControllerFactory;
use Jobs\Controller\IndexController;
use Jobs\Filter\GetJobs;
use Jobs\Filter\IGetJobs;

return [
    'router' => [
        'routes' => [
            'job' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/jobs?(/(?P<job>.*))?',
                    'spec'     => '/jobs/%job%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'job',
                        'job'        => null
                    ],
                ],
            ],
            'jobs' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/jobs?(/(?P<job>.*))?',
                    'spec'     => '/jobs/%job%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'job',
                        'job'        => null
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
                    'jobs' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/jobs',
                            'constraints' => [IRequest::VERSION => 'v1[01]'],
                            'defaults' => [
                                'controller' => JobApi::class
                            ],
                        ],
                        'child_routes' => [
                            'list' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ],
                            ],
                        ]
                    ]
                ]
            ]
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => IndexControllerFactory::class,
            JobApi::class => IndexControllerFactory::class
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'jobs/index/index'  => __DIR__ . '/../view/jobs/index/job.phtml',
        ],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'service_manager' => [
        'aliases' => [
            IDao::JOB_DAO => JobDAO::class,
            IGetJobs::FILTER => GetJobs::class
        ],
        'factories' => [
            JobDAO::class => InvokableServiceFactory::class,
            GetJobs::class => InvokableServiceFactory::class
        ]
    ],
    'menu_helpers' => [
        'jobs' => [
            'class'    => '\Projects\Menu\Helper\ProjectAwareMenuHelper',
            'priority' => 170
        ]
    ]
];
