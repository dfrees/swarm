<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis;

use Api\IModelFields;
use Application\Cache\AbstractCacheService;
use Application\Cache\ICacheStatus;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Events\Listener\ListenerFactory;
use Groups\Model\IGroup;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use Projects\Model\IProject;
use Queue\Manager as QueueManager;
use Redis\Model\AbstractDAO;
use Users\Model\IUser;
use Workflow\Model\IWorkflow;

/**
 * Redis Manager for handling operations on the Redis cache (typically coming from API calls)
 * @package Redis\Controller
 */
class Manager extends AbstractCacheService implements InvokableService
{
    const CONTEXT   = 'context';
    const USER_ID   = 'userId';
    const REQUESTER = 'requester';
    const CONTEXTS  = [IUser::USER, IGroup::GROUP, IProject::PROJECT, IWorkflow::WORKFLOW];

    private $services;
    private $translator;
    private $logger;

    /**
     * Redis Manager constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->logger     = $services->get(SwarmLogger::SERVICE);
    }

    /**
     * Delete keys from Redis that signify population.
     * @param string    $key        expected to be 'redis' otherwise this controller is not able to handle
     * @param array     $options    the only supported option is 'context' with a short model key or list of keys.
     *                              Supported values are user, group, project. For example context => 'user' or
     *                              multiple values context => 'user, group' etc. Empty or no context will deleted
     *                              populated status for all model keys (within the namespace)
     * @return array messages
     * @throws InvalidArgumentException if key or context are invalid
     */
    public function delete($key, array $options = []) : array
    {
        $this->validateKey($key);
        $context = isset($options[self::CONTEXT]) ? strtolower($options[self::CONTEXT]) : null;
        $filter  = $this->services->get(Services::REDIS_CACHE_VERIFY);
        try {
            $contexts = $filter->filter($context);
        } catch (InvalidArgumentException $errr) {
            throw $errr;
        }
        $cacheService = $this->services->get(AbstractCacheService::CACHE_SERVICE);
        $keys         = $this->getKeysToDelete($contexts);
        $cacheService->deleteMultiple($keys);
        // delete takes namespace into account but we want to be helpful and include the namespace
        // in the messages
        $namespace = $cacheService->getNamespace();
        foreach ($keys as &$value) {
            $value = $namespace . $value;
        }
        return [$this->translator->t("Deleted key(s) [%s]", [implode(', ', $keys)])];
    }

    /**
     * Create a task in the queue to check the integrity of the cache for a given context.
     *
     * @param string $key       expected to be 'redis' otherwise this controller is not able to handle
     * @param array  $options   The supported options are 'context' with a short model key or list of keys.
     *                          Supported values are user, group, project. For example context => 'user' or
     *                          multiple values context => 'user, group' etc. Empty or no context will trigger
     *                          verify for all the cache.
     * @return array
     */
    public function queueCacheIntegrityTask($key, array $options = []) : array
    {
        $this->validateKey($key);
        $context = isset($options[self::CONTEXT]) ? strtolower($options[self::CONTEXT]) : null;
        $filter  = $this->services->get(Services::REDIS_CACHE_VERIFY);
        try {
            $contexts = $filter->filter($context);
        } catch (InvalidArgumentException $err) {
            throw $err;
        }
        $userId = isset($options[self::USER_ID]) ? strtolower($options[self::USER_ID])
            : $this->services->get(ConnectionFactory::P4_ADMIN)->getUser();
        // Put this into the queue asap so we can get this processed.
        $seconds = 1;
        foreach ($contexts as $eachContext) {
            $dao = $this->services->get($eachContext.'DAO');
            $this->createFutureTask($eachContext, $userId, $seconds);
            $dao->setIntegrityStatus();
        }
        $this->logger->trace("User $userId requested integrity check for cache of $context");
        return [$this->translator->t("Verifying Redis cache for [%s]", [implode(", ", $contexts)])];
    }
    /**
     * Get the status for a given context.
     *
     * @param string $key       expected to be 'redis' otherwise this controller is not able to handle
     * @param array  $options   The supported options are 'context' with a short model key or list of keys.
     *                          Supported values are user, group, project. For example context => 'user' or
     *                          multiple values context => 'user, group' etc. Empty or no context will trigger
     *                          verify for all the cache.
     * @return array
     */
    public function verifyStatus($key, $options)
    {
        $this->validateKey($key);
        $context = isset($options[self::CONTEXT]) ? strtolower($options[self::CONTEXT]) : null;
        $filter  = $this->services->get(Services::REDIS_CACHE_VERIFY);
        try {
            $contexts = $filter->filter($context);
        } catch (InvalidArgumentException $errr) {
            throw $errr;
        }
        $results = [];
        foreach ($contexts as $eachContext) {
            $dao      = $this->services->get($eachContext.'DAO');
            $progress = $dao->getIntegrityStatus();
            $state    = $this->getIntegrityState($progress, $eachContext);
            $this->logger->trace("Status of integrity for $eachContext is $progress with state $state");
            $results[$eachContext] = [IModelFields::STATE => $state, IModelFields::PROGRESS => $progress];
        }
        return $results;
    }

