<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

return [
    'libreoffice' => [
        'path' => 'soffice'
    ],
    'xhprof' => [
        'ignored_routes' => ['libreoffice']
    ],
    'router' => [
        'routes' => [
            'libreoffice' => [
                'type' => 'Application\Router\Regex',
                'options' => [
                    'regex'    => '/libreoffice?(/(?P<path>.*))?',
                    'spec'     => '/libreoffice/%path%',
                    'defaults' => [
                        'controller' => LibreOffice\Controller\IndexController::class,
                        'action'     => 'index',
                        'path'       => null
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            LibreOffice\Controller\IndexController::class => Application\Controller\IndexControllerFactory::class
        ],
    ],
];
