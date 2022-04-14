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
            'imagick' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/imagick?(/(?P<path>.*))?',
                    'spec'     => '/imagick/%path%',
                    'defaults' => [
                        'controller' => Imagick\Controller\IndexController::class,
                        'action'     => 'index',
                        'path'       => null
                    ],
                ],
            ],
        ],
    ],
    'xhprof' => [
        'ignored_routes' => ['imagick']
    ],
    'controllers' => [
        'factories' => [
            Imagick\Controller\IndexController::class => Application\Controller\IndexControllerFactory::class
        ],
    ],
];
