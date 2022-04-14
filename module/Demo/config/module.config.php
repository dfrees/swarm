<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

return [
    'router' => [
        'routes' => [
            'demo-generate' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/demo/generate[/]',
                    'defaults' => [
                        'controller' => Demo\Controller\IndexController::class,
                        'action'     => 'generate',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Demo\Controller\IndexController::class => Application\Controller\IndexControllerFactory::class
        ],
    ],
];
