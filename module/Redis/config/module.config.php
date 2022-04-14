<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Application\Config\Services;
use Application\Factory\InvokableServiceFactory;
use Application\Config\ConfigManager;
use Application\Lock\ILock;
use Redis\Filter\CacheVerify;
use Redis\LockService;
use Redis\RedisService;

return [
    ConfigManager::REDIS => [
        ConfigManager::OPTIONS => [
            'password'               => null,
            ConfigManager::NAMESPACE => RedisService::REDIS_NAMESPACE,
            'server'                 => [
                'host' => 'localhost',
                'port' => '7379',
            ],

        ],
        // Number of seconds to hold or block on lock for Redis model population
        ConfigManager::POPULATION_LOCK_TIMEOUT => 300,
        // Maximum number of key/value pairs allowed in an mSet call to redis. Sets exceeding
        // this will be batched according to this maximum
        ConfigManager::ITEMS_BATCH_SIZE        => 100000,
        // 24hr time to check the integrity of the cache, default to early hours of the morning
        ConfigManager::CHECK_INTEGRITY => '03:00',
        ConfigManager::INVALID_KEY_CHARS => ':@{}()' // Characters that are not permitted in redis key values
    ],
    'service_manager'   => [
        'factories' => [
            LockService::class  => InvokableServiceFactory::class,
            RedisService::class => InvokableServiceFactory::class,
            CacheVerify::class  => InvokableServiceFactory::class,
        ],
        'aliases' => [
            ILock::SERVICE               => LockService::class,
            Services::REDIS_CACHE_VERIFY => CacheVerify::class
        ]
    ]
];
