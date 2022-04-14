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
use Application\Controller\IndexControllerFactory;
use Search\Controller\SearchApi;
use Laminas\Http\Request;
use Application\Factory\InvokableServiceFactory;
use Search\Filter\Search;
use Search\Filter\ISearch;
use Search\Service\FileSearch;
use Search\Service\IFileSearch;

return [
    ConfigManager::SEARCH => [
        ConfigManager::P4_SEARCH_HOST => null,
        ConfigManager::P4_SEARCH_API_PATH => '/api/v1/search/raw',
    ],
    'controllers' => [
        'factories' => [
            SearchApi::class => IndexControllerFactory::class,
        ]
    ],
    'service_manager' => [
        'aliases' => [
            ISearch::SEARCH_FILTER => Search::class,
            IFileSearch::FILE_SEARCH_SERVICE => FileSearch::class
        ],
        'factories' => array_fill_keys(
            [
                Search::class,
                FileSearch::class
            ],
            InvokableServiceFactory::class
        )
    ],
    'router' => [
        'routes' => [
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => SearchApi::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'searchApi' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/search',
                            'constraints' => [IRequest::VERSION => "v1[0-1]"],
                        ],
                        'child_routes' => [
                            'search' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => Request::METHOD_GET,
                                    'defaults' => [
                                        'controller' => SearchApi::class,
                                        'action'     => 'search'
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]
];
