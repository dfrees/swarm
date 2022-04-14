<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis;

use Application\Cache\SimpleCacheDecorator;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Redis\Exception\RedisException;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\StorageFactory;
use Laminas\Http\Response;

/**
 * Service to handle Redis caching
 *
 * @package Redis
 */
class RedisService extends SimpleCacheDecorator implements InvokableService
{
    const RESOURCE_ID     = 'SwarmRedis';
    const REDIS_NAMESPACE = 'Swarm';
    const REDIS           = 'redis';
    const SEPARATOR       = '^';

    // These are not supported in user or group names, so they are safe to use a name separators
    const SEARCH_PART_SEPARATOR = ":";
    const SEARCH_FULL_SEPARATOR = "^";

    private $resource = null;
    private $options  = null;

    // The hashing algorithm to use for encoded keys
    const HASHED_KEY_ALGORITHM = "md5";

    /**
     * Create a Redis Service based on settings from the config.
     * Merge the user config into default values.
     *
     * Gives access to the Cache decorator
     *
     * @param ContainerInterface $services
     * @param array|null         $options
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        // Configuration is required before the parent constructor can be called so make sure we
        // set it here to be used by $this->buildNameSpace
        $this->setConfig($services->get(ConfigManager::CONFIG));
        $defaults = [
            'name'    => ConfigManager::REDIS,
            'options' => [
                'namespace' => $this->buildNameSpace(
                    ConfigManager::getValue(
                        $this->config,
                        ConfigManager::REDIS_OPTIONS_NAMESPACE,
                        self::REDIS_NAMESPACE
                    )
                ),
            ]
        ];

        $storage = StorageFactory::factory(
            [
                'adapter' => array_replace_recursive($this->config[self::REDIS], $defaults),
                'plugins' => [
                    // Swarm custom serializer plugin that handles errors
                    new Serializer($services),
                    'exception_handler' => ['throw_exceptions' => true]
                ],
                'options' => [
                    'resource_id' => self::RESOURCE_ID
                ]
            ]
        );
        // Try creating the redis connection.
        try {
            parent::__construct($services, $storage);
            $this->resource = $storage->getOptions()->getResourceManager()->getResource(self::RESOURCE_ID);
            $this->options  = $storage->getOptions();
            // Try to ping redis to see if its reachable.
            $this->getRedisResource()->ping();
        } catch (\Exception $redisError) {
            // Throw a RedisException and let that set the message and code.
            throw new RedisException($redisError->getMessage(), Response::STATUS_CODE_503, $redisError);
        }
    }

    /**
     * Get the underlying resource that provides redis calls. We will need to use this to get access to calls not
     * provided by the Zend adapter layer.
     * @return mixed
     */
    public function getRedisResource()
    {
        return $this->resource;
    }

    /**
     * Get the underlying Options that can be provided to redis. We can use this to get redis settings and tweak them
     * as required during operations.
     *
     * @return AdapterOptions|null
     */
    public function getRedisOptions()
    {
        return $this->options;
    }

    /**
     * Build name space for redis
     *
     * The namespace can't be longer than 128 character long. This is a limit in the Redis Options Adapter
     *
     * @param string   $prefix   the prefix to use for the namespace.
     * @return string
     * @throws ConfigException
     */
    public function buildNameSpace($prefix)
    {
        $newNamespace = P4_SERVER_ID ? $prefix . self::SEPARATOR . P4_SERVER_ID : $prefix;
        return $this->validateNamespace($newNamespace);
    }

    /**
     * Get the namespace that this redis cache is using.
     *
     * @return string
     */
    public function getNamespace()
    {
        $cacheOptions = $this->getRedisOptions();
        return $cacheOptions->getNamespace().$cacheOptions->getNamespaceSeparator();
    }
}
