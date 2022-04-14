<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\Controller\CacheController;
use Api\Controller\IndexController;
use Api\Controller\LoginController;
use Api\Controller\ActivityController;
use Api\Controller\CommentsController;
use Api\Controller\ChangesController;
use Api\Controller\GroupsController;
use Api\Controller\ProjectsController;
use Api\Controller\ReviewsController;
use Api\Controller\WorkflowsController;
use Api\Controller\UsersController;
use Api\Controller\ServersController;
use Api\IRequest;
use Application\Controller\IndexControllerFactory;
use Laminas\Http\Request;

return [
    'router' => [
        'routes' => [
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => IndexController::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'version' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/[:version/]version[/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9]|1(\.[1-2])?|1[01])'],
                            'defaults' => [
                                'controller' => IndexController::class,
                                'action'     => 'version'
                            ],
                        ],
                    ],
                    'logout' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/logout[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'user' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'post',
                                    'defaults' => [
                                        'controller' => LoginController::class,
                                        'action'     => 'logout'
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'login' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/login[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'user' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'post',
                                    'defaults' => [
                                        'controller' => LoginController::class,
                                        'action'     => 'login'
                                    ],
                                ],
                            ],
                            'samlResponse' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'saml/response[/]',
                                    'constraints' => [IRequest::VERSION => 'v9'],
                                ],
                                'child_routes' => [
                                    'samlResponse' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'samlResponse'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'samlLogin' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'saml[/]',
                                    'constraints' => [IRequest::VERSION => 'v9'],
                                ],
                                'child_routes' => [
                                    'samlLogin' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'samlLogin'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'listmethods' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'listmethods[/]',
                                    'constraints' => [IRequest::VERSION => 'v9'],
                                ],
                                'child_routes' => [
                                    'listmethods' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'get',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'listMethods'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'initauth' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'initauth[/]',
                                    'constraints' => [IRequest::VERSION => 'v9'],
                                ],
                                'child_routes' => [
                                    'initauth' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'initAuth'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'checkauth' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'checkauth[/]',
                                    'constraints' => [IRequest::VERSION => 'v9'],
                                ],
                                'child_routes' => [
                                    'checkauthpoll' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'get',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'checkAuthPoll'
                                            ],
                                        ],
                                    ],
                                    'checkauth' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'controller' => LoginController::class,
                                                'action'     => 'checkAuth'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'activity' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => [
                            'route' => '/:version/activity[/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9]|1(\.[1-2])?)'],
                            'defaults' => [
                                'controller' => ActivityController::class,
                            ],
                        ],
                    ],
                    'comments-legacy' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => [
                            'route' => '/:version/comments[/]',
                            'constraints' => [IRequest::VERSION => 'v([3-9])'],
                            'defaults' => [
                                'controller' => CommentsController::class,
                            ],
                        ],
                        'child_routes' => [
                            'comment' => [
                                'type' => 'Application\Router\Regex',
                                'options' => [
                                    'regex'    => '/?(?P<id>[0-9\(\)]+)[/]?',
                                    'spec'     => '/comments/%id%',
                                ],
                            ],
                        ],
                    ],
                    'commentsV9' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'may_terminate' => false,
                        'options' => [
                            'route' => '/:version/comments[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => CommentsController::class,
                            ],
                        ],
                        'child_routes' => [
                            'markallread' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'may_terminate' => false,
                                'options' => [
                                    'route'    => '[/]read[/]',
                                ],
                                'child_routes' => [
                                    'markallread-post' => [
                                        'may_terminate' => true,
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'action'     => 'markAllRead',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'markallunread' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route'    => '[/]unread[/]',
                                ],
                                'child_routes' => [
                                    'markallunread-post' => [
                                        'may_terminate' => true,
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'action'     => 'markAllUnread',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'notify' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'may_terminate' => false,
                                'options' => [
                                    'route' => '[/]notify[/]',
                                ],
                                'child_routes' => [
                                    'notify-post' => [
                                        'may_terminate' => true,
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'post',
                                            'defaults' => [
                                                'action'     => 'sendAllMyDelayedComments',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'projects' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'may_terminate' => true,
                        'options' => [
                            'route' => '/:version/projects[/:id][/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9]|1(\.[1-2])?)'],
                            'defaults' => [
                                'controller' => ProjectsController::class,
                            ],
                        ],
                    ],
                    'groups' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/groups[/:id][/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9])'],
                            'defaults' => [
                                'controller' => GroupsController::class,
                            ],
                        ],
                    ],
                    'dashboard' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/dashboards/action',
                            'constraints' => [IRequest::VERSION => 'v([6-9])'],
                        ],
                        'child_routes' => [
                            'review-dashboard' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'get',
                                    'defaults' => [
                                        'controller' => ReviewsController::class,
                                        'action'     => 'dashboard',
                                        'author'     => null
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'reviews-legacy' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews[/:id][/]',
                            'constraints' => [IRequest::VERSION => 'v([1-9]|1(\.[1-2])?)'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                            ],
                        ],
                    ],
                    'reviews/archive' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/archive[/]',
                            'constraints' => [IRequest::VERSION => 'v([6-9])'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                                'action'     => 'archiveInactive',
                            ],
                        ],
                    ],
                    'reviews/changes' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/changes[/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9]|1(\.[1-2])?)'],
                            'defaults' => [
                                'controller'    => ReviewsController::class,
                                'action'        => 'addChange',
                                'addChangeMode' => null
                            ],
                        ],
                    ],
                    'reviews/state' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/state[/]',
                            'constraints' => [IRequest::VERSION => 'v([2-9])'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                                'action'     => 'state',
                            ],
                        ],
                    ],
                    'reviews/cleanup' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/cleanup[/]',
                            'constraints' => [IRequest::VERSION => 'v([6-9])'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                                'action'     => 'cleanup',
                            ],
                        ],
                    ],
                    'reviews/vote' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/vote[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                                'action' => 'vote',
                            ],
                        ],
                        'child_routes' => [
                            'review-vote' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'post',
                                ],
                            ],
                        ],
                    ],
                    'users' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/users[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => UsersController::class,
                            ],
                        ],
                    ],
                    'reviews/obliterate' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/obliterate[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                                'action'     => 'obliterate',
                            ],
                        ],
                    ],
                    'reviews/transitions' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/reviews/:id/transitions[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => ReviewsController::class,
                            ],
                        ],
                        'child_routes' => [
                            'review-transitions' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'GET',
                                    'defaults' => [
                                        'action'     => 'transitions',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'servers' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/servers[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'servers-all' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'get',
                                    'defaults' => [
                                        'controller' => ServersController::class,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'session' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/session[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'session-transitions' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'delete, get, post',
                                    'defaults' => [
                                        'controller' => LoginController::class,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'users/unfollowall' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/users/:user/unfollowall[/]',
                            'constraints' => [IRequest::VERSION => 'v([8-9])'],
                        ],
                        'child_routes' => [
                            'users-unfollowall' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'post',
                                    'defaults' => [
                                        'controller' => UsersController::class,
                                        'action'     => 'unfollowAll',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'change/defaultreviewers' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/changes/:change/defaultreviewers[/]',
                            'constraints' => [IRequest::VERSION => 'v([8-9])'],
                            'defaults' => [
                                'controller' => ChangesController::class,
                                'action'     => 'defaultReviewers'
                            ],
                        ],
                    ],
                    'change/affects' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/changes/:change/affectsprojects[/]',
                            'constraints' => [IRequest::VERSION => 'v([8-9])'],
                            'defaults' => [
                                'controller' => ChangesController::class,
                                'action'     => 'affectsProjects'
                            ],
                        ],
                    ],
                    'change/check' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/changes/:change/check[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                            'defaults' => [
                                'controller' => ChangesController::class,
                                'action' => 'check',
                            ],
                        ],
                        'child_routes' => [
                            'change-check' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'GET',
                                ],
                            ],
                        ],
                    ],
                    'workflows' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/workflows[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'workflows-all' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'get, post',
                                    'defaults' => [
                                        'controller' => WorkflowsController::class,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'workflow' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/workflows/:id[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'workflow-id' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'get, patch, put, delete',
                                    'defaults' => [
                                        'controller' => WorkflowsController::class
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'cache' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/cache/:id[/]',
                            'constraints' => [IRequest::VERSION => 'v9'],
                        ],
                        'child_routes' => [
                            'cache-id' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => Request::METHOD_DELETE,
                                    'defaults' => [
                                        'controller' => CacheController::class
                                    ],
                                ],
                            ],
                            'cacheIntegrity' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => 'verify[/]',
                                ],
                                'child_routes' => [
                                    'integrity-check' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => Request::METHOD_POST,
                                            'defaults' => [
                                                'controller' => CacheController::class,
                                                'action'     => 'integrity',
                                            ],
                                        ],
                                    ],
                                    'integrity-status' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                            'defaults' => [
                                                'controller' => CacheController::class,
                                                'action'     => 'integrityStatus',

                                            ],
                                        ],
                                    ],
                                ]
                            ],
                        ],
                    ],
                    'notfound' => [
                        'type' => 'Laminas\Router\Http\Regex',
                        'priority' => -100,
                        'options' => [
                            'regex' => '/(?P<path>.*)|$',
                            'spec'  => '/%path%',
                            'defaults' => [
                                'controller' => IndexController::class,
                                'action'     => 'notFound',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                ActivityController::class,
                IndexController::class,
                LoginController::class,
                ProjectsController::class,
                ReviewsController::class,
                GroupsController::class,
                CommentsController::class,
                UsersController::class,
                ChangesController::class,
                ServersController::class,
                WorkflowsController::class,
                CacheController::class
            ],
            IndexControllerFactory::class
        )
    ],
    'security' => [
        'login_exempt' => [
            'api/version',
            'api/servers/servers-all',
            'api/login/user',
            'api/users',
            'api/session/session-transitions',
            'api/login/samlLogin/samlLogin',
            'api/samlApi/samlApiLogin'
        ],
        'login_with_cookie' => ['api/change/check/change-check']
    ],
];
