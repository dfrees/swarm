<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

use Application\Cache\AbstractCacheService;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Helper\ArrayHelper;
use Application\Lock\ILock;
use Application\Log\SwarmLogger;
use Application\Model\AbstractDAO as ApplicationDAO;
use Application\Model\IModelDAO;
use Application\Permissions\ConfigCheck;
use Closure;
use Exception;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface;
use P4\Log\Logger;
use P4\Model\Fielded\Iterator;
use P4\Spec\PluralAbstract;
use Record\Key\AbstractKey;
use Redis\Model\IModelDAO as RedisIModelDAO;
use Redis\RedisService;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

/**
 * Abstract DAO for other redis cached based DAO implementations to use.
 * @package Redis\Model
 */
abstract class AbstractDAO extends ApplicationDAO implements RedisIModelDAO
{
    // This is the default PHP 'max_execution_time' that is applied when no value is set.
    // See: https://doc.bccnsoft.com/docs/php-docs-7-en/function.set-time-limit.html
    const DEFAULT_MAX_EXECUTION_TIME = 30;

    // Key and value used to put into cache.
    const POPULATED_STATUS = "populated-status";
    const POPULATED        = 1;
    const UNPOPULATED      = 0;
    const SCAN_PAGE_SIZE   = 1000;
    // When populating null means no maximum by default
    const FETCH_MAXIMUM = null;
    // Model code
    const MODEL = '';
    // The key for the verify status of the user dataset
    const VERIFY_STATUS = "verify-status";
    // Default maximum number of seconds to hold a lock
    const DEFAULT_POPULATION_LOCK_TIMEOUT = 300;
    // The template message for verification progress
    const VERIFY_PROGRESS = "Step %d of %d: %s";
    // The steps performed during verification
    const VERIFY_GET_REDIS_KEYS       = 'getRedisKeys';
    const VERIFY_GET_REDIS_CHECKSUMS  = 'getRedisChecksums';
    const VERIFY_GET_SERVER_CHECKSUMS = 'getServerChecksums';
    const VERIFY_REMOVE_EXTRA_KEYS    = 'removeExtraneousRedisKeys';
    const VERIFY_ADD_MISSING_KEYS     = 'addMissingModelKeys';
    // The progress message for all done
    const VERIFY_COMPLETE_MESSAGE = 'Verification complete';

    // Search constants used to build search keys
    const SEARCH_STARTS_WITH = 'starts_with';
    const SEARCH_INCLUDES    = 'includes';

    // fetchAll option to perform a search
    const FETCH_SEARCH = 'search';
    // Params used in searching via fetchAll
    const SEARCH_TERM               = 'term';
    const SEARCH_LIMIT              = 'limit';
    const SEARCH_RETURN_RAW_ENTRIES = 'returnRawEntries';
    const SEARCH_STARTS_WITH_ONLY   = 'startsWithOnly';
    const SEARCH_EXCLUDE_LIST       = 'excludeList';

    /**
     * AbstractDAO constructor. If the redis server does not have the populated status set we will rebuild the cache to
     * ensure it is up to date
     *
     * @param ContainerInterface $services
     * @param array|null         $options
     *
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        parent::__construct($services, $options);
        // Get the server and check the current status or population.
        $this->populate();
    }

    /**
     * Check the the Id exists in the Cache first then fall back to the parent.
     *
     * @inheritDoc
     */
    public function exists($id, ConnectionInterface $connection = null)
    {
        $cacheService = $this->getCache();
        $multiple     = is_array($id);
        $exists       = false;
        $valid        = [];
        $connection   = $this->getConnection($connection);
        // Deal with arrays as well as single ids
        foreach ((array)$id as $id) {
            $id = $this->normalizeId($id, $connection);
            if ($cacheService) {
                $exists = $cacheService->has(static::CACHE_KEY_PREFIX . $id);
            }
            // Also check the Perforce server; cache may not be available or spec only exists in server
            if (!$exists) {
                $exists = parent::exists($id, $connection);
            }
            if ($exists) {
                $valid[] = $id;
            }
        }
        return $multiple ? $valid : $exists;
    }

