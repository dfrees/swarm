<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\IRequest;
use Application\Config\ConfigManager;
use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Comments\Model\CommentDAO;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use Api\Controller\IndexController as ApiController;
use Comments\Controller\CommentApi;
use Comments\Controller\IndexController;
use Application\Controller\IndexControllerFactory;
use Comments\Filter\IComment;
use Comments\Filter\EditComment;
use Comments\Filter\IParameters;
use Comments\Filter\Parameters;
use Comments\Filter\EditParameters;
use Comments\Filter\CreateComment;
use Comments\Filter\MarkAsRead;
use Comments\Filter\IMarkAsRead;

return [
    'router' => [
        'routes' => [
            'comments' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/comments?(/(?P<topic>.*))?',
                    'spec'     => '/comments/%topic%',
                    'defaults' => [
                        'controller' => Comments\Controller\IndexController::class,
                        'action'     => 'index',
                        'topic'      => null
                    ],
                ],
            ],
            'add-comment' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/comment[s]/add[/]',
                    'defaults' => [
                        'controller' => Comments\Controller\IndexController::class,
                        'action'     => 'add'
                    ],
                ],
            ],
            'edit-comment' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/comment[s]/edit/:comment[/]',
                    'defaults' => [
                        'controller' => Comments\Controller\IndexController::class,
                        'action'     => 'edit'
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
                    'comments' => [
                        'type' => Segment::class,
                        'may_terminate' => false,
                        'options' => [
                            'route' => '/:version/comments[/]',
                            'constraints' => [IRequest::VERSION => 'v1[0-1]'],
                            'defaults' => [
                                'controller' => Comments\Controller\CommentApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'comment-id' => [
                                'type' => Segment::class,
                                'may_terminate' => true,
                                'options' => [
                                    'route' => ':id[/]',
                                    'verb' => Request::METHOD_GET,
                                ],
                                'child_routes' => [
                                    'comment-edit' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => 'edit[/]',
                                            'defaults' => [
                                                'action' => 'edit'
                                            ],
                                        ],
                                        'child_routes' => [
                                            'comment-edit-post' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                ],
                                            ],
                                        ],
                                    ],
                                    'comment-create' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_POST,
                                        ],
                                    ],
                                    'comment-archive' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => ':operation[/]',
                                            'constraints' => [
                                                'operation' => 'archive|unarchive'
                                            ],
                                            'defaults' => [
                                                'action' => 'archiveOrUnArchive'
                                            ],
                                        ],
                                        'child_routes' => [
                                            'comment-archive-post' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                ],
                                            ],
                                        ],
                                    ],
                                    'comment-read-unread' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => ':readByEvent[/]',
                                            'constraints' => [
                                                'readByEvent' => 'read|unread'
                                            ],
                                            'defaults' => [
                                                'action' => 'markCommentAsReadOrUnread'
                                            ],
                                        ],
                                        'child_routes' => [
                                            'comment-read-unread-post' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'comment-topic' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => ':topic/:id[/]',
                                    'constraints' => [
                                        'topic' => 'reviews|changes|jobs'
                                    ],
                                    'defaults' => [
                                        'action' => 'getCommentsByTopicId'
                                    ],
                                ],
                                'child_routes' => [
                                    'comment-topic-get' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET
                                        ]
                                    ]
                                ]
                            ],
                            'comment-topic-create' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => ':topic/:topic_id[/]',
                                    'constraints' => [
                                        'topic' => 'reviews|changes|jobs'
                                    ],
                                ],
                                'child_routes' => [
                                    'comment-topic-post' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_POST
                                        ]
                                    ]
                                ]
                            ]
                        ],
                    ],
                    'topic' => [
                        'type' => Segment::class,
                        'may_terminate' => false,
                        'options' => [
                            'route' => '/:version/:topic/:topic_id/',
                            'constraints' => [
                                IRequest::VERSION => 'v1[0-1]',
                                'topic' => 'reviews'
                            ],
                            'defaults' => [
                                'controller' => Comments\Controller\CommentApi::class,
                            ],
                        ],
                        'child_routes' => [
                            'topic-comments' => [
                                'type' => Segment::class,
                                'may_terminate' => false,
                                'options' => [
                                    'route' => 'comments[/]',
                                ],
                                'child_routes' => [
                                    'comments' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_POST,
                                        ],
                                    ],
                                    'comment-id' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => ':id[/]',
                                            'verb' => Request::METHOD_POST,
                                        ],
                                    ],
                                ],
                            ],
                            'topic-notify' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => 'notify[/]',
                                ],
                                'child_routes' => [
                                    'topic-notify-post' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_POST,
                                            'defaults' => [
                                                'action' => 'sendNotification'
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
            CommentApi::class      => IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            Application\View\Helper\ViewHelperFactory::COMMENTS => Application\View\Helper\ViewHelperFactory::class,
        ],
    ],
    'mentions' => [
        'mode'            => 'global',     // one of 'disabled' 'global' 'projects'.
        // If disabled - no @mentioning will be enabled,
        // 'global' - enables @mention dropdown in all comment sections
        // 'projects' - enables dropdown in comment sections in a project
        // that are part of a project
        ConfigManager::USERS_EXCLUDE_LIST  => [],      // array of users that should never end up
        // on the user @mention dropdown
        ConfigManager::GROUPS_EXCLUDE_LIST => [],      // array of groups that should never end up
        // on the user @mention dropdown.
        // supports full regex, with the exception of an alphanumeric term, which
        // will be treated as an exact match. 'jim' will match 'jim' exactly but
        // '^jim' will match all strings starting with 'jim' and 'jim$' will match
        // all strings ending in 'jim'.
    ],
    'comments' => [
        'threading' => [
            'max_depth' => 4
        ],
        'show_id'    => false,
        'notification_delay_time' => 1800,               // Default to 30 minutes 1800 seconds.
    ],
    'service_manager' => [
        'aliases' => [
            IModelDAO::COMMENT_DAO  => CommentDAO::class,
            IComment::COMMENTS_EDIT_FILTER => EditComment::class,
            IParameters::COMMENTS_PARAMETERS_FILTER => Parameters::class,
            IParameters::EDIT_COMMENTS_PARAMETERS_FILTER => EditParameters::class,
            IComment::COMMENTS_CREATE_FILTER => CreateComment::class,
            IMarkAsRead::MARK_AS_READ_UPDATE_FILTER => MarkAsRead::class
        ],
        'factories' => array_fill_keys(
            [
                CommentDAO::class,
                EditComment::class,
                Parameters::class,
                EditParameters::class,
                CreateComment::class,
                MarkAsRead::class
            ],
            InvokableServiceFactory::class
        )
    ],
];
