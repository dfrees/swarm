<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Cache;

use Application\Config\ConfigManager;
use Application\Log\SwarmLogger;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Redis\RedisService;
use Throwable;
use Traversable;
use Laminas\Cache\Exception\InvalidArgumentException as LaminasCacheInvalidArgumentException;
use Laminas\Cache\Psr\SerializationTrait;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheException;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\StorageInterface;
use Interop\Container\ContainerInterface;
use Application\Config\ConfigException;

/**
 * Swarm specific version based on Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator. It was not possible to override
 * some of the PSR-16 limitations as so many of the functions were private, therefore we have our own implementation
 * copy the useful parts and adjust to:
 *  - Allow '/' characters in a key name
 *  - Allow keys to be up to 1000 characters (rather than PSR-16 value of 64)
 * @package Application\Cache
 */
class SimpleCacheDecorator implements ISimpleCacheDecorator
{
    use SerializationTrait;

    /**
     * Allow a namespace prefix of 128 characters (this is as many as zend permit in options)
     */
    const MAX_NAMESPACE_LENGTH = 128;

    /**
     * For now allow large keys instead of PSR-16 default of 64
     */
    const MAX_KEY_LENGTH = self::MAX_NAMESPACE_LENGTH + 1024;
    const LOG_PREFIX     = SimpleCacheDecorator::class;

    /**
     * @var bool
     */
    private $providesPerItemTtl = true;

    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * Reference used by storage when calling getItem() to indicate status of
     * operation.
     *
     * @var null|bool
     */
    private $success;

    /**
     * @var DateTimeZone
     */
    private $utc;

    // Swarm application services
    private $services;
    // Swarm configuration
    protected $config;

    /**
     * SimpleCacheDecorator constructor.
     * @param ContainerInterface    $services   application services
     * @param StorageInterface      $storage    storage interface implementation
     */
    public function __construct(ContainerInterface $services, StorageInterface $storage)
    {
        if ($this->isSerializationRequired($storage)) {
            throw new SimpleCacheException(
                sprintf(
                    'The storage adapter "%s" requires a serializer plugin; please see'
                    . ' https://docs.zendframework.com/zend-cache/storage/plugin/#quick-start'
                    . ' for details on how to attach the plugin to your adapter.',
                    get_class($storage)
                )
            );
        }

        $this->memoizeTtlCapabilities($storage);
        $this->storage  = $storage;
        $this->services = $services;
        $this->config   = $services->get(ConfigManager::CONFIG);
        $this->utc      = new DateTimeZone('UTC');
    }

