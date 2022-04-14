<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\Controller\IndexController as APIController;
use Api\IRequest;
use Application\Config\Services;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Application\View\Helper\ViewHelperFactory;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use Redis\Model\UserDAO;
use Users\Authentication\Service;
use Users\Controller\IndexController;
use Users\Controller\SessionApi;
use Users\Controller\UserApi;
use Users\Filter\GetUsers;
use Users\Settings\ReviewPreferences;
use Users\Settings\TimePreferences;
use Users\Model\Factory;
use Users\Authentication\Helper;

return [
    'users' => [
        'maximum_dashboard_actions'  => 1000,
        'dashboard_refresh_interval' => 300000,
        'settings' => [
            ReviewPreferences::REVIEW_PREFERENCES => [
                ReviewPreferences::SHOW_COMMENTS_IN_FILES  => true,
                ReviewPreferences::VIEW_DIFFS_SIDE_BY_SIDE => true,
                ReviewPreferences::SHOW_SPACE_AND_NEW_LINE => false,
                ReviewPreferences::IGNORE_WHITESPACE       => false,
            ],
            TimePreferences::TIME_PREFERENCES => [
                TimePreferences::DISPLAY  => 'Timeago', // Default to 'Timeago' but can be set to 'Timestamp'
            ]
        ],
        'display_fullname' => true,
    ],
    'avatars' => [
        'http_url'  => 'http://www.gravatar.com/avatar/{hash}?s={size}&d={default}',
        'https_url' => 'https://secure.gravatar.com/avatar/{hash}?s={size}&d={default}'
    ],
    'security' => [
        'login_exempt' => [
            'login', // specify route id's which bypass require_login setting
            'api/sessionApi/session-transitions',
        ],
        'mfa_routes'   => [          // specify routes which are parth of the authentication workflow
            'login',
            'api/login/listmethods/listmethods',
            'api/login/initauth/initauth',
            'api/login/checkauth/checkauth',
            'api/login/checkauth/checkauthpoll',
            'logout'                        // added logout to routes as you can be P4 basic logged in but can't logout.

        ],
        'prevent_login' => [],         // specify user ids which are not permitted to login to swarm
    ],
    'service_manager' => [
        'aliases' => [
            Service::AUTH         => Service::class,
            IModelDAO::USER_DAO   => UserDAO::class,
            Services::AUTH_HELPER => Helper::class,
            Services::GET_USERS_FILTER => GetUsers::class,
        ],
        'factories' => [
            Service::class   => InvokableServiceFactory::class,
            'user'           => Factory::class,
            UserDAO::class   => InvokableServiceFactory::class,
            Helper::class    => InvokableServiceFactory::class,
            GetUsers::class  => InvokableServiceFactory::class
        ],
    ],
    'router' => [
        'routes' => [
            'home' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'login' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/login[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'login',
                    ],
                ],
            ],
            'logout' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/logout[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'logout',
                    ],
                ],
            ],
            'user' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/user(s?)/(?P<user>.*[^/])(/?)',
                    'spec'     => '/users/%user%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'user'
                    ],
                ],
            ],
            'follow' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/follow/:type/:id[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'follow',
                        'type'       => null,
                        'id'         => null
                    ],
                ],
            ],
            'unfollow' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/unfollow/:type/:id[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'unfollow',
                        'type'       => null,
                        'id'         => null
                    ],
                ],
            ],
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => APIController::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'sessionApi' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/session[/]',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                        ],
                        'child_routes' => [
                            'session-transitions' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'delete, get, post',
                                    'defaults' => [
                                        'controller' => SessionApi::class,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'userApi' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/users',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => UserApi::class
                            ],
                        ],
                        'child_routes' => [
                            'get-all-users' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ]
                            ],
                            'user-by-id' => [
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
            SessionApi::class      => IndexControllerFactory::class,
            UserApi::class         => IndexControllerFactory::class
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'users/index/index'  => __DIR__ . '/../view/users/index/index.phtml',
            'users/index/user'   => __DIR__ . '/../view/users/index/user.phtml',
        ],
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => array_fill_keys(
            [
                ViewHelperFactory::USER,
                ViewHelperFactory::USER_LINK,
                ViewHelperFactory::AVATAR,
                ViewHelperFactory::AVATARS,
                ViewHelperFactory::NOTIFICATION_SETTINGS,
                ViewHelperFactory::USER_SETTINGS
            ],
            ViewHelperFactory::class
        )
    ],
];