    /**
     * Check the cache for the records and return if exists. Otherwise fall back to the parent.
     *
     * @inheritDoc
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        $connection   = $this->getConnection($connection);
        $cacheService = $this->getCache();
        $model        = null;
        // normalizeId for the User class will lowercase the id. This is correct
        // but we do not want to pass a lowercase id to parent::fetchById as in
        // PluralAbstract it will return a sparsely populated model with that
        // identifier set on it and we do not want the model to have the identifier
        // changed. 'exists' in model/User (which parent::fetchById calls) will
        // still work correctly as it uses lowercase id to query if the server
        // is case insensitive (Jira SW-6822)
        $nid = $this->normalizeId($id, $connection);
        if ($cacheService) {
            $model = $cacheService->get(static::CACHE_KEY_PREFIX . $nid);
            $model = $model ? $model : parent::fetchById($id, $connection);
        } else {
            $model = parent::fetchById($id, $connection);
        }
        return $model ? $this->setConnection($model, $connection) : null;
    }

    /**
     * Fetch all the records for the given model from the cache, otherwise fall back to Perforce fetchAll
     *
     * @inheritDoc
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        // Get the fetch by constant from the model. Most use ids
        $fetchBy  = constant(static::MODEL . '::FETCH_BY_IDS');
        $options += [
            PluralAbstract::FETCH_MAXIMUM => null,
            IModelDAO::FETCH_NO_CACHE     => null,
            $fetchBy                      => null,
            self::FETCH_SEARCH            => null
        ];

        // If FETCH_NO_CACHE is specified, just return from the parent
        if ($options[IModelDAO::FETCH_NO_CACHE]) {
            return parent::fetchAll($options, $connection);
        }

        // Deal with the case of not having a cache service
        $cacheService = $this->getCache();
        if (is_null($cacheService)) {
            if (isset($options[self::FETCH_SEARCH])) {
                // There's no cache service and we've specified a search - we have to return an empty array
                return [];
            } else {
                // Otherwise we return from the parent
                return parent::fetchAll($options, $connection);
            }
        }

        // If we are doing a search, just return the output of the search method
        if (isset($options[self::FETCH_SEARCH])) {
            return $this->search($options);
        }

        // From here we know there is a cache and we're supposed to use it
        $models = new Iterator();
        $max    = $options[PluralAbstract::FETCH_MAXIMUM];
        // If we are not fetching by ID/name we do the normal scan
        if (is_null($options[$fetchBy])) {
            $keys = $this->getKeysFromCache($cacheService, $max);
        } else {
            // If we are fetching by NAME/ID we should just use get multiple for them.
            // This is faster than doing a scan though the full system.
            $ids  = (array) $options[$fetchBy];
            $keys = array_map(
                function ($id) use ($connection) {
                    return static::CACHE_KEY_PREFIX.$this->normalizeId($id, $connection);
                },
                $ids
            );
        }
        if (count($keys) > 0) {
            foreach ($cacheService->getMultiple($keys) as $key => $model) {
                if ($model) {
                    $models[$model->getId()] = $this->setConnection($model, $connection);
                }
            }
        }
        return $models;
    }

    /**
     * Ensure that the Redis cache data matches that in the Perforce server
     *
     * This is done in 5 steps:
     *   - 1) Getting the raw data from redis
     *   - 2) Getting the serialized version of the models
     *   - 3) Comparing the md5sums of the results
     *   - 4) Removing any keys which no longer have matching server data
     *   - 5) Caching any models which are not present
     *
     * Note that updated data may be deleted then added.
     */
    public function verify()
    {
        $this->logLine(Logger::DEBUG, __LINE__, "Verifying the Redis cache contents");
        $cache = $this->getCache();

        // Get checksums for the cache and the server
        $redisKeyChecksums      = $this->getRedisKeyChecksums($cache);
        $perforceModelChecksums = $this->getServerKeyChecksums();
        $this->logLine(
            Logger::TRACE,
            __LINE__,
            'Redis has (' . count($redisKeyChecksums) . ') keys.'.
            'Swarm has (' . count($perforceModelChecksums) . ') models, '
        );

        // Update the cache with any discrepancies
        $this->deleteExtraneousRedisKeys($cache, $redisKeyChecksums, $perforceModelChecksums);
        $this->addMissingRedisKeys($redisKeyChecksums, $perforceModelChecksums);

        // All done
        $this->setIntegrityStatus(static::VERIFY_COMPLETE_MESSAGE);
        $this->logLine(Logger::DEBUG, __LINE__, static::VERIFY_COMPLETE_MESSAGE);
    }

    /**
     * Fetch the model by id and set it in the cache (without calling save on the model). Used to update the cache
     * when a new record has been added to perforce. The item will first be removed from the cache so that if it
     * has been deleted the fetch will not repopulate the cache.
     * @param string    $id     the id of the record, for example a user id. Cache key prefixes will be
     *                          used as per the implementing class
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed the model
     */
    public function fetchByIdAndSet($id, ConnectionInterface $connection = null)
    {
        // normalizeId for the User class will lowercase the id. This is correct
        // but we do not want to pass a lowercase id to parent::fetchById as in
        // PluralAbstract it will return a sparsely populated model with that
        // identifier set on it and we do not want the model to have the identifier
        // changed. 'exists' in model/User (which parent::fetchById calls) will
        // still work correctly as it uses lowercase id to query if the server
        // is case insensitive (SW-6822)
        $nid          = $this->normalizeId($id);
        $model        = null;
        $cacheService = $this->getCache();
        $cacheService->delete(static::CACHE_KEY_PREFIX . $nid);
        $this->deleteFromSet($nid, $cacheService);
        try {
            $model = $this->fetchById($id, $connection);
            $cacheService->set(static::CACHE_KEY_PREFIX . $nid, $model);
            $this->addToSet([static::CACHE_KEY_PREFIX . $nid]);
            $this->populateSearchKeys($cacheService, [$model]);
        } catch (Exception $e) {
            // Since we are here there is no model, so we need to remove the search entry by id
            $this->removeSearchEntriesById($cacheService, $id);
            $this->services->get(SwarmLogger::SERVICE)->debug("$nid removed from the cache, fetchById found no record");
        }
        return $model ? $this->setConnection($model, $connection) : null;
    }