    /**
     * Get the state based on the progress from redis.
     *
     * @param string $progress   This is the string set in redis.
     * @param string $context This is the context we are interested in.
     * @return string
     */
    protected function getIntegrityState($progress, $context)
    {
        $state      = ICacheStatus::STATUS_NOT_QUEUED;
        $hash       = $this->getCacheIntegrityHash($context);
        $queue      = $this->services->get(QueueManager::SERVICE);
        $futureTask = $queue->hasTaskByHash($hash);

        if ($progress) {
            switch ($progress) {
                case AbstractDAO::VERIFY_COMPLETE_MESSAGE:
                case ICacheStatus::STATUS_QUEUED:
                    $state = $futureTask ? ICacheStatus::STATUS_QUEUED : ICacheStatus::STATUS_NOT_QUEUED;
                    break;
                default:
                    $state = ICacheStatus::STATUS_RUNNING;
                    break;
            }
        } else {
            $state = $futureTask ? ICacheStatus::STATUS_QUEUED : $state;
        }
        return $state;
    }
    /**
     * Builds a hash value for the context(s) to be used in the construction of the future task file name.
     *
     * @param string|array $context The context(s) we want build a hash for..
     * @return string the md5 hash
     */
    public function getCacheIntegrityHash($context)
    {
        // salt the hash with 'cache.integrity' and the key
        return hash('md5', ListenerFactory::CACHE_INTEGRITY . $context, false);
    }

    /**
     * Create the future task based on the config value.
     *
     * @param string $context The context we want to create a future task for.
     * @param string $userId  The user that we report that has created the task.
     * @param int    $seconds The number of seconds after now we want the task to trigger.
     */
    public function createFutureTask($context, $userId, $seconds)
    {
        $time = time() + $seconds;
        $this->logger->trace("Setting up a future task for $context at $time");
        $queueManager = $this->services->get(QueueManager::SERVICE);
        // Now create a task in the queue for the next worker to pick up.
        $queueManager->addTask(
            ListenerFactory::CACHE_INTEGRITY,
            $context,
            [
                self::REQUESTER => $userId,
                self::CONTEXT   => $context
            ],
            $time,
            $this->getCacheIntegrityHash($context)
        );
    }

    /**
     * Translates contexts (for example [user, group, project] into redis keys to delete
     * @param array|null    $contexts   the contexts
     * @return array keys to delete
     */
    private function getKeysToDelete($contexts)
    {
        $keys = [];
        if ($contexts) {
            foreach ($contexts as $context) {
                $keys[] = $this->getPopulatedKey($context);
            }
        } else {
            foreach (self::CONTEXTS as $validContext) {
                $keys[] = $this->getPopulatedKey($validContext);
            }
        }
        return $keys;
    }

    /**
     * Build a key based on the context. For example 'user' will build a key from the value of
     * POPULATED_STATUS in UserDAO.
     * @param string    $context    the context for example 'user' or 'group'
     * @return mixed
     */
    private function getPopulatedKey($context)
    {
        return constant('Redis\Model\\' . ucfirst($context) . 'DAO::POPULATED_STATUS');
    }

    /**
     * Validates the key is as expected
     * @param string    $key            the key
     * @throws InvalidArgumentException if key is invalid
     */
    private function validateKey($key)
    {
        if (ConfigManager::REDIS !== $key) {
            throw new InvalidArgumentException(
                $this->translator->t(
                    "Redis Manager called with key [%s], the controller only handles [%s]",
                    [$key, ConfigManager::REDIS]
                )
            );
        }
    }
}
