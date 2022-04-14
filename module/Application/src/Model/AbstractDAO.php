<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Model;

use Application\Cache\ICacheStatus;
use Application\Exception\NotImplementedException;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Permissions\ConfigCheck;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface;
use P4\Spec\PluralAbstract;

/**
 * Abstract DAO for other cached based DAO implementations to use.
 * @package Application\Model
 */
abstract class AbstractDAO implements InvokableService, IModelDAO, ICacheStatus
{
    protected $services;
    protected $logger;
    protected $lowercase;
    // Concrete implementations should provide this
    const CACHE_KEY_PREFIX = '';
    // Concrete implementations should provide this
    const MODEL = PluralAbstract::class;
    // Concrete implementations should provide this
    const SEARCH_STARTS_WITH_KEY = '';
    // Concrete implementations should provide this
    const SEARCH_INCLUDES_KEY = '';

    const NOT_IMPLEMENTED_MESSAGE = 'This method is not implemented.';

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services  = $services;
        $this->logger    = $services->get(SwarmLogger::SERVICE);
        $this->lowercase = function_exists('mb_strtolower')
            ? function ($string) {
                return mb_strtolower($string, 'UTF-8');
            }
            : function ($string) {
                return strtolower($string);
            };
    }

    /**
     * @inheritDoc
     */
    public function existIds(array $ids, ConnectionInterface $connection = null): array
    {
        $existing = [];
        foreach ($ids as $id) {
            if ($this->exists($id, $connection)) {
                $existing[] = $id;
            }
        }
        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function exists($id, ConnectionInterface $connection = null)
    {
        return call_user_func(static::MODEL . '::' . __FUNCTION__, $id, $connection);
    }

    /**
     * @inheritDoc
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        // By default this simply passes on to model. Implementations need to do their own thing
        return call_user_func(static::MODEL  . '::' . __FUNCTION__, $options, $this->getConnection($connection));
    }

    /**
     * @inheritDoc
     */
    public function fetch($id, ConnectionInterface $connection = null)
    {
        return $this->fetchById($id, $connection);
    }

    /**
     * @inheritDoc
     */
    public function fetchByIdUnrestricted($id, ConnectionInterface $connection = null)
    {
        return call_user_func(static::MODEL  . '::fetch', $id, $connection);
    }

    /**
     * @inheritDoc
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        return call_user_func(static::MODEL  . '::' . __FUNCTION__, $id, $connection);
    }

    /**
     * @inheritDoc
     */
    public function save($model)
    {
        return $model->save();
    }

    /**
     * @inheritDoc
     */
    public function delete($model)
    {
        return $model->delete();
    }

    /**
     * Set the connection on the model if provided
     * @param mixed                     $model      the model
     * @param ConnectionInterface|null  $connection the connection
     * @return mixed the model
     */
    protected function setConnection($model, ConnectionInterface $connection = null)
    {
        $connection = $this->getConnection($connection);
        return $model->setConnection($connection);
    }

    /**
     * Gets a connection
     * @param ConnectionInterface   $connection   the connection
     * @return ConnectionInterface if the connection is not null is it simply returned, otherwise the default connection
     * from the Perforce model is returned
     */
    protected function getConnection(ConnectionInterface $connection = null)
    {
        return $connection ? $connection : call_user_func(static::MODEL . '::getDefaultConnection');
    }

    /**
     * Tests the connection (or the default connection if not set) and builds a case sensitive id
     * if appropriate.
     * @param string                    $id         the id.
     * @param ConnectionInterface|null  $connection the connection.
     * @return mixed
     */
    protected function getCaseSpecificId($id, ConnectionInterface $connection = null)
    {
        $connection = $this->getConnection($connection);
        if ($connection->isCaseSensitive()) {
            return $id;
        } else {
            $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
            return $lower($id);
        }
    }


    /**
     * A convenience method to filter all invalid/non-existent user ids from a passed list.
     *
     * @param   array|string        $ids      one or more model ids to filter for validity
     * @param   ConnectionInterface $connection optional - a specific connection to use.
     * @param   array               $excludeList  optional list of ids to exclude.
     * @return  array               the filtered result
     */
    public function filter($ids, ConnectionInterface $connection = null, $excludeList = [])
    {
        $connection    = $this->getConnection($connection);
        $caseSensitive = $connection->isCaseSensitive();

        // we don't want user ids which contain wildcards, isValidId
        // should remove these and any other wacky input values
        foreach ($ids as $key => $id) {
            $isExcluded = ConfigCheck::isExcluded($id, $excludeList, $caseSensitive);

            if ($isExcluded || !call_user_func(static::MODEL  . '::isValidId', $id, $connection)) {
                unset($ids[$key]);
            }
        }
        // if, after filtering, we have no users; simply return
        if (!$ids) {
            return $ids;
        }
        // leverage fetchAll to do the heavy lifting
        return $this->fetchAll(
            [constant(static::MODEL . '::FETCH_BY_NAME') => $ids],
            $connection
        )->invoke('getId');
    }

    /**
     * Converts the id to a normalized value.
     * @param string                $id         the id
     * @param ConnectionInterface   $connection connection details
     * @return string the normalized id. By default the id is returned unchanged, sub-classes can implement
     * different behaviour as required
     */
    protected function normalizeId($id, ConnectionInterface $connection = null)
    {
        return $id;
    }

    /**
     * @inheritDoc
     */
    public function queueTask($model, $data = null)
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        throw new NotImplementedException($translator->t(self::NOT_IMPLEMENTED_MESSAGE));
    }

    /**
     * @inheritDoc
     * @throws NotImplementedException
     */
    public function getIntegrityStatus()
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        throw new NotImplementedException($translator->t(self::NOT_IMPLEMENTED_MESSAGE));
    }

    /**
     * @inheritDoc
     * @throws NotImplementedException
     */
    public function setIntegrityStatus($status = self::STATUS_QUEUED)
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        throw new NotImplementedException($translator->t(self::NOT_IMPLEMENTED_MESSAGE));
    }
}
