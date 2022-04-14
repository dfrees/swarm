<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Exception\IException;
use Redis\Exception\RedisException;
use Laminas\EventManager\Event;

/**
 * Module to load Redis configuration settings.
 */
class Module
{
    /**
     * Bootstrap for the redis to ensure we have redis module loaded.
     *
     * @param Event $event
     * @throws ConfigException
     * @throws RedisException
     */
    public function onBootstrap(Event $event)
    {
        try {
            // Verify that redis is present and working.
            $services = $event->getApplication()->getServiceManager();
            $services->get(RedisService::class);
        } catch (RedisException $redisError) {
            // Get the config and if we are in development mode throw default error
            $config = $services->get(ConfigManager::CONFIG);
            $mode   = ConfigManager::getValue($config, ConfigManager::ENVIRONMENT_MODE, ConfigManager::PRODUCTION);
            if ($mode === ConfigManager::DEVELOPMENT) {
                throw $redisError;
            }
            $response = $event->getResponse();
            $response->setStatusCode($redisError->getCode());
            $response->setReasonPhrase($redisError->getMessage());
            $response->setMetaData([IException::CUSTOM_ERROR => true]);
        }
    }

    /**
     * Load and merge in configuration.
     * @return mixed
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
