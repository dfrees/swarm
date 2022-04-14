<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\Controller\IndexController;
use Api\IRequest;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use TestIntegration\Controller\IndexController as TestDefinitionController;
use TestIntegration\Controller\TestDefinitionsApi;
use TestIntegration\Controller\TestRunApi;
use TestIntegration\Filter\ITestDefinition;
use TestIntegration\Filter\ITestRun;
use TestIntegration\Filter\TestDefinition as TestDefinitionFilter;
use TestIntegration\Filter\TestRun as TestRunFilter;
use TestIntegration\Model\TestDefinitionDAO;
use TestIntegration\Model\TestRunDAO;
use TestIntegration\Service\ITestExecutor;
use TestIntegration\Service\TestExecutor;
use TestIntegration\Model\ITestDefinition as IModel;
use Application\Checker;
use TestIntegration\TestDefinitionChecker;

return [
    IConfigDefinition::GLOBAL_TESTS => [],
    'service_manager' => [
        'aliases' => [
            IDao::TEST_DEFINITION_DAO => TestDefinitionDAO::class,
            IDao::TEST_RUN_DAO        => TestRunDAO::class,
            ITestExecutor::NAME       => TestExecutor::class,
            ITestDefinition::NAME     => TestDefinitionFilter::class,
            ITestRun::NAME            => TestRunFilter::class
        ],
        'factories' => array_fill_keys(
            [
                TestDefinitionDAO::class,
                TestRunDAO::class,
                TestExecutor::class,
                TestDefinitionFilter::class,
                TestRunFilter::class,
                TestDefinitionChecker::class
            ],
            InvokableServiceFactory::class
        ),
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                TestRunApi::class,
                TestDefinitionsApi::class,
                TestDefinitionController::class
            ],
            IndexControllerFactory::class
        )
    ],
    'router' => [
        'routes' => [
            'test-definitions' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/testdefinitions[/]',
                    'defaults' => [
                        'controller' => TestDefinitionController::class,
                    ]
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'rest_list_add' => [
                        'type' => Method::class,
                        'options' => [
                            'verb' => 'get'
                        ],
                    ],
                ],
            ],
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => IndexController::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'testruns-list' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/testruns[/]',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => TestRunApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'testruns-all' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => 'get',
                                ],
                            ]
                        ]
                    ],
                    'review-testruns' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/reviews/:reviewId/testruns[/]',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => TestRunApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'testruns-get-by-review' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => 'get',
                                    'defaults' => [
                                        'action' => 'getByReview'
                                    ],
                                ],
                            ],
                            'testruns-post' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => 'post',
                                ],
                            ],
                            'testruns-test' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => ':id[/]',
                                ],
                                'child_routes' => [
                                    'create' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'action'     => 'createForTest'
                                            ],
                                        ],
                                    ],
                                    'edit' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => 'put, patch',
                                        ],
                                    ],
                                    'testruns-run' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => 'run[/]',
                                        ],
                                        'child_routes' => [
                                            'action' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => 'post',
                                                    'defaults' => [
                                                        'action'     => 'run'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'testruns-api' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/testruns/:id[/]',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => TestRunApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'testruns-uuid' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => ':uuid[/]',
                                ],
                                'child_routes' => [
                                    'action' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'action'     => 'updateWithUuid'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'testruns-pass' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => 'pass/:uuid[/]',
                                ],
                                'child_routes' => [
                                    'action' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => 'get, post',
                                            'defaults' => [
                                                'action'     => 'pass'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'testruns-fail' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => 'fail/:uuid[/]',
                                ],
                                'child_routes' => [
                                    'action' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => 'get, post',
                                            'defaults' => [
                                                'action'     => 'fail'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'testdefinitions-api' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/testdefinitions',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => TestDefinitionsApi::class
                            ],
                        ],
                        'child_routes' => [
                            'testdefinitions' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => implode(
                                        ',',
                                        [Request::METHOD_POST, Request::METHOD_GET]
                                    ),
                                ]
                            ],
                            'testdefinitions-id' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id',
                                ],
                                'child_routes' => [
                                    'testdefinitions-id-child' => [
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

                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_map' => [],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'menu_helpers' => ['testIntegration' => ['target'=>'/testdefinitions/','priority'=>200]],
    // These are the UUID based endpoints that do not require authentication
    'security' => [
        'login_exempt'  => [TestRunApi::UPDATE_NOAUTH_URL_ROUTE, TestRunApi::PASS_URL_ROUTE, TestRunApi::FAIL_URL_ROUTE]
    ],
    IConfigDefinition::TEST_DEFINITIONS => [
        IConfigDefinition::PROJECT_AND_BRANCH_SEPARATOR => ':',
    ],
    /**
     * Map a check name of IModel::TESTDEFINITION to be handled by TestDefinitionChecker
     * @see ConfigCheck
     */
    Checker::CHECKERS => [
        IModel::TESTDEFINITION => TestDefinitionChecker::class
    ]
];