    /**
     * @inheritDoc
     *
     * @return bool/null
     * @throws ConfigException
     */
    public function invalidate()
    {
        $result       = [];
        $cacheService = $this->getCache();

        $invalidate = function () use ($cacheService, &$result) {
            $this->deleteKeysForPrefix();
            $result[] = $cacheService->set(static::POPULATED_STATUS, self::UNPOPULATED);
        };

        // Ensure we don't run invalidate unless the cache is already populated
        $this->runUnderPopulateLock($cacheService, $invalidate, true);
        return empty($result) ? false : $result[0];
    }

    /**
     * @inheritDoc
     *
     * @return bool/null
     * @throws ConfigException
     */
    public function populate()
    {
        $result       = [];
        $cacheService = $this->getCache();

        // Callback to do the actual population
        $populate = function () use ($cacheService, &$result) {
            $this->deleteKeysForPrefix();
            $keyCount = 0;
            $lastSeen = null;
            // Fail back to the standard fetchAll function.
            do {
                $options = [
                    AbstractKey::FETCH_MAXIMUM => static::FETCH_MAXIMUM,
                    AbstractKey::FETCH_AFTER => $lastSeen
                ];
                $this->logger->debug(
                    "Redis/AbstractDAO::populate: fetchAll on "
                    . static::MODEL
                    . " with options [" . var_export($options, true) . "]"
                );
                $models = call_user_func(
                    static::MODEL . '::fetchAll',
                    $options,
                    $this->services->get(ConnectionFactory::P4_ADMIN)
                );

                // Populate Redis search keys
                $this->populateSearchKeys($cacheService, $models, false);

                // Now build the cache with all the model object to be cached.
                $modelKeys = $this->generateModelKeys($models, true);
                $keyCount += count($modelKeys);
                // Push cache items into the cache for the fetch
                $result[] = $cacheService->populateMultiple($modelKeys);
                $end      = $models->last();
                $lastSeen = $end === false ? $end : $end->getId();
            } while (static::FETCH_MAXIMUM && $models && count($models) > 0);
            // setting the populated status key.
            $cacheService->set(static::POPULATED_STATUS, self::POPULATED);
            $this->logger->debug("Redis/AbstractDAO::populate: Saved $keyCount Redis keys for " . static::MODEL);
        };

        // Only populate if the cache is not already populated and it's not in the process of being populated
        $errorLevel = error_reporting();
        try {
            // Suppress warnings during population. If this is not done a user visiting a page that
            // triggers a populate will get a warning trace and 'Unexpected token < in JSON' if
            // warning but not an error occurs
            error_reporting(E_ERROR);
            $this->runUnderPopulateLock($cacheService, $populate, false);
        } finally {
            // Restore previous reporting level
            error_reporting($errorLevel);
        }
        return empty($result) ? false : $result[0];
    }

    /**
     * Searches for matches within the Redis search sets
     * The search strategy implemented here, depends upon a set of entries being in the following form:
     * - For users:  <lowercase fullname><separator><lowercase username><separator><fullname><separator><username>
     * - For groups: <lowercase groupName><separator><lowercase groupId><separator><groupName><groupId><separator>
     * In this way, we can do a case-insensitive search for a term matching on an id or a name
     *
     * We first find records starting with the given term. If $startsWithOnly is true, we end the search there.
     * Otherwise, if we haven't reached our limit, we go on to search for records including the given term
     * If $startsWithOnly is false, we format the results as arrays with model keys. Otherwise we return raw entries
     * Note: We are not returning full models but just ids and names.
     *
     * @param array     $options    options for searching
     * @param mixed     $connection connection
     * @return array|null
     */
    protected function search(array $options, ConnectionInterface $connection = null)
    {
        // It's a noop if the DAO doesn't support searching,
        if (strlen(static::SEARCH_STARTS_WITH_KEY) === 0) {
            return [];
        }

        // Parse the options
        $searchOptions    = $options[self::FETCH_SEARCH];
        $term             = $searchOptions[self::SEARCH_TERM];
        $limit            = (int)($searchOptions[self::SEARCH_LIMIT] ?? 0);
        $returnRawEntries = (bool)($searchOptions[self::SEARCH_RETURN_RAW_ENTRIES] ?? false);
        $startsWithOnly   = (bool)($searchOptions[self::SEARCH_STARTS_WITH_ONLY] ?? false);
        $excludeList      = $searchOptions[self::SEARCH_EXCLUDE_LIST] ?? [];

        // This will throw argument exceptions if the term and the limit are not valid
        $this->validateSearchOptions($term, $limit);

        // Set the term to lowercase
        $term = ArrayHelper::lowerCase($term);

        // First do the faster, starts with search
        $startsWithResults = $this->searchStartsWith($term, $limit, $returnRawEntries);
        $numResults        = count($startsWithResults);

        // If we have already filled up our limit with the starts with search than we are done
        if ($startsWithOnly || ($limit > 0 && $numResults >= $limit)) {
            $results = $startsWithResults;
        } else {
            // Finish filling up any remaining limit with the slower, includes search
            $includesResults = $this->searchIncludes($term, $limit - $numResults, $returnRawEntries);
            $results         = array_unique(array_merge($startsWithResults, $includesResults));
        }

        $this->removeExcludedItems($results, $excludeList);
        return $returnRawEntries ? $results : $this->formatSearchResults($results);
    }

