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
use Application\Config\IConfigDefinition;
use Application\Config\Services;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Application\Model\IModelDAO;
use Application\View\Helper\ViewHelperFactory;
use Laminas\Http\Request;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Segment;
use Reviews\Controller\IndexController;
use Reviews\Controller\ReviewApi;
use Reviews\Filter\AppendReplaceChange;
use Reviews\Filter\FileReadUnRead;
use Reviews\Filter\GetReviews;
use Reviews\Filter\IAppendReplaceChange;
use Reviews\Filter\IParticipants;
use Reviews\Filter\IVersion;
use Reviews\Filter\Keywords;
use Reviews\Filter\Participants;
use Reviews\Filter\ProjectsForUser;
use Reviews\Filter\Version;
use Reviews\Filter\VoteInput;
use Reviews\Model\FileInfoDAO;
use Reviews\Model\ReviewDAO;
use Reviews\Service\IStatistics;
use Reviews\Service\Statistics;
use Reviews\Validator\Transitions;

return [
    IConfigDefinition::REVIEWS => [
        'patterns' => [
            'octothorpe' => [     // #review or #review-1234 with surrounding whitespace/eol
                'regex'  => '/(?P<pre>(?:\s|^)\(?)'
                    . '\#(?P<keyword>review|append|replace)(?:-(?P<id>[0-9]+))?'
                    . '(?P<post>[.,!?:;)]*(?=\s|$))/i',
                'spec'   => '%pre%#%keyword%-%id%%post%',
                'insert' => "%description%\n\n#review-%id%",
                // Ignore line limit here to aid readability
                // @codingStandardsIgnoreStart
                'strip'  => '/^\s*\#(review|append|replace)(-[0-9]+)?(\s+|$)|(\s+|^)\#(review|append|replace)(-[0-9]+)?\s*$/i'
                // @codingStandardsIgnoreEnd
            ],
            'leading-square'  => [ // [review] or [review-1234] at start
                'regex'  => '/^(?P<pre>\s*)\[(?P<keyword>review|append|replace)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            ],
            'trailing-square' => [ // [review] or [review-1234] at end
                'regex'  => '/(?P<pre>\s*)\[(?P<keyword>review|append|replace)(?:-(?P<id>[0-9]+))?\](?P<post>\s*)?$/i',
                'spec'   => '%pre%[%keyword%-%id%]%post%'
            ],
        ],
        'filters' => [
            'fetch-max' => 50,
            'filter-max' => 50,
            'result_sorting' => true,
            'date_field' => 'created', // 'created' displays and sorts by created date, 'updated' displays and sorts
            // by last updated
            // These need to match Review::FETCH_BY...
            'hasVoted' => [
                'fetch-max' => 50,
            ],
            'myComments' => [
                'fetch-max' => 50,
            ],
            'lastUpdated' => [
                'fetch-max' => 50,
            ]
        ],
        'expand_group_reviewers' => false, // whether swarm should expand group members on the review page if they
        // have been added as an individual.
        'cleanup'              => [
            'mode'        => 'user', // auto - follow default, user - present checkbox(with default)
            'default'     => false,  // clean up pending changelists on commit
            'reopenFiles' => false   // re-open any opened files into the default changelist
        ],
        'disable_commit'        => false,
        'disable_self_approve'  => false, // whether authors can approve their own reviews
        'commit_credit_author'  => true,
        'commit_timeout'        => 1800,  // default: 30 minutes (must be in seconds)
        'unapprove_modified'    => true,  // whether approved reviews with modified files can be automatically
        // unapproved
        'ignored_users'         => [],
        'allow_author_change'   => false, // Whether anyone can change the Author
        'sync_descriptions'     => false, // if true a changesaved event will update all reviews attached to said change
        'expand_all_file_limit' => 10,    // Controls if 'Expand all' is available for reviews by specifying a file
        // limit over which the option will not be available. 0 signifies always on.
        'process_shelf_delete_when' => [], // States of the review we will process if a user deletes files from
        // their shelved changelist.
        // Supported states array('needsReview', 'needsRevision', 'archived', 'rejected', 'approved')
        'disable_approve_when_tasks_open' => false, // false shows a warning if tasks are open, true prevents approve
        'version_chooser'                 => 'chooser',
        'more_context_lines'              => 10,
        'max_bottom_context_lines'        => 10000,
        'allow_author_obliterate'         => false, // If true author will be allowed to obliterate their own review.
        'moderator_approval'              => ConfigManager::VALUE_ANY, // for reviews with multiple branches either
        // 'any' moderator may approve or 'each' must
        // approve (at least one from each branch)
        'end_states'                      => [],  // States that are considered closed
        // when checking workflow rules to
        // see if a review can be changed
        IConfigDefinition::REACT_ENABLED      => false,  // Prefer php based reviews
        IConfigDefinition::ALLOW_EDITS        => true, // Allow inline editing of files in a review
        IConfigDefinition::STATISTICS => [
            IConfigDefinition::COMPLEXITY => [
                IConfigDefinition::CALCULATION => IConfigDefinition::DEFAULT, // 'default' to use a Swarm implementation
                // based on files and changes, 'custom' to
                // use a customer implementation
                IConfigDefinition::HIGH => 300, // When using the Swarm implementation reviews with differences >= this
                // value will be considered high complexity
                IConfigDefinition::LOW  => 30   // When using the Swarm implementation reviews with differences <= this
                // value will be considered low complexity. Reviews within the range are
                // medium complexity
            ]
        ],
        // This is the value that controls the initial number of reviewers to display on the secondary navigation panel
        // before a '+x more' is displayed
        IConfigDefinition::MAX_SECONDARY_NAV_ITEMS => 6,
        IConfigDefinition::DEFAULT_UI => IConfigDefinition::CLASSIC,
    ],
    'security' => [
        'login_exempt'  => ['review-tests', 'review-deploy']
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
                    'reviews-v11-transitions-POST' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/reviews/:id/transitions',
                            'constraints' => [IRequest::VERSION => 'v11'],
                            'defaults' => [
                                'controller' => ReviewApi::class
                            ],
                        ],
                        'child_routes' => [
                            'transition' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_POST,
                                    'defaults' => [
                                        'action' => 'transition'
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'reviews' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/reviews',
                            'constraints' => [IRequest::VERSION => 'v1[01]'],
                            'defaults' => [
                                'controller' => ReviewApi::class
                            ],
                        ],
                        'child_routes' => [
                            'get-all-reviews' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                ]
                            ],
                            'review-id' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id',
                                ],
                                'child_routes' => [
                                    'review' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                        ]
                                    ],
                                    'vote' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/vote'
                                        ],
                                        'child_routes' => [
                                            'vote-up-down' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                    'action' => 'vote'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'review-author' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/author'
                                        ],
                                        'child_routes' => [
                                            'review-author-update' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_PUT,
                                                    'defaults' => [
                                                        'action' => 'author'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'delete-review' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_DELETE,
                                            'defaults' => [
                                                'controller' => ReviewApi::class
                                            ],
                                        ],
                                    ],
                                    'transitions' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/transitions',
                                        ],
                                        'child_routes' => [
                                            'transitions' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'transitions'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'transition' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/transition'
                                        ],
                                        'child_routes' => [
                                            'transition' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                        'action' => 'transition'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'review-description' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/description'
                                        ],
                                        'child_routes' => [
                                            'update' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_PUT,
                                                    'defaults' => [
                                                        'action' => 'description'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'participants' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/participants',
                                        ],
                                        'child_routes' => [
                                        'add' => [
                                            'type' => Method::class,
                                            'options' => [
                                                'verb' => Request::METHOD_POST,
                                                'defaults' => [
                                                    'action' => 'addParticipants'
                                                ],
                                            ],
                                        ],
                                        'update' => [
                                            'type' => Method::class,
                                            'options' => [
                                                'verb' => Request::METHOD_PUT,
                                                'defaults' => [
                                                    'action' => 'updateParticipants'
                                                ],
                                            ],
                                        ],
                                        'delete' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_DELETE,
                                                    'defaults' => [
                                                        'action' => 'deleteParticipants'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'join' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/join',
                                        ],
                                        'child_routes' => [
                                            'add' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                        'action' => 'join'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'leave' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/leave',
                                        ],
                                        'child_routes' => [
                                            'add' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_DELETE,
                                                    'defaults' => [
                                                        'action' => 'leave'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'archive' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/archive'
                                        ],
                                        'child_routes' => [
                                            'transitions' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'archive'
                                                    ],
                                                ],
                                            ]
                                        ],
                                    ],
                                    'files' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/files'
                                        ],
                                        'child_routes' => [
                                            'fileChanges' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'fileChanges'
                                                    ],
                                                ],
                                            ],
                                            'readBy' => [
                                                'type' => Segment::class,
                                                'options' => [
                                                    'route' => '/readby'
                                                ],
                                                'child_routes' => [
                                                    'filesReadBy' => [
                                                        'type' => Method::class,
                                                        'options' => [
                                                            'verb' => Request::METHOD_GET,
                                                            'defaults' => [
                                                                'action' => 'getFilesReadBy'
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                            'file-read-unread' => [
                                                'type' => Segment::class,
                                                'options' => [
                                                    'route' => '/:operation',
                                                    'constraints' => [
                                                        'operation' => 'read|unread'
                                                    ],
                                                    'defaults' => [
                                                        'action' => 'markFileAsReadOrUnread'
                                                    ],
                                                ],
                                                'child_routes' => [
                                                    'file-operation-post' => [
                                                        'type' => Method::class,
                                                        'options' => [
                                                            'verb' => Request::METHOD_POST,
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'comments' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/comments'
                                        ],
                                        'child_routes' => [
                                            'transitions' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_GET,
                                                    'defaults' => [
                                                        'action' => 'getComments'
                                                    ],
                                                ],
                                            ]
                                        ],
                                    ],
                                    'projects' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/refreshProjects'
                                        ],
                                        'child_routes' => [
                                            'rest' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                        'action' => 'refreshProjects'
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'append-change' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/appendchange'
                                        ],
                                        'child_routes' => [
                                            'append-change-post' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                        'action' => 'appendChange'
                                                    ],
                                                ],
                                            ]
                                        ],
                                    ],
                                    'replace-with-change' => [
                                        'type' => Segment::class,
                                        'options' => [
                                            'route' => '/replacewithchange'
                                        ],
                                        'child_routes' => [
                                            'replace-with-change-post' => [
                                                'type' => Method::class,
                                                'options' => [
                                                    'verb' => Request::METHOD_POST,
                                                    'defaults' => [
                                                        'action' => 'replaceWithChange'
                                                    ],
                                                ],
                                            ]
                                        ],
                                    ],
                                ],
                            ],
                            'get-dashboard-reviews' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/dashboard',
                                    'defaults' => [
                                        'controller' => ReviewApi::class,
                                        'action' => 'dashboard'
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'review' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review[/v[:version]][/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'review',
                        'version'    => null
                    ],
                ],
            ],
            'review-files ' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review[/v[:version]]/files[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'review',
                        'version'    => null
                    ],
                ],
            ],
            'review-comments ' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review[/v[:version]]/comments[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'review',
                        'version'    => null
                    ],
                ],
            ],
            'review-activity ' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review[/v[:version]]/activity[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'review',
                        'version'    => null
                    ],
                ],
            ],
            'review-history ' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review[/v[:version]]/history[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'review',
                        'version'    => null
                    ],
                ],
            ],
            'review-version-delete' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/v:version/delete[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'deleteVersion',
                        'version'    => null
                    ],
                ],
            ],
            'review-reviewer' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/reviewers/:user[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'reviewer',
                        'user'       => null
                    ],
                ],
            ],
            'review-reviewers' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/reviewers[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'reviewers'
                    ],
                ],
            ],
            'review-author' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/author[/:author][/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'editAuthor',
                        'author'     => null
                    ],
                ],
            ],
            'review-vote' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/vote/:vote[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'vote',
                        'vote'       => null
                    ],
                ],
            ],
            'review-tests' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/tests/:status[/:token][/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'testStatus',
                        'status'     => null,
                        'token'      => null
                    ],
                ],
            ],
            'review-deploy' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/deploy/:status[/:token][/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'deployStatus',
                        'status'     => null,
                        'token'      => null
                    ],
                ],
            ],
            'review-transition' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/:review/transition[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'transition'
                    ],
                ],
            ],
            'dashboards' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route' => '/dashboards/action',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'dashboard',
                        'author' => null
                    ],
                ],
            ],
            'reviews' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/reviews[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index'
                    ],
                ],
            ],
            'add-review' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/review[s]/add[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'add'
                    ],
                ],
            ],
            'review-file' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/reviews?/(?P<review>[0-9]+)/v(?P<version>[0-9,]+)/files?(/(?P<file>.*))?',
                    'spec'     => '/reviews/%review%/v%version%/files/%file%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'fileInfo',
                        'review'     => null,
                        'version'    => null,
                        'file'       => null
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => IndexControllerFactory::class,
            ReviewApi::class => IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'aliases' => [
            Keywords::SERVICE     => Keywords::class,
            Services::TRANSITIONS => Transitions::class,
            IModelDAO::REVIEW_DAO => ReviewDAO::class,
            Services::VOTE_INPUT_FILTER => VoteInput::class,
            IParticipants::PARTICIPANTS => Participants::class,
            IVersion::VERSION_FILTER => Version::class,
            IStatistics::COMPLEXITY_SERVICE => Statistics::class,
            Services::GET_REVIEWS_FILTER => GetReviews::class,
            Services::PROJECTS_FOR_USER => ProjectsForUser::class,
            Services::FILE_READ_UNREAD_FILTER => FileReadUnRead::class,
            IModelDAO::FILE_INFO_DAO => FileInfoDAO::class,
            IAppendReplaceChange::FILTER => AppendReplaceChange::class
        ],
        'factories' => array_fill_keys(
            [
                Keywords::class,
                Transitions::class,
                ReviewDAO::class,
                VoteInput::class,
                Participants::class,
                Version::class,
                Statistics::class,
                GetReviews::class,
                ProjectsForUser::class,
                FileReadUnRead::class,
                FileInfoDAO::class,
                AppendReplaceChange::class
            ],
            InvokableServiceFactory::class
        )
    ],
    'view_manager' => [
        'template_path_stack'   => [
            __DIR__ . '/../view',
        ],
    ],
    'view_helpers' => [
        'factories' => array_fill_keys(
            [
                ViewHelperFactory::REVIEWS,
                ViewHelperFactory::REVIEW_KEYWORDS,
                ViewHelperFactory::REVIEWERS_CHANGES,
                ViewHelperFactory::AUTHOR_CHANGE,
            ],
            ViewHelperFactory::class
        )
    ],
    'menu_helpers' => [
        'dashboard' => ['target'=>'/','priority'=>100,'cssClass'=>'component'],
        'reviews' => [
            'cssClass' => 'component',
            'class'    => '\Projects\Menu\Helper\ProjectAwareMenuHelper',
            'priority' => 130
        ]
    ]
];
