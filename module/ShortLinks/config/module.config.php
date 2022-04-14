<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

return [
    'short_links' => [
        'hostname'     => null,     // a dedicated host for short links - defaults to standard host
                                    // this setting will be ignored if 'external_url' is set
        'external_url' => null,     // force a custom fully qualified URL (example: "https://example.com:8488")
                                    // this setting will override 'hostname' if both are specified
                                    // if set then ['environment']['external_url'] must also be set
    ],
    'router' => [
        'routes' => [
            'short-link' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/l[/:link][/]',
                    'defaults' => [
                        'controller' => ShortLinks\Controller\IndexController::class,
                        'action'     => 'index',
                        'link'       => null
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            ShortLinks\Controller\IndexController::class => Application\Controller\IndexControllerFactory::class
        ],
    ],
];
