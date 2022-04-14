<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis;

use Application\Cache\AbstractCacheService;
use Application\Factory\InvokableService;
use Application\Lock\ILock;
use Application\Log\SwarmLogger;
use Interop\Container\ContainerInterface;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\TimeoutException;
use malkusch\lock\mutex\PHPRedisMutex;
use Exception;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

/**
 * Class LockService
 * @package Redis
 */
class LockService implements ILock, InvokableService
{
    // Redis API
    private $redis    = null;
    private $services = null;
    private $logger   = null;

    /**
     * LockService constructor.
     *
     * Creates a new LockService
     *
     * @param ContainerInterface $services
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        try {
            $this->services = $services;
            $cacheService   = $services->get(AbstractCacheService::CACHE_SERVICE);
            $this->redis    = $cacheService->getRedisResource();
            $this->logger   = $services->get(SwarmLogger::SERVICE);
        } catch (ServiceNotCreatedException $e) {
            // Ignore, cache not available
        }
    }

    /**
     * @inheritDoc
     *
     * This implementation is based on whether or not the $mutexName is set in the Redis cache or not.
     * If a lock is not acquired, this will return false.
     * If the code runs beyond the mutex timeout, an error will be logged but whatever the code returns will still
     * be returned.
     * For more information see See: https://github.com/php-lock/lock
     *
     * @param string    $mutexKey
     * @param callable  $code
     * @param int       $timeout
     *
     * @return bool|false|mixed|null
     *
     * @throws LockAcquireException
     * @throws LockReleaseException
     * @throws TimeoutException
     * @throws Exception
     */
    public function lock($mutexKey, callable $code, $timeout = 3)
    {
        if (is_null($this->redis)) {
            return false;
        }

        $mutex = new PHPRedisMutex([$this->redis], $mutexKey, $timeout);

        try {
            return $mutex->synchronized($code);
        } catch (LockReleaseException $e) {
            // This is very bad. This means that the lock was not released.
            $this->logError($e);
            throw $e;
        } catch (TimeoutException $e) {
            // Here the lock was never acquired due to blocking
            $this->logError($e);
            throw $e;
        } catch (LockAcquireException $e) {
            // Here the lock was not acquired for some reason
            $this->logError($e);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     *
     * This implementation is based on whether or not the $mutexName is set in the Redis cache or not.
     * If a lock is not acquired because the check fails or there is some other issue, this returns false.
     * If the code runs beyond the mutex timeout, an error will be logged but whatever the code returns will still
     * be returned.
     * For more information see See: https://github.com/php-lock/lock
     *
     * @param string    $mutexKey
     * @param callable  $check
     * @param callable  $code
     * @param int       $timeout
     *
     * @return false|null
     *
     * @throws LockAcquireException
     * @throws LockReleaseException
     * @throws TimeoutException
     */
    public function lockWithCheck($mutexKey, callable $check, callable $code, $timeout = 3)
    {
        if (is_null($this->redis)) {
            return false;
        }

        $mutex = new PHPRedisMutex([$this->redis], $mutexKey, $timeout);

        try {
            $mutex->check($check)->then($code);
        } catch (LockReleaseException $e) {
            // This is very bad. This means that the lock was not released
            $this->logError($e);
            throw $e;
        } catch (TimeoutException $e) {
            // Here the lock was never acquired due to blocking
            $this->logError($e);
            throw $e;
        } catch (LockAcquireException $e) {
            // Here the lock was not acquired for some reason
            $this->logError($e);
            throw $e;
        }
    }

    /**
     * Logs an exception and any previous exceptions
     *
     * @param Exception $e
     */
    protected function logError(Exception $e)
    {
        if ($e->getMessage()) {
            $this->logger->err($e->getMessage());
        } else {
            $this->logger->err($e->getTraceAsString());
        }
        if ($e->getPrevious()) {
            $this->logError($e->getPrevious());
        }
    }
}
