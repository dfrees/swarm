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
use Application\Controller\IndexControllerFactory;
use Menu\Controller\MenuApi;

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
                    'menu' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/menus[/]',
                            'constraints' => [IRequest::VERSION => 'v1[0-1]'],
                        ],
                        'child_routes' => [
                            'menus' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'get',
                                    'defaults' => [
                                        'controller' => MenuApi::class
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ]
    ],
    'controllers' => [
        'factories' => [
            MenuApi::class => IndexControllerFactory::class
        ]
    ]
];
