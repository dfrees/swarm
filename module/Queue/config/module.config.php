<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Config\ConfigManager;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Queue\Controller\IndexController;

return [
    ConfigManager::QUEUE  => [
        ConfigManager::WORKERS                     => 3,
        ConfigManager::WORKER_LIFETIME             => 595,  // 10 minutes (less 5s)
        ConfigManager::WORKER_TASK_TIMEOUT         => 1800, // 30 minutes (max execution time per task)
        ConfigManager::WORKER_MEMORY_LIMIT         => '1G',
        ConfigManager::DISABLE_TRIGGER_DIAGNOSTICS => true,
        ConfigManager::WORKER_CHANGE_SAVE_DELAY    => 5000, // millisecond delay for a future changesaved task in the
                                                            // queue
    ],
    'router' => [
        'routes' => [
            'worker' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/queue/worker[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'worker',
                    ],
                ],
            ],
            'status' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/queue/status[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'status',
                    ],
                ],
            ],
            'tasks' => [
                'type' => 'Laminas\Router\Http\Segment',
                'options' => [
                    'route'    => '/queue/tasks[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'tasks',
                    ],
                ],
            ],
        ],
    ],
    'xhprof' => [
        'ignored_routes' => ['worker']
    ],
    'security' => [
        'login_exempt' => ['worker']
    ],
    'controllers' => [
        'factories' => [
           IndexController::class => IndexControllerFactory::class
        ],
    ],
    'service_manager' => [
        'aliases'   => [
            Queue\Manager::SERVICE => Queue\Manager::class,
        ],
        'factories' => [
            Queue\Manager::class => InvokableServiceFactory::class,
        ],
    ],
];