    /**
     * Set the configuration
     * @param mixed $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     */
    public function get($key, $default = null)
    {
        $this->validateKey($key);

        $this->success = null;
        try {
            $result = $this->storage->getItem($key, $this->success);
        } catch (Throwable $e) {
            throw static::translateException($e);
        }

        $result = $result === null ? $default : $result;
        return $this->success ? $result : $default;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function set($key, $value, $ttl = null)
    {
        $this->validateKey($key);
        $ttl = $this->convertTtlToInteger($ttl);

        // PSR-16 states that 0 or negative TTL values should result in cache
        // invalidation for the item.
        if (null !== $ttl && 1 > $ttl) {
            return $this->delete($key);
        }

        // If a positive TTL is set, but the adapter does not support per-item
        // TTL, we return false immediately.
        if (null !== $ttl && ! $this->providesPerItemTtl) {
            return false;
        }

        $options     = $this->storage->getOptions();
        $previousTtl = $options->getTtl();
        $options->setTtl($ttl);

        $result = false;
        try {
            $result = $this->storage->setItem($key, $value);
        } catch (Throwable $e) {
            throw static::translateException($e);
        } finally {
            $options->setTtl($previousTtl);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function delete($key)
    {
        $this->validateKey($key);

        try {
            return null !== $this->storage->removeItem($key);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear()
    {
        $namespace = $this->storage->getOptions()->getNamespace();

        if ($this->storage instanceof ClearByNamespaceInterface && $namespace) {
            return $this->storage->clearByNamespace($namespace);
        }

        if ($this->storage instanceof FlushableInterface) {
            return $this->storage->flush();
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple($keys, $default = null)
    {
        $keys = $this->convertIterableToArray($keys, false, __FUNCTION__);
        array_walk($keys, [$this, 'validateKey']);

        try {
            $results = $this->storage->getItems($keys);
        } catch (Throwable $e) {
            throw static::translateException($e);
        }

        foreach ($keys as $key) {
            if (! isset($results[$key])) {
                $results[$key] = $default;
                continue;
            }
        }

        return $results;
    }

    /**
     * Calls setMultiple with batch size as specified in configuration
     * @param array|iterable    $values         values to set
     * @param bool              $validateKeys   whether keys should be validated
     * @param int|null          $ttl            ttl
     * @return bool
     * @throws InvalidArgumentException
     * @throws ConfigException
     * @throws Exception
     */
    private function setMultipleBatched($values, $validateKeys = true, $ttl = null)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $ttl    = $this->convertTtlToInteger($ttl);
        // If a positive TTL is set, but the adapter does not support per-item
        // TTL, we return false -- but not until after we validate keys.
        if (null !== $ttl && ! $this->providesPerItemTtl) {
            return false;
        }

        $values        = $this->convertIterableToArray($values, true, __FUNCTION__);
        $valuesChunked = array_chunk(
            $values,
            ConfigManager::getValue($this->config, ConfigManager::REDIS_ITEMS_BATCH_SIZE, 100000),
            true
        );
        $logger->trace(
            sprintf(
                '[%s]: [%s] value chunks [%d] from [%d] values',
                self::LOG_PREFIX,
                'setMultipleBatched',
                count($valuesChunked),
                count($values)
            )
        );
        $options     = $this->storage->getOptions();
        $previousTtl = $options->getTtl();
        $options->setTtl($ttl);
        try {
            $result = [];
            $chunk  = 1;
            foreach ($valuesChunked as $values) {
                $keys = array_keys($values);
                $logger->trace(
                    sprintf(
                        '[%s]: [%s] chunk [%d], [%d] keys',
                        self::LOG_PREFIX,
                        'setMultipleBatched',
                        $chunk,
                        count($keys)
                    )
                );
                // PSR-16 states that 0 or negative TTL values should result in cache
                // invalidation for the items.
                if (null !== $ttl && 1 > $ttl) {
                    return $this->deleteMultiple($keys);
                }
                if ($validateKeys) {
                    array_walk($keys, [$this, 'validateKey']);
                }
                $result = array_merge($result, $this->storage->setItems($values));
                $chunk++;
            }
        } catch (Throwable $e) {
            throw static::translateException($e);
        } finally {
            $options->setTtl($previousTtl);
        }

        // Most times we would expect to exit here as $result should be populated with
        // keys that failed. An empty result indicates no failures
        if (empty($result)) {
            return true;
        }

        foreach ($result as $index => $key) {
            if (!$this->storage->hasItem($key)) {
                unset($result[$index]);
            }
        }
        return empty($result);
    }

    /**
     * {@inheritDoc}
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    public function populateMultiple($values, $ttl = null)
    {
        return $this->setMultipleBatched($values, false, $ttl);
    }

    /**
     * {@inheritDoc}
     * @throws Exception
     */
    public function setMultiple($values, $ttl = null)
    {
        return $this->setMultipleBatched($values, true, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple($keys)
    {
        $keys = $this->convertIterableToArray($keys, false, __FUNCTION__);
        if (empty($keys)) {
            return true;
        }

        array_walk($keys, [$this, 'validateKey']);

        try {
            $result = $this->storage->removeItems($keys);
        } catch (Throwable $e) {
            return false;
        }

        if (empty($result)) {
            return true;
        }

        foreach ($result as $index => $key) {
            if (! $this->storage->hasItem($key)) {
                unset($result[$index]);
            }
        }

        return empty($result);
    }

    /**
     * {@inheritDoc}
     */
    public function has($key)
    {
        $this->validateKey($key);

        try {
            return $this->storage->hasItem($key);
        } catch (Throwable $e) {
            throw static::translateException($e);
        }
    }

    /**
     * @param Throwable|Exception $e
     * @return SimpleCacheException
     */
    private static function translateException($e)
    {
        $exceptionClass = $e instanceof LaminasCacheInvalidArgumentException
            ? SimpleCacheInvalidArgumentException::class
            : SimpleCacheException::class;

        return new $exceptionClass($e->getMessage(), $e->getCode(), $e);
    }

    /**
     * Validate that the namespace is of the correct format and length. The returned namespace will be truncated
     * to the correct length if it is otherwise valid
     * @param string    $namespace  namespace to validate
     * @return void|string
     * @throws SimpleCacheInvalidArgumentException if key is invalid
     * @throws ConfigException
     */
    protected function validateNamespace($namespace)
    {
        $namespace = substr($namespace, 0, self::MAX_NAMESPACE_LENGTH);
        $this->validateKey($namespace, self::MAX_NAMESPACE_LENGTH);
        return $namespace;
    }

    /**
     * Validate that the key is of the correct format and length.
     * @param string    $key        key to validate
     * @param int       $maxLength  maximum key length, defaults to MAX_KEY_LENGTH
     * @return void|string
     * @throws SimpleCacheInvalidArgumentException if key is invalid
     * @throws ConfigException
     */
    protected function validateKey($key, $maxLength = self::MAX_KEY_LENGTH)
    {
        if ('' === $key) {
            throw new SimpleCacheInvalidArgumentException(
                'Invalid key provided; cannot be empty'
            );
        }

        if (0 === $key) {
            // cache/integration-tests erroneously tests that ['0' => 'value']
            // is a valid payload to setMultiple(). However, PHP silently
            // converts '0' to 0, which would normally be invalid. For now,
            // we need to catch just this single value so tests pass.
            // I have filed an issue to correct the test:
            // https://github.com/php-cache/integration-tests/issues/92
            return $key;
        }

        if (! is_string($key)) {
            throw new SimpleCacheInvalidArgumentException(
                sprintf(
                    'Invalid key provided of type "%s"%s; must be a string',
                    is_object($key) ? get_class($key) : gettype($key),
                    is_scalar($key) ? sprintf(' (%s)', var_export($key, true)) : ''
                )
            );
        }
        $invalidKeyChars = ConfigManager::getValue($this->config, ConfigManager::REDIS_INVALID_KEY_CHARS);
        if ($invalidKeyChars) {
            $regex = sprintf('/[%s]/', preg_quote($invalidKeyChars, '/'));
            if (preg_match($regex, $key)) {
                throw new SimpleCacheInvalidArgumentException(
                    sprintf(
                        'Invalid key "%s" provided; cannot contain any of (%s)',
                        $key,
                        $invalidKeyChars
                    )
                );
            }
        }
        $split = explode(RedisService::SEPARATOR, $key);
        $key   = isset($split[1]) ? $split[1] : $split[0];
        if (preg_match('/^.{' . (self::MAX_KEY_LENGTH + 1) . ',}/u', $key)) {
            throw new SimpleCacheInvalidArgumentException(
                sprintf(
                    'Invalid key "%s" provided; key is too long. Must be no more than %d characters',
                    $key,
                    $maxLength
                )
            );
        }
    }

    /**
     * Determine if the storage adapter provides per-item TTL capabilities
     *
     * @param StorageInterface $storage
     * @return void
     */
    private function memoizeTtlCapabilities(StorageInterface $storage)
    {
        $capabilities             = $storage->getCapabilities();
        $this->providesPerItemTtl = $capabilities->getStaticTtl() && (0 < $capabilities->getMinTtl());
    }

    /**
     * @param int|DateInterval
     * @return null|int
     * @throws Exception
     */
    private function convertTtlToInteger($ttl)
    {
        // null === absence of a TTL
        if (null === $ttl) {
            return null;
        }

        // integers are always okay
        if (is_int($ttl)) {
            return $ttl;
        }

        // Numeric strings evaluating to integers can be cast
        if (is_string($ttl)
            && ('0' === $ttl
                || preg_match('/^[1-9][0-9]+$/', $ttl)
            )
        ) {
            return (int) $ttl;
        }

        // DateIntervals require conversion
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable('now', $this->utc);
            $end = $now->add($ttl);
            return $end->getTimestamp() - $now->getTimestamp();
        }

        // All others are invalid
        throw new SimpleCacheInvalidArgumentException(
            sprintf(
                'Invalid TTL "%s" provided; must be null, an integer, or a %s instance',
                is_object($ttl) ? get_class($ttl) : var_export($ttl, true),
                DateInterval::class
            )
        );
    }

    /**
     * @param array|iterable $iterable
     * @param bool $useKeys Whether or not to preserve keys during conversion
     * @param string $forMethod Method that called this one; used for reporting
     *     invalid values.
     * @return array
     * @throws SimpleCacheInvalidArgumentException for invalid $iterable values
     */
    private function convertIterableToArray($iterable, $useKeys, $forMethod)
    {
        if (is_array($iterable)) {
            return $iterable;
        }

        if (! $iterable instanceof Traversable) {
            throw new SimpleCacheInvalidArgumentException(
                sprintf(
                    'Invalid value provided to %s::%s; must be an array or Traversable',
                    __CLASS__,
                    $forMethod
                )
            );
        }

        $array = [];
        foreach ($iterable as $key => $value) {
            if (! $useKeys) {
                $array[] = $value;
                continue;
            }

            if (! is_string($key) && ! is_int($key) && ! is_float($key)) {
                throw new SimpleCacheInvalidArgumentException(
                    sprintf(
                        'Invalid key detected of type "%s"; must be a scalar',
                        is_object($key) ? get_class($key) : gettype($key)
                    )
                );
            }
            $array[$key] = $value;
        }
        return $array;
    }
}