    /**
     * Throws invalid argument exceptions if the given term and limit aren't valid
     * @param mixed $term
     * @param mixed $limit
     */
    protected function validateSearchOptions($term, $limit)
    {
        // It's a noop if the search term is missing or contains a separator or the limit is negative
        if (!is_string($term) || $term === '') {
            throw new \InvalidArgumentException('The search term must be a non-empty string');
        }

        $full = RedisService::SEARCH_FULL_SEPARATOR;
        $part = RedisService::SEARCH_PART_SEPARATOR;
        if (strpos($term, $full) !== false || strpos($term, $part) !== false) {
            throw new \InvalidArgumentException("The search term cannot contain: $full or $part.");
        }

        if (!is_int($limit) || $limit < 0) {
            throw new \InvalidArgumentException('The limit must be a non-negative integer.');
        }
    }

    /**
     * Searches for records starting with the given term
     * This implementation uses ZRANGEBYLEX over Redis sorted sets of strings.
     * It is relatively fast, having O(log(N)) time complexity
     *
     * @param string   $term               term to search for
     * @param int      $limit              max number of results to return, 0 for unlimited
     * @param bool     $returnRawEntries   optionally, return the the records in their stored form
     *
     * @return array
     */
    protected function searchStartsWith($term, $limit, $returnRawEntries = false)
    {
        // Get the appropriate Redis sorted set key
        $cache = $this->getCache();
        $redis = $cache->getRedisResource();
        $key   = $cache->getNamespace() . static::SEARCH_STARTS_WITH_KEY;

        // Determine the number of entries in the set and add 50, to account for any new entries that may get added
        // while we are conducting our search.
        $numEntries = $redis->zCard($key) + 50;

        // Get, sort & truncate the results
        // Redis ZRANGEBYLEX is applied on sorted sets to get values that fall between a minimum and maximum
        //lexicographic range. In our case, the minimum will be the search term. To derive tha maximum of the range we
        // start with the search term and then append '\xFF' which is the hex code for the largest possible character.
        // The '[' is  just the format required by the method.
        $matches = $redis->zRangeByLex($key, "[$term", "[$term\xFF", 0, $numEntries);
        $matches = $returnRawEntries ? $matches : $this->extractSearchResults($matches);
        $matches = array_unique($matches);
        sort($matches);
        return $limit > 0 ? array_slice($matches, 0, $limit) : $matches;
    }

    /**
     * Searches for records including the given term
     * This implementation uses SSCAN over Redis sets of strings
     * It is relatively slow, having O(N) time complexity
     *
     * @param string   $term               term to search for
     * @param int      $limit              max number of results to return, 0 for unlimited
     * @param bool     $returnRawEntries   optionally, return the the records in their stored form
     *
     * @return array
     */
    protected function searchIncludes(string $term, int $limit, $returnRawEntries = false)
    {
        // Get the appropriate Redis set key
        $cache = $this->getCache();
        $redis = $cache->getRedisResource();
        $key   = $cache->getNamespace() . static::SEARCH_INCLUDES_KEY;

        // Determine the number of entries in the set and add 50, to account for any new entries that may get added
        // while we are conducting our search.
        $numEntries = $redis->sCard($key) + 50;

        // Get, sort & truncate the results. Note we wrap the search term in '*'s to match anywhere in the record.
        $cursor  = null;
        $matches = $redis->sScan($key, $cursor, "*$term*", $numEntries);
        $matches = $returnRawEntries ? $matches : $this->extractSearchResults($matches);
        sort($matches);
        return $limit > 0 ? array_slice($matches, 0, $limit) : $matches;
    }

    /**
     * Converts the search matches from strings into model-appropriate, associative arrays
     * To be overridden in the DAO models that use search
     * @param array $matches
     * @return array|null
     */
    protected function formatSearchResults(array $matches)
    {
        return [];
    }

    /**
     * Filters out any excluded items
     * @param array   $results          search results
     * @param array   $excludeList      list of excluded ids
     * @return |null
     */
    protected function removeExcludedItems(array &$results, $excludeList)
    {
        // This is a noop
        if (empty($excludeList)) {
            return;
        }

        $connection    = $this->getConnection();
        $caseSensitive = $connection->isCaseSensitive();
        $toRemove      = [];

        foreach ($results as $result) {
            $id = explode(RedisService::SEARCH_PART_SEPARATOR, $result)[1];
            if (ConfigCheck::isExcluded($id, $excludeList, $caseSensitive)) {
                $toRemove[] = $result;
            }
        }

        $results = array_diff($results, $toRemove);
    }

