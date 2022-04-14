<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Cache\AbstractCacheService;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Controller\IndexController;
use Application\Controller\IndexControllerFactory;
use Application\Controller\ValidationController;
use Application\Depot\FileStorageFactory;
use Application\Factory\InvokableServiceFactory;
use Application\I18n\TranslatorFactory as SwarmTranslatorFactory;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Model\KeyDAO;
use Menu\Model\MenuDAO;
use Application\Permissions\ConfigCheck;
use Application\Permissions\PrivateProjects;
use Application\Permissions\RestrictedChanges;
use Application\Permissions\Reviews;
use Application\Session\SwarmSession;
use Application\Permissions\Permissions;
use Application\Permissions\IpProtects;
use Application\Permissions\Csrf\Service as CsrfService;
use Application\View\Helper\ViewHelperFactory;
use Markdown\Settings as MarkdownSettings;
use Application\Config\CacheService;
use Api\Controller\ICacheController;
use Redis\RedisService;
use Redis\Manager;
use Application\Config\Services;
use Application\Checker;
use Application\Permissions\IPermissions;
use Application\Filter\Linkify;
use Application\Http\SwarmRequest;

return [
    ConfigManager::ENVIRONMENT => [
        'mode'            => getenv('SWARM_MODE') ?: 'production',
        'hostname'        => getenv('SWARM_HOST') ?: null,             // a dedicated Swarm hostname - this setting will
                                                                       // be ignored if 'external_url' is set
        'external_url'    => null,                                     // force a custom fully qualified URL (example:
                                                                       // "https://example.com:8488") - this setting
                                                                       // will override 'hostname' if both are specified
        ConfigManager::BASE_URL        => null,
        ConfigManager::ASSET_BASE_PATH => null,
        ConfigManager::LOGOUT_URL      => null,                        // Customer specific logout url to be called
                                                                       // after Swarm logout
        ConfigManager::VENDOR => [
            ConfigManager::EMOJI_PATH => 'vendor/gemoji/images'
        ]
    ],
    ConfigManager::MARKDOWN => [
        ConfigManager::FILE_EXTENSIONS => MarkdownSettings::DEFAULT_FILE_EXTENSIONS,
        ConfigManager::MARKDOWN        => MarkdownSettings::SAFE
    ],
    ConfigManager::LINKIFY => [
        ConfigManager::WORD_LENGTH_LIMIT => 1024, // Word length limit for candidates to linkify
        ConfigManager::MARKDOWN => [ // Patterns used to add links to markdown content
            [
                ConfigManager::ID => ConfigManager::GROUPS,
                ConfigManager::REGEX => '(@@[!*]*)([^@\s]+)',
                ConfigManager::URL =>  '{baseurl}'.ConfigManager::GROUPS.'/{2}',
            ],
            [
                ConfigManager::ID => ConfigManager::JOBS,
                ConfigManager::REGEX => '(@?)(job[0-9]+)',
                ConfigManager::URL =>  '{baseurl}'.ConfigManager::JOBS.'/{2}',
            ],
            [
                ConfigManager::ID => ConfigManager::REVIEWS,
                ConfigManager::REGEX => '#?review-([0-9]+)',
                ConfigManager::URL =>  '{baseurl}'.ConfigManager::REVIEWS.'/{1}',
            ],
            [
                ConfigManager::ID => ConfigManager::SWARM,
                ConfigManager::REGEX => '[#@]([0-9]+)',
                ConfigManager::URL =>  '{baseurl}{1}',
            ],
            // Users is last to allow existing @... references to work
            [
                ConfigManager::ID => ConfigManager::USERS,
                ConfigManager::REGEX => '(@[*]*)([^@\s]+)',
                ConfigManager::URL =>  '{baseurl}'.ConfigManager::USERS.'/{2}',
            ],
        ],
    ],
    'http_client_options' => [
        'timeout'   => 10,
        'hosts'     => []              // optional, per-host overrides; host as key, array of options as value
    ],
    'session' => [
        'cookie_lifetime'            => 0,          // session cookie lifetime to use when remember me isn't checked
        'remembered_cookie_lifetime' => 30*24*60*60 // session cookie lifetime to use when remember me is checked
    ],
    'security' => [
        'require_login'          => true,   // if enabled only the login screen will be accessible for anonymous users
        'disable_autojoin'       => false,  // if enabled user will not auto-join the swarm group on login
        'https_strict'           => false,  // if enabled, we'll tell clients to pin on https for 30 days
        'https_strict_redirect'  => true,   // if both https_strict and this are enabled; we meta-refresh HTTP to HTTPS
        'https_port'             => null,   // optionally, specify a non-standard port to use for https
        'emulate_ip_protections' => true,   // if enabled, ip-based protections matching user's remote ip are applied
        'disable_system_info'    => false,  // if enabled, system info is disabled (results in a 403 if accessed)
        'x_frame_options'        => 'SAMEORIGIN', // x-frame-options header to send - set to false to disable
        'csrf_exempt'            => [
            'goto','api/login/initauth/initauth',
            'api/login/checkauth/checkauth',
            'api/login/samlResponse/samlResponse',
            'api/logout/user',
            'api/session/session-transitions'
        ],
        'multiserver_login_exempt_routes'  // specify route id's which bypass require_login setting
                                 => ['home'],
    ],
    'upgrade' => [
        'status_refresh_interval' => 10,     // Refresh page every 10 seconds
        'batch_size'              => 1000,   // Fetch 1000 review to lower the memory usage.
    ],
    'git_fusion' => [
        'depot' => '.git-fusion',
        'user'  => 'git-fusion-user',
        'reown' => [                   // git-fusion commits as its user then re-owns the change to the real author
            'retries'  => 20,               // we'll retry processing up to this many times to get the actual author
            'max_wait' => 60                // the delay between tries starts at 2 seconds and grows up to this limit
        ]
    ],
    'css' => [
        '/build/min.css' => [
            '/vendor/bootstrap/css/bootstrap.min.css',
            '/vendor/prettify/prettify.css',
            '/swarm/css/style.css'
        ]
    ],
    'p4' => [
        'slow_command_logging'  => [
            3,    // commands without a specific rule get a 3 second limit
            10 => ['print', 'shelve', 'submit', 'sync', 'unshelve']
        ],
        'max_changelist_files'  => 1000, // limit the number of files displayed in a change or a review
        'auto_register_url'     => true, // set to false to disable P4.Swarm.URL registration as a p4 property
        'proxy_mode'            => true  // Set to false to disable proxy authentication mode.
    ],
    'js' => [
        '/build/min.js' => [
            '/vendor/jquery/jquery-3.2.1.min.js',
            '/vendor/jquery-sortable/jquery-sortable-min.js',
            '/vendor/bootstrap/js/bootstrap.min.js',
            '/vendor/diff_match_patch/diff_match_patch.js',
            '/vendor/jquery.expander/jquery.expander.min.js',
            '/vendor/jquery.timeago/jquery.timeago.js',
            '/vendor/jsrender/jsrender.js',
            '/vendor/prettify/prettify.js',
            '/vendor/jed/jed.js',
            '/swarm/js/jquery-plugins.js',
            '/swarm/js/bootstrap-extensions.js',
            '/swarm/js/application.js',
            '/swarm/js/activity.js',
            '/swarm/js/users.js',
            '/swarm/js/projects.js',
            '/swarm/js/groups.js',
            '/swarm/js/files.js',
            '/swarm/js/changes.js',
            '/swarm/js/comments.js',
            '/swarm/js/mentions.js',
            '/swarm/js/attachments.js',
            '/swarm/js/reviews.js',
            '/swarm/js/jobs.js',
            '/swarm/js/3dviewer.js',
            '/swarm/js/i18n.js',
            '/swarm/js/notifications.js',
            '/swarm/js/init.js',
            '/swarm/js/time.js',
            '/swarm/js/workflow.js',
            '/swarm/js/testdefinition.js'
        ]
    ],
    'router' => [
        'routes' => [
            'about' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/about[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'about',
                    ],
                ],
            ],
            'goto'  => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/(@+)?(?P<id>.+)',
                    'spec'     => '/@%id%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'goto',
                        'id'         => null
                    ],
                ],
                'priority' => -1000     // we'll catch anything that falls through by setting a late priority
            ],
            'info' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/info[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'info',
                    ],
                ],
            ],
            'log' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/info/log[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'log',
                    ],
                ],
            ],
            'phpinfo' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/info/phpinfo[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'phpinfo',
                    ],
                ],
            ],
            'upgrade' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/upgrade[/]',
                    'defaults' => [
                        'controller' => \Reviews\Controller\IndexController::class,
                        'action'     => 'upgrade',
                    ],
                ],
            ],
            'validate' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route' => '/validate/emailAddress[/]'
                ],
                'child_routes' => [
                    'email-address' => [
                        'type' => 'Laminas\Router\Http\Method',
                        'options' => [
                            'verb' => 'post,get',
                            'defaults' => [
                                'controller' => ValidationController::class,
                                'action'     => 'emailAddress'
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class      => IndexControllerFactory::class,
            ValidationController::class => IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'aliases'   => [
            'MvcTranslator'                     => TranslatorFactory::class,
            TranslatorFactory::SERVICE          => TranslatorFactory::class,
            SwarmLogger::SERVICE                => SwarmLogger::class,
            SwarmSession::SESSION               => SwarmSession::class,
            Permissions::PERMISSIONS            => Permissions::class,
            IpProtects::IP_PROTECTS             => IpProtects::class,
            Services::CONFIG_CHECK              => ConfigCheck::class,
            PrivateProjects::PROJECTS_FILTER    => PrivateProjects::class,
            CsrfService::CSRF_CONTAINER         => CsrfService::class,
            Reviews::REVIEWS_FILTER             => Reviews::class,
            ICacheController::CONFIG_CACHE      => CacheService::class,
            ICacheController::REDIS_CACHE       => Manager::class,
            AbstractCacheService::CACHE_SERVICE => RedisService::class,
            IModelDAO::KEY_DAO                  => KeyDAO::class,
            IModelDAO::MENU_DAO                 => MenuDAO::class,
            Services::LINKIFY                   => Linkify::class,
            Services::SWARM_REQUEST             => SwarmRequest::class
        ],
        'factories' => [
            SwarmLogger::class           => InvokableServiceFactory::class,
            ConnectionFactory::P4_CONFIG => ConnectionFactory::class,
            ConnectionFactory::P4        => ConnectionFactory::class,
            ConnectionFactory::P4_ADMIN  => ConnectionFactory::class,
            ConnectionFactory::P4_USER   => ConnectionFactory::class,
            SwarmSession::class          => InvokableServiceFactory::class,
            Permissions::class           => InvokableServiceFactory::class,
            ConfigCheck::class           => InvokableServiceFactory::class,
            IpProtects::class            => InvokableServiceFactory::class,
            'depot_storage'              => FileStorageFactory::class,
            RestrictedChanges::class     => InvokableServiceFactory::class,
            PrivateProjects::class       => InvokableServiceFactory::class,
            Reviews::class               => InvokableServiceFactory::class,
            CsrfService::class           => InvokableServiceFactory::class,
            TranslatorFactory::class     => SwarmTranslatorFactory::class,
            CacheService::class          => InvokableServiceFactory::class,
            Manager::class               => InvokableServiceFactory::class,
            KeyDAO::class                => InvokableServiceFactory::class,
            MenuDAO::class               => InvokableServiceFactory::class,
            Linkify::class               => InvokableServiceFactory::class,
            SwarmRequest::class          => InvokableServiceFactory::class,
        ],
    ],
    TranslatorFactory::SERVICE => [
        'locale'                    => 'en_US',
        'detect_locale'             => true,
        'translation_file_patterns' => [
            [
                'type'        => 'gettext',
                'base_dir'    => BASE_PATH . '/language',
                'pattern'     => '%s/default.mo',
            ],
        ],
        'non_utf8_encodings' => ['windows-1252'],
        ConfigManager::UTF8_CONVERT => true,
    ],
    'view_manager' => [
        'display_not_found_reason' => false,
        'display_exceptions'       => false,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/index',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/react'            => __DIR__ . '/../view/layout/react.phtml',
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'layout/toolbar'          => __DIR__ . '/../view/layout/toolbar.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy', 'ViewFeedStrategy'
        ],
    ],
    ConfigManager::LOG => [
        'file'         => DATA_PATH . '/log',
        'priority'     => 3, // just log errors by default
        ConfigManager::REFERENCE_ID => false,
        ConfigManager::EVENT_TRACE  => false,
        ConfigManager::JS_DEBUG     => [],
    ],
    'view_helpers' => [
        'factories' => array_fill_keys(
            [ViewHelperFactory::ASSET_BASE_PATH,
            ViewHelperFactory::ESCAPE_FULL_URL,
            ViewHelperFactory::BREADCRUMBS,
            ViewHelperFactory::BODY_CLASS,
            ViewHelperFactory::CSRF,
            ViewHelperFactory::ESCAPE_FULL_URL,
            ViewHelperFactory::HEAD_LINK,
            ViewHelperFactory::HEAD_SCRIPT,
            ViewHelperFactory::LINKIFY,
            ViewHelperFactory::PERMISSIONS,
            ViewHelperFactory::PREFORMAT,
            ViewHelperFactory::QUALIFIED_URL,
            ViewHelperFactory::REQUEST,
            ViewHelperFactory::SHORTEN_STACK_TRACE,
            ViewHelperFactory::TRUNCATE,
            ViewHelperFactory::UTF8_FILTER,
            ViewHelperFactory::ROUTE_MATCH,
            ViewHelperFactory::WORD_WRAP,
            ViewHelperFactory::WORDIFY,
            ViewHelperFactory::T,
            ViewHelperFactory::TE,
            ViewHelperFactory::TP,
            ViewHelperFactory::TPE,
            ViewHelperFactory::SERVICE],
            ViewHelperFactory::class
        )
    ],
    'controller_plugins' => [
        'invokables' => [
            'disconnect'    => 'Application\Controller\Plugin\Disconnect'
        ]
    ],
    'depot_storage' => [
        'base_path' => '//.swarm'
    ],
    /**
     * List of 'checkers' that will be called by the ConfigCheck class. Defined here so that
     * if no modules define checkers we will have and empty array (module specific checkers
     * are merged).
     *
     * Format is
     *
     * Checker::CHECKERS => [
     *     <check name> => class name extending Checker
     * ]
     *
     * The class name being mapped to must also be listed in as a factory so it can be looked
     * up via the service manager.
     */
    Checker::CHECKERS => array_fill_keys(
        [
            // Note: we should be migrating to the checker pattern. Currently
            // only IPermissions::AUTHENTICATED is implemented (see SW-7291)
            IPermissions::AUTHENTICATED,
        ],
        Permissions::class
    )
];
