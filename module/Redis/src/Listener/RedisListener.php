<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Listener;

use Api\Controller\ICacheController;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Helper\DateTimeHelper;
use Events\Listener\AbstractEventListener;
use InvalidArgumentException;
use Queue\Manager as QueueManager;
use Redis\Manager as RedisManager;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class RedisListener extends AbstractEventListener
{
    protected $queue        = null;
    protected $redisService = null;
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->queue        = $this->services->get(QueueManager::SERVICE);
        $this->redisService = $this->services->get(ICacheController::REDIS_CACHE);
    }

    /**
     * Check if the integrity has be done and issue a new one for the future.
     * Trigger event early (500) before others to ensure the data is valid.
     *
     * @param Event $event
     * @return void
     * @throws ConfigException
     */
    public function shouldVerifyCacheIntegrity(Event $event)
    {
        parent::log($event);
        if ($event->getParam('slot') !== 1 && $this->queue->getWorkerCount() > 1) {
            return;
        }
        $this->createFutureVerifyTask(RedisManager::CONTEXTS);
    }

    /**
     * Build the cache integrity and update any records that are missing or incorrect.
     * At present this is for users, groups, projects and workflow.
     *
     * @param Event $event
     * @return void
     * @throws ConfigException
     */
    public function cacheIntegrity(Event $event)
    {
        parent::log($event);
        $id     = $event->getParam('id');
        $filter = $this->services->get(Services::REDIS_CACHE_VERIFY);
        try {
            $context = $filter->filter($id)[0];
        } catch (InvalidArgumentException $err) {
            $this->logger->trace("cacheIntegrity failed to verify context $id");
            return;
        }
        $this->logger->trace("Trigger cache verify for $context");
        $dao = $this->services->get($context . 'DAO');
        $dao->verify();
        $this->logger->trace("Finished cache verify for $context");
        // Now we have finished verify we should create a future task queue for the future.
        $this->createFutureVerifyTask([$context]);
    }

    /**
     * Get all the required data and setup a future task for the contexts.
     *
     * @param array $contexts  The list of context that we want to deal with.
     * @throws ConfigException
     */
    protected function createFutureVerifyTask($contexts)
    {
        $configValue   =  ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::REDIS_CHECK_INTEGRITY
        );
        $p4AdminUserID = $this->services->get(ConnectionFactory::P4_ADMIN)->getUser();
        $seconds       = DateTimeHelper::getIntervalInSeconds($configValue);
        // If the seconds is zero we are disabled and don't want future task created.
        if ($seconds === 0) {
            return;
        }
        foreach ($contexts as $context) {
            $hash    = $this->redisService->getCacheIntegrityHash($context);
            $entries = $this->queue->getTasksByHash($hash);
            if (empty($entries)) {
                $this->logger->trace(
                    "Found no future tasks for $context, queueing task for $seconds seconds in the future."
                );
                $this->redisService->createFutureTask($context, $p4AdminUserID, $seconds);
            }
        }
    }
}