    /**
     * Populates the includes & starts_with search sets, optionally deleting previously existing entries for consistency
     * @param mixed           $cacheService   cache service
     * @param Iterator|array  $models         Perforce models
     * @param bool            $deleteFirst    whether to delete entries first
     */
    protected function populateSearchKeys($cacheService, $models, $deleteFirst = true)
    {
        try {
            $searchEntries = $this->buildSearchEntries($models);
            if ($searchEntries) {
                if ($deleteFirst) {
                    foreach ($models as $model) {
                        if ($model->getId()) {
                            $this->removeSearchEntriesById($cacheService, $model->getId());
                        }
                    }
                }

                $redis      = $cacheService->getRedisResource();
                $namespace  = $cacheService->getNamespace();
                $searchKeys = $this->getSearchKeys($namespace);

                // Populate the starts with sorted set
                $zEntries = [];
                $entries  = $searchEntries[self::SEARCH_STARTS_WITH];

                // Here we are using a sorted set (ZSET), which requires us to assign a rank to each member.
                // In a ZSET, when all members are assigned the same rank, the sorting defaults to a lexicographical
                // ordering, which allows us to search a starts with sorted set faster than a regular set.
                // So, in our zAdd call, we need to insert a rank of 0 before each entry
                foreach ($entries as $entry) {
                    $zEntries[] = 0;
                    $zEntries[] = $entry;
                }
                $redis->zAdd($searchKeys[self::SEARCH_STARTS_WITH], ...$zEntries);

                // Populate the includes set
                $redis->sAdd($searchKeys[self::SEARCH_INCLUDES], ...$searchEntries[self::SEARCH_INCLUDES]);
            }
        } catch (\Exception $e) {
            $this->logger->err($e);
        }
    }

    /**
     * Removes all search entries associated with the given model
     * @param mixed            $cacheService   cache service
     * @param Iterator|array   $model          Perforce model or array thereof
     */
    protected function removeSearchEntriesByModel($cacheService, $model)
    {
        try {
            $searchEntries = $this->buildSearchEntries([$model]);
            if (isset($searchEntries)) {
                $this->removeSearchEntries($cacheService, $searchEntries);
            }
        } catch (\Exception $e) {
            $this->logger->err($e);
        }
    }

    /**
     * Removes all search keys entries associated with the given model id
     * @param mixed    $cacheService   cache service
     * @param string   $id             Perforce model id
     */
    protected function removeSearchEntriesById($cacheService, string $id)
    {
        try {
            $searchEntries = $this->findSearchEntriesById($id);
            if (isset($searchEntries)) {
                $this->removeSearchEntries($cacheService, $searchEntries);
            }
        } catch (\Exception $e) {
            $this->logLine(
                Logger::ERR,
                __LINE__,
                sprintf("Failed to remove search entry with id [%s], error is [%s]", $id, $e->getMessage())
            );
            $this->logLine(Logger::ERR, __LINE__, $e->getTraceAsString());
        }
    }

    /**
     * Removes the given search entries from the includes and the starts_with sets
     * @param mixed    $cacheService   cache service
     * @param array   $searchEntries   entries to be removed
     */
    protected function removeSearchEntries($cacheService, array $searchEntries)
    {
        $redis      = $cacheService->getRedisResource();
        $namespace  = $cacheService->getNamespace();
        $searchKeys = $this->getSearchKeys($namespace);
        $redis->zRem($searchKeys[self::SEARCH_STARTS_WITH], ...$searchEntries[self::SEARCH_STARTS_WITH]);
        $redis->sRem($searchKeys[self::SEARCH_INCLUDES], ...$searchEntries[self::SEARCH_INCLUDES]);
    }

    /**
     * Returns the non-lowercase part of the matched search entries
     * @param array $matches
     * @return array
     */
    protected function extractSearchResults(array $matches)
    {
        $results = [];
        foreach ($matches as $match) {
            $results[] = explode(RedisService::SEARCH_FULL_SEPARATOR, $match)[1];
        }
        return $results;
    }

    /**
     * Constructs set members based off of the models that can be used for searching
     * To be overridden in the DAO models that use search
     * @param Iterator|array $models
     * @return array|null
     */
    protected function buildSearchEntries($models)
    {
        return null;
    }

    /**
     * Whether an entry should be included in the search entries when being built, defaults to true
     * @param mixed     $model      model
     * @return bool true if the model is to be included
     */
    protected function includeSearchEntry($model) : bool
    {
        return true;
    }

    /**
     * Gets the value from the model that should be used to build a search entry. Defaults to getName
     * @param mixed     $model      model
     * @return mixed
     */
    protected function getSearchEntryValue($model)
    {
        return $model->getName();
    }

    /**
     * Gets the search entries from the faster starts_with search
     * @param string   $id   Perforce model id
     * @return array|null
     */
    protected function findSearchEntriesById($id)
    {
        // If search is not supported in this DAO instance, we return null
        if (strlen(static::SEARCH_STARTS_WITH_KEY) === 0) {
            return null;
        }

        $options = [
            self::FETCH_SEARCH => [
                self::SEARCH_TERM => $id,
                self::SEARCH_LIMIT => 0,
                self::SEARCH_RETURN_RAW_ENTRIES => true
            ]
        ];
        $matches = $this->search($options);
        foreach ($matches as $match) {
            $parts = explode(RedisService::SEARCH_PART_SEPARATOR, $match);
            if (array_pop($parts) === $id) {
                $results[] = $match;
            }
        }

        // We return an array with duplicate values so it will have the same format as buildSearchEntries or null
        return isset($results)
            ? [
                self::SEARCH_STARTS_WITH => $results,
                self::SEARCH_INCLUDES    => $results
            ]
            : null;
    }

