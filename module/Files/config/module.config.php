<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Config\ConfigManager;
use Application\Factory\InvokableServiceFactory;
use Application\View\Helper\ViewHelperFactory;
use Application\Controller\IndexControllerFactory;
use Files\Controller\IndexController;
use Files\Format\Manager;
use Files\Archiver;
use Files\Service\File;
use Application\Config\Services;
use Api\Controller\IndexController as ApiController;
use Api\IRequest;
use Files\Controller\FileApi;
use Laminas\Router\Http\Segment;
use Laminas\Router\Http\Method;
use Laminas\Http\Request;
use Application\Router\Regex;
use Laminas\Router\Http\Literal;
use Files\Model\FileDAO;
use Application\Config\IDao;
use Files\Filter\UpdateFile as UpdateFileFilter;
use Files\Filter\GetFile as GetFileFilter;
use Files\Filter\IFile;
use Files\Filter\Diff\IDiff as IDiffFilter;
use Files\Filter\Diff\Diff as DiffFilter;

return [
    'archives' => [
        'max_input_size'     => 512 * 1024 * 1024, // 512M (must be in bytes)
        'archive_timeout'    => 1800,              // 30 minutes (must be in seconds)
        'cache_lifetime'     => 60 * 60 * 24,      // time to keep archives before deleting them (in seconds)
        'compression_level'  => 1                  // should be between 0 (no compression) and 9 (maximum compression)
    ],
    ConfigManager::FILES => [
        ConfigManager::ALLOW_EDITS => true,        // Allow inline editing of files
        'max_size'                 => 1024 * 1024, // 1MB (must be in bytes)
        'download_timeout'         => 1800,        // Time allowed before download of a non archive file will error
                                                   // default 30 minutes (must be in seconds)
    ],
    'diffs' => [
        'max_diffs'          => 1500,   // maximum number of diff lines without manual user override
        'context_lines'      => 5       // number of context lines around a diff
    ],
    'xhprof' => [
        'ignored_routes' => ['archive', 'download', 'view']
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
                    'files' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:version/files/:id',
                            'constraints' => [IRequest::VERSION => 'v1[0-1]'],
                            'defaults' => [
                                'controller' => FileApi::class
                            ],
                        ],
                        'child_routes' => [
                            'content' => [
                                'type' => Method::class,
                                'options' => [
                                    'verb' => implode(
                                        ',',
                                        [Request::METHOD_PUT, Request::METHOD_GET]
                                    ),
                                ]
                            ],
                            'diff' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/diff',
                                ],
                                'child_routes' => [
                                    'fileDiff' => [
                                        'type' => Method::class,
                                        'options' => [
                                            'verb' => Request::METHOD_GET,
                                            'defaults' => [
                                                'action' => 'diff'
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ]
                    ],
                ]
            ],
            'file' => [
                'type' => Regex::class,
                'options' => [
                    'regex'    => '/files?(/(?P<path>.*))?',
                    'spec'     => '/files/%path%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'file',
                        'path'       => null
                    ],
                ],
            ],
            'view' => [
                'type' => Regex::class,
                'options' => [
                    'regex'    => '/view(/(?P<path>.*))?',
                    'spec'     => '/view/%path%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'file',
                        'path'       => null,
                        'view'       => true
                    ],
                ],
            ],
            'download' => [
                'type' => Regex::class,
                'options' => [
                    'regex'    => '/downloads?(/(?P<path>.*))?',
                    'spec'     => '/downloads/%path%',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'file',
                        'path'       => null,
                        'download'   => true
                    ],
                ],
            ],
            'archive' => [
                'type' => Regex::class,
                'options' => [
                    'regex'    => '/archives?/(?P<path>.+)\.zip',
                    'spec'     => '/archives/%path%.zip',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'archive'
                    ],
                ],
            ],
            'archive-status' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/archive-status/:digest[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'archive'
                    ],
                ],
            ],
            'diff' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/diff',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'diff',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => array_fill_keys(
            [
                IndexController::class,
                FileApi::class
            ],
            IndexControllerFactory::class
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
                ViewHelperFactory::FILE_SIZE,
                ViewHelperFactory::DECODE_FILE_SPEC,
                ViewHelperFactory::DECODE_SPEC,
                ViewHelperFactory::FILE_TYPE_VIEW,
            ],
            ViewHelperFactory::class
        )
    ],
    'service_manager' => [
        'aliases'   => [
            Manager::FORMATS       => Manager::class,
            Services::ARCHIVER     => Archiver::class,
            Services::FILE_SERVICE => File::class,
            IDao::FILE_DAO         => FileDAO::class,
            IFile::UPDATE_FILTER   => UpdateFileFilter::class,
            IFile::GET_FILTER      => GetFileFilter::class,
            IDiffFilter::NAME      => DiffFilter::class
        ],
        'factories' => array_fill_keys(
            [
                Manager::class,
                Archiver::class,
                File::class,
                FileDAO::class,
                UpdateFileFilter::class,
                GetFileFilter::class,
                DiffFilter::class
            ],
            InvokableServiceFactory::class
        )
    ],
    'menu_helpers' => [
        'files' => [
            'class'    => '\Projects\Menu\Helper\ProjectAwareMenuHelper',
            'priority' => 150
        ]
    ]
];
