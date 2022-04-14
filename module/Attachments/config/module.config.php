<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

return [
    'attachments' => [
        'max_file_size' => null, // in bytes; will default to php upload_max_size if blank
    ],
    'router' => [
        'routes' => [
            'attachments' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/attachment[s]/:attachment[/][:filename]',
                    'defaults' => [
                        'controller' => Attachments\Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'add-attachment' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/attachments/add[/]',
                    'defaults' => [
                        'controller' => Attachments\Controller\IndexController::class,
                        'action'     => 'add',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Attachments\Controller\IndexController::class => Application\Controller\IndexControllerFactory::class
        ],
    ],
];