    /**
     * Gets the Redis keys used for searching this DAO instance
     * If the DAO instance doesn't have search keys defined, we just return an empty array
     * @return array
     */
    protected function getSearchKeys($namespace = '')
    {
        $keys = [];
        if (strlen(static::SEARCH_STARTS_WITH_KEY) > 0) {
            return [
                self::SEARCH_STARTS_WITH => $namespace . static::SEARCH_STARTS_WITH_KEY,
                self::SEARCH_INCLUDES    => $namespace . static::SEARCH_INCLUDES_KEY
            ];
        }
        return $keys;
    }

    /**
     * Do the parent delete, then remove keys form redis.
     *
     * @inheritDoc
     */
    public function delete($model)
    {
        $result       = parent::delete($model);
        $cacheService = $this->getCache();
        if ($cacheService) {
            $cacheService->delete(static::CACHE_KEY_PREFIX . $model->getId());
            $this->deleteFromSet($model->getId(), $cacheService);
            $this->removeSearchEntriesById($cacheService, $model->getId());
        }
        return $result;
    }

    /**
     * Get the value of the status.
     *
     * @inheritDoc
     */
    public function getIntegrityStatus()
    {
        $status       = false;
        $cacheService = $this->getCache();
        if ($cacheService->has(static::VERIFY_STATUS)) {
            $status = $cacheService->get(static::VERIFY_STATUS);
        }
        return $status;
    }

    /**
     * Set the value of the status.
     *
     * @inheritDoc
     */
    public function setIntegrityStatus($status = self::STATUS_QUEUED)
    {
        $cacheService = $this->getCache();
        return $cacheService->set(static::VERIFY_STATUS, $status);
    }

    /**
     * Delete the model key from the set
     * @param mixed $modelId        id of the model to delete
     * @param mixed $cacheService   the cache service
     */
    protected function deleteFromSet($modelId, $cacheService)
    {
        if ($cacheService) {
            $cacheService->getRedisResource()->sRem(
                $this->getModelSetValue($cacheService),
                $cacheService->getNamespace() . static::CACHE_KEY_PREFIX . $modelId
            );
        }
    }

    /**
     * Generate the model keys. Then do parent save. Set the redis cache from the model keys.
     *
     * @inheritDoc
     */
    public function save($model)
    {
        $this->logger->info('Redis/AbstactDAO::save. Saving [' . $model->getId() . ']');
        $cacheService = $this->getCache();
        // If we don't have a ID we will save the model and then generate the model data.
        if ($model->getId() === null) {
            $model = parent::save($model);
            $keys  = $this->generateModelKeys([$model]);
        } else {
            $keys  = $this->generateModelKeys([$model]);
            $model = parent::save($model);
        }
        $this->logger->debug("Redis/AbstractDAO::save: Saving Redis keys " . implode(",", array_keys($keys)));
        $cacheService->setMultiple($keys);
        $this->populateSearchKeys($cacheService, [$model]);
        return $model;
    }

    /**
     * Delete all keys for the namespace and cache prefix defined for the DAO
     */
    private function deleteKeysForPrefix()
    {
        $cacheService    = $this->getCache();
        $namespace       = $cacheService->getNamespace();
        $namespaceLength = strlen($namespace);
        // Fetch all the keys that are prefix with this model and then delete them.
        $keysToDelete = array_map(
            function ($fullKey) use ($namespaceLength) {
                return substr($fullKey, $namespaceLength);
            },
            call_user_func_array(
                'array_merge',
                array_map(
                    function ($prefix) use ($cacheService, $namespace) {
                        return $cacheService->getRedisResource()->keys($namespace . $prefix . '*');
                    },
                    $this->getModelKeyPrefixes()
                )
            )
        );
        $cacheService->deleteMultiple($keysToDelete);
        $cacheService->deleteMultiple(array_values($this->getSearchKeys()));
        $cacheService->getRedisResource()->del($this->getModelSetValue($cacheService));
    }

    /**
     * Get the cache service
     * @return mixed|null the cache service or null if it is not available
     */
    protected function getCache()
    {
        $cacheService = null;
        try {
            $cacheService = $this->services->get(AbstractCacheService::CACHE_SERVICE);
        } catch (ServiceNotCreatedException $snce) {
            // Ignore, cache not available
        }
        return $cacheService;
    }

    /** Get redis keys data from the cache, with an optional limit
     * @param $cache
     * @param int $max
     * @return array
     */
    protected function getKeysFromCache($cache, $max = 0)
    {
        $keys            = [];
        $redis           = $cache->getRedisResource();
        $namespace       = $cache->getNamespace();
        $namespaceLength = strlen($namespace);
        $scan            = $namespace.static::CACHE_KEY_PREFIX.'*';
        $current         = null;
        do {
            $nextKeys = $redis->sScan(
                $this->getModelSetValue($cache), $current, $scan, static::SCAN_PAGE_SIZE
            );
            $keys     = array_merge(
                $keys, array_map(
                    function ($fullKey) use ($namespaceLength) {
                        return substr($fullKey, $namespaceLength);
                    },
                    $nextKeys ?: []
                )
            );
            if ($max > 0 && count($keys) >= $max) {
                $keys = array_slice($keys, 0, $max);
                break;
            }
        } while ($nextKeys !== false);
        return $keys;
    }

