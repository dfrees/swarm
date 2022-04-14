<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Workflow\Model\IWorkflow;
use Workflow\Controller\IndexController;
use Application\Controller\IndexControllerFactory;
use Redis\Model\WorkflowDAO;
use Workflow\Manager;
use Application\Config\Services;
use Application\Checker;
use Workflow\WorkflowChecker;
use Application\Permissions\ConfigCheck;
use Laminas\Router\Http\Method;
use Laminas\Http\Request;
use Laminas\Router\Http\Segment;
use Workflow\Controller\WorkflowApi;
use Api\IRequest;
use Workflow\Filter\Workflow as WorkflowFilter;
use Workflow\Filter\GlobalWorkflow as GlobalWorkflowFilter;

return [
    IWorkflow::WORKFLOW => [
        IWorkflow::WORKFLOW_ENABLED => true // Can workflows be used? Default to true
    ],
    IWorkflow::WORKFLOW_RULES => [
        IWorkflow::ON_SUBMIT => [
            IWorkflow::WITH_REVIEW => [
                IWorkflow::RULE => IWorkflow::NO_CHECKING,
                IWorkflow::MODE => IWorkflow::MODE_DEFAULT
            ],
            IWorkflow::WITHOUT_REVIEW => [
                IWorkflow::RULE => IWorkflow::NO_CHECKING,
                IWorkflow::MODE => IWorkflow::MODE_DEFAULT
            ],
        ],
        IWorkflow::END_RULES => [
            IWorkflow::UPDATE => [
                IWorkflow::RULE => IWorkflow::NO_CHECKING,
                IWorkflow::MODE => IWorkflow::MODE_DEFAULT
            ]
        ],
        IWorkflow::AUTO_APPROVE => [
            IWorkflow::RULE => IWorkflow::NEVER,
            IWorkflow::MODE => IWorkflow::MODE_DEFAULT
        ],
        IWorkflow::COUNTED_VOTES => [
            IWorkflow::RULE => IWorkflow::ANYONE,
            IWorkflow::MODE => IWorkflow::MODE_DEFAULT
        ],
        IWorkflow::GROUP_EXCLUSIONS => [
            IWorkflow::RULE => [],
            IWorkflow::MODE => IWorkflow::MODE_POLICY
        ],
        IWorkflow::USER_EXCLUSIONS => [
            IWorkflow::RULE => [],
            IWorkflow::MODE => IWorkflow::MODE_POLICY
        ]
    ],
    'router' => [
        'routes' => [
            'workflows' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route' => '/workflows[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                    ]
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'rest_list_add' => [
                        'type' => 'Laminas\Router\Http\Method',
                        'options' => [
                            'verb' => 'get'
                        ],
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
                    'workflows-api' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/workflows',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => WorkflowApi::class
                            ],
                        ],
                        'child_routes' => [
                            'workflows' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => implode(
                                        ',',
                                        [Request::METHOD_POST, Request::METHOD_GET]
                                    ),
                                ]
                            ],
                            'workflows-id' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id',
                                ],
                                'child_routes' => [
                                    'workflows-id-child' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => implode(
                                                ',',
                                                [Request::METHOD_PUT, Request::METHOD_GET, Request::METHOD_DELETE]
                                            ),
                                        ]
                                    ],
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ],
    ],
    'service_manager' => [
        'aliases' => [
            IModelDAO::WORKFLOW_DAO          => WorkflowDAO::class,
            Services::WORKFLOW_MANAGER       => Manager::class,
            Services::WORKFLOW_FILTER        => WorkflowFilter::class,
            Services::GLOBAL_WORKFLOW_FILTER => GlobalWorkflowFilter::class
        ],
        'factories' => array_fill_keys(
            [
                WorkflowDAO::class,
                Manager::class,
                WorkflowChecker::class,
                WorkflowFilter::class,
                GlobalWorkflowFilter::class
            ],
            InvokableServiceFactory::class
        )
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                IndexController::class,
                WorkflowApi::class
            ],
            IndexControllerFactory::class
        )
    ],
    'view_manager' => [
        'template_map' => [],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'menu_helpers' => ['workflow' => ['priority'=>190]],

    /**
     * Map a check name of IWorkflow::WORKFLOW to be handled by WorkflowChecker
     * @see ConfigCheck
     */
    Checker::CHECKERS => [
        IWorkflow::WORKFLOW => WorkflowChecker::class
    ]
];
