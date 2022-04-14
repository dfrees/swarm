<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
use Application\Config\IDao;
use Application\Factory\InvokableServiceFactory;
use Application\Controller\IndexControllerFactory;
use Api\IRequest;
use Spec\Model\SpecDAO;
use Spec\Controller\SpecAPI;

return [
    'service_manager' => [
        'aliases' => [
            IDao::SPEC_DAO => SpecDAO::class,
        ],
        'factories' => array_fill_keys(
            [
                SpecDAO::class,
            ], InvokableServiceFactory::class
        ),
    ],
    'controllers' => [
        'factories' => [
            SpecAPI::class => IndexControllerFactory::class
        ]
    ],
    'router' => [
        'routes' => [
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => '/api',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'spec' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/specs/:type',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                            'defaults' => [
                                'controller' => SpecAPI::class
                            ]
                        ],
                        'child_routes' => [
                            'fields' => [
                                'type' => 'Laminas\Router\Http\Segment',
                                'options' => [
                                    'route' => '/fields'
                                ],
                                'child_routes' => [
                                    'fields' => [
                                        'type' => 'Laminas\Router\Http\Method',
                                        'options' => [
                                            'verb' => 'get',
                                            'defaults' => [
                                                'action' => 'fields'
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
];