    /**
     * Generate extra indices in the cache for the models (other than the standard id link). By default
     * we do not create any extra indices, specific implementations will handle their own
     *
     * @param mixed   $models      The Perforce models to generate indices for
     * @param boolean $rebuild     Build all available primary and any secondary model keys
     * @return array
     */
    protected function generateModelKeys($models, $rebuild = false)
    {
        $this->logger->info('Redis/AbstractDAO::generateModelKeys');
        $modelKeys = [];
        // Default implementation is to create no extra indices
        foreach ($models as $model) {
            $modelKeys[$this->buildModelKeyId($model)] = $model;
        }
        $this->addToSet(array_keys($modelKeys));
        return $modelKeys;
    }

    /**
     * Adds the keys to a redis set.
     * @param array         $modelKeys  keys to add
     * @param string|null   $setName    set name to use. Defaults to $this->getModelSetValue() if not provided
     */
    protected function addToSet(array $modelKeys, string $setName = null)
    {
        $cacheService = $this->getCache();
        $redis        = $cacheService->getRedisResource();
        $namespace    = $cacheService->getNamespace();
        $redis->sAddArray(
            $setName ? $setName : $this->getModelSetValue($cacheService),
            array_map(
                function ($value) use ($namespace) {
                    return $namespace . $value;
                },
                $modelKeys
            )
        );
    }

    protected function getModelKeyPrefixes()
    {
        return [static::CACHE_KEY_PREFIX];
    }

    /**
     * Build a redis key identifier from the cache prefix for this model and its ID.
     *
     * @param mixed   $model   This is the model we are dealing with.
     * @return string   This is the ID for this model.
     */
    protected function buildModelKeyId($model)
    {
        return static::CACHE_KEY_PREFIX .
            $this->normalizeId($model->getId(), $this->getConnection($model->getConnection()));
    }

    /**
     * @param object      $cacheService         cache service
     * @param Closure     $code                 code to run in the lock
     * @param bool        $shouldBePopulated    whether to check if populated or unpopulated
     *
     * @throws ConfigException
     */
    protected function runUnderPopulateLock($cacheService, $code, $shouldBePopulated)
    {
        // Get the name of the mutex and the timeout
        $mutexName = $this->getPopulateMutexName($cacheService);
        $timeout   = $this->getPopulateTimeout();

        // Callback to check if the cache is already populated
        $check = $this->checkPopulatedCallback($cacheService, $shouldBePopulated);

        // Run the code under the lock
        $lockService = $this->services->get(ILock::SERVICE);
        $this->extendMaxPhpExecutionTime($timeout);
        $lockService->lockWithCheck($mutexName, $check, $code, $timeout);
    }

    /**
     * Ensure that php code doesn't timeout before the lock is released
     *
     * @param int   $minimumSeconds    The number of seconds that the lock will be held
     */
    protected function extendMaxPhpExecutionTime($minimumSeconds)
    {
        $maxExecutionTime = ini_get('max_execution_time');

        // There is no limit on PHP code execution, so we have nothing to do
        if ($maxExecutionTime === '0') {
            return;
        }

        $maxExecutionTime = $maxExecutionTime === '' ? self::DEFAULT_MAX_EXECUTION_TIME : (int) $maxExecutionTime;

        // The code could timeout before the lock is released, so we extend the limit to match the lock timeout
        if ($maxExecutionTime < $minimumSeconds) {
            ini_set('max_execution_time', $minimumSeconds);
        }
    }

    /**
     * Constructs the check callback to check whether or not the cache is populated
     *
     * @param   mixed/null  $cacheService
     * @param   bool        $shouldBePopulated  Determines whether it's checking for populated or unpopulated state
     *
     * @return  Closure
     */
    protected function checkPopulatedCallback($cacheService, $shouldBePopulated)
    {
        $check = function () use ($cacheService, $shouldBePopulated): bool {
            $status = $cacheService->get(static::POPULATED_STATUS);
            return $shouldBePopulated ? $status == self::POPULATED : $status == self::UNPOPULATED;
        };

        return $check;
    }

    /**
     * Builds a unique name for the mutex
     *
     * @param $cacheService
     * @return string
     */
    protected function getPopulateMutexName($cacheService)
    {
        return $this->getModelSetValue($cacheService) . '_populate';
    }

    /**
     * Generate a name for a redis set based on the model
     * @param mixed     $cacheService       the cache service
     * @return string the model set name
     */
    protected function getModelSetValue($cacheService)
    {
        return $cacheService->getNamespace() . stripslashes(static::MODEL);
    }

    /**
     * Gets the population_timeout value from the config or returns a default value
     *
     * @return int
     */

    /**
     * @return array|bool|int|mixed|string|null
     * @throws ConfigException
     */
    protected function getPopulateTimeout()
    {
        $config = $this->services->get(ConfigManager::CONFIG);

        return ConfigManager::getValue(
            $config,
            ConfigManager::REDIS_POPULATION_LOCK_TIMEOUT,
            self::DEFAULT_POPULATION_LOCK_TIMEOUT
        );
    }

    /**
     * Builds an array of md5sum/Redis key mappings
     *
     * @param mixed|null    $cache      the cache service or null if it is not available
     *
     * @return array
     */
    protected function getRedisKeyChecksums($cache)
    {
        $namespace = $cache->getNamespace();

        $this->logLine(Logger::TRACE, __LINE__, "Getting all keys in the $namespace dataset.");
        $this->setIntegrityStatusStep(static::VERIFY_GET_REDIS_KEYS);
        $redisKeys = array_map(
            function ($key) use ($namespace) {
                return $namespace . $key;
            },
            $this->getKeysFromCache($cache)
        );

        $this->logLine(Logger::TRACE, __LINE__, 'Building md5sums for raw redis entries.');
        $this->setIntegrityStatusStep(static::VERIFY_GET_REDIS_CHECKSUMS);

        return $redisKeys ?
            array_flip(
                array_combine(
                    $redisKeys,
                    array_map(
                        function ($value) {
                            return md5($value);
                        },
                        $cache->getRedisResource()->mget($redisKeys)
                    )
                )
            )
            : [];
    }

    /**
     * Builds an array of md5sum/model id key mappings
     *
     * @return array|null
     */
    protected function getServerKeyChecksums()
    {
        $this->logLine(Logger::TRACE, __LINE__, 'Building md5sums for serialized models.');
        $this->setIntegrityStatusStep(static::VERIFY_GET_SERVER_CHECKSUMS);
        return array_flip(
            array_map(
                function ($value) {
                    return md5(serialize($value));
                },
                iterator_to_array(parent::fetchAll([]))
            )
        );
    }

    /**
     * Removes any keys found in Redis that are not also found in the Perforce server
     *
     * @param mixed|null    $cache              the cache service or null if it is not available
     * @param array         $redisEntries       md5sum/Redis key mappings
     * @param array         $perforceEntries    md5sum/model id key mappings
     */
    protected function deleteExtraneousRedisKeys($cache, $redisEntries, $perforceEntries)
    {
        $this->setIntegrityStatusStep(static::VERIFY_REMOVE_EXTRA_KEYS);

        $redis         = $cache->getRedisResource();
        $modelSetValue = $this->getModelSetValue($cache);

        foreach (array_diff_key($redisEntries, $perforceEntries) as $deleted) {
            $this->logLine(Logger::TRACE, __LINE__, "Deleting $deleted from dataset & redis");
            $redis->delete($deleted);
            $redis->sRem($modelSetValue, $deleted);
            $exploded = explode(RedisService::SEPARATOR, $deleted);
            $this->removeSearchEntriesById($cache, end($exploded));
        }
    }

    /**
     * Adds any keys found in Perforce that are not also found in Redis
     *
     * @param array    $redisEntries       md5sum/Redis key mappings
     * @param array    $perforceEntries    md5sum/model id key mappings
     */
    protected function addMissingRedisKeys($redisEntries, $perforceEntries)
    {
        // Any md5sums not in redis but in perforce need to be added
        $this->setIntegrityStatusStep(static::VERIFY_ADD_MISSING_KEYS);
        foreach (array_diff_key($perforceEntries, $redisEntries) as $unCached) {
            $this->logLine(Logger::TRACE, __LINE__, "Adding $unCached to dataset & redis");
            $this->fetchByIdAndSet($unCached);
        };
    }

    /**
     * Sets the verify-status key in Redis to a verification step with a description of the step
     *
     * @param string    $step    the name of the step being performed
     */
    protected function setIntegrityStatusStep($step)
    {
        $messages   = [
            static::VERIFY_GET_REDIS_KEYS       => 'Getting Redis cache entries',
            static::VERIFY_GET_REDIS_CHECKSUMS  => 'Calculating checksums for Redis keys',
            static::VERIFY_GET_SERVER_CHECKSUMS => 'Calculating checksums for Perforce models',
            static::VERIFY_REMOVE_EXTRA_KEYS    => 'Clearing any extraneous Redis cache entries',
            static::VERIFY_ADD_MISSING_KEYS     => 'Indexing any missing Perforce models into the Redis cache'
        ];
        $stepNumber = array_search($step, array_keys($messages))+1;
        $this->setIntegrityStatus(
            sprintf(static::VERIFY_PROGRESS, $stepNumber, count($messages), $messages[$step])
        );
    }

    /**
     * Logs a message, indicating the class and line number from where it is called
     *
     * @param int       $level      the log level to be applied
     * @param int       $line       the line number where this method is called
     * @param string    $message    the message to be logged
     */
    protected function logLine($level, $line, $message)
    {
        $this->logger->log($level, get_class($this) . ':' . __CLASS__ . " ($line): $message");
    }

    /**
     * Extract an id from a single search result.
     * @param mixed     $result     the search result
     * @param string    $key        the key
     * @param bool      $raw        whether the result is raw format
     * @return mixed
     */
    public function getSearchResultId($result, string $key, bool $raw)
    {
        return $this->getSearchResultIds([$result], $key, $raw)[0];
    }

    /**
     * Extract ids from an array of search results.
     * @param array     $results    the search results
     * @param string    $key        the key
     * @param bool      $raw        whether the result is raw format
     * @return array
     */
    public function getSearchResultIds(array $results, string $key, bool $raw) : array
    {
        if ($raw) {
            $ids = array_map(
                function ($result) {
                    return preg_split("/".RedisService::SEARCH_PART_SEPARATOR."/", $result)[2];
                },
                $results
            );
        } else {
            $ids = array_map(
                function ($result) use ($key) {
                    return $result[$key];
                },
                $results
            );
        }
        return $ids;
    }
}
