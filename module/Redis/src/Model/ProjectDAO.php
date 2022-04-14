<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

use Api\IRequest;
use Application\Cache\SimpleCacheDecorator;
use Application\Config\ConfigException;
use Application\Connection\ConnectionFactory;
use Application\Helper\DateTimeHelper;
use Application\Log\SwarmLogger;
use Application\Permissions\PrivateProjects;
use P4\Connection\ConnectionInterface;
use P4\Key\Key;
use P4\Model\Fielded\Iterator;
use P4\Spec\Job;
use Projects\Model\IProject;
use Projects\Model\Project;
use Projects\Model\Project as ProjectModel;
use Psr\SimpleCache\InvalidArgumentException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Redis\RedisService;
use Users\Model\Config as UserConfig;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheInvalidArgumentException;
use Projects\Validator\BranchPath;
use Application\Model\IModelDAO;
use Exception;

/**
 * DAO to handle finding/saving Projects.
 * @package Redis\Model
 */
class ProjectDAO extends AbstractDAO
{
    use SearchEntryTrait;
    // The main key prefix to reference an individual record, for example 'project^fred'
    const CACHE_KEY_PREFIX = IProject::PROJECT . RedisService::SEPARATOR;
    // The prefix to reference a link from a path to projects/branches using it, for example 'path^AB$H...NAHS'
    const CACHE_PATH_PREFIX = 'path' . RedisService::SEPARATOR;
    // The Perforce class that handles project
    const MODEL = ProjectModel::class;
    // The Key used by projectDAO to know if it has been pre populated.
    const POPULATED_STATUS = IProject::PROJECT . "-" . AbstractDAO::POPULATED_STATUS;
    // The key for the verify status of the project dataset
    const VERIFY_STATUS = IProject::PROJECT . "-" . AbstractDAO::VERIFY_STATUS;

    // Option to fetch projects and branches by depot path
    const FETCH_BY_PATH = 'paths';
    // Option to allow fetch by path to return all branches for relevant projects
    const FETCH_ALL_PATH_BRANCHES = 'allPathBranches';
    // When populating we limit the fetch to 50 records at a time as for big project data sets generating keys
    // is CPU/memory intensive
    const FETCH_MAXIMUM = 50;
    // How many projects we unserialize at a time
    const UNSERIALIZE_BATCH_SIZE = 100;
    // The key used to index groups for starts with searches, within a given namespace
    const SEARCH_STARTS_WITH_KEY = AbstractDAO::SEARCH_STARTS_WITH . RedisService::SEPARATOR . IProject::PROJECT;
    // The key used to index groups for includes searches, within a given namespace
    const SEARCH_INCLUDES_KEY = AbstractDAO::SEARCH_INCLUDES . RedisService::SEPARATOR . IProject::PROJECT;

    /**
     * Overrides the parent populate to depend on population of groups first
     * @return bool|void
     * @throws ConfigException
     */
    public function populate()
    {
        // Getting the group DAO will ensure group population
        $this->services->get(IModelDAO::GROUP_DAO);
        parent::populate();
    }

    /**
     * Override fetchById to call fetch on the model instead.
     *
     * @param string                   $id             The id
     * @param ConnectionInterface|null $connection     The connection
     * @param bool                     $filterPrivates Remove the private project if it's value is true
     * @return  ProjectModel   instance of the requested entry
     * @throws RecordNotFoundException
     */
    public function fetchById($id, ConnectionInterface $connection = null, $filterPrivates = false)
    {
        $model = parent::fetchById($id, $connection);
        if ($filterPrivates) {
            $model = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter(new Iterator([$model]))->first();
        }
        if ($model !== null && !$model->isDeleted()) {
            return $model;
        }
        throw new RecordNotFoundException("Cannot fetch entry. Id does not exist.");
    }

    /**
     * Verifies if the specified record(s) exists.
     * We use the FetchByID function instead of exists as this saves us making additional call to check if the model
     * has been deleted. Swarm keeps deleted project as soft deletes and most calls to get project should return
     * false if deleted.
     *
     * @param string|int|array         $id         The entry id or an array of ids to filter
     * @param ConnectionInterface|null $connection The connection
     * @return  bool|array          true/false for single arg, array of existent ids for array input
     */
    public function exists($id, ConnectionInterface $connection = null)
    {
        try {
            // As fetchById can return a not found error we want to catch this and ignore and return false.
            (null !== $this->fetchById($id, $connection));
        } catch (RecordNotFoundException $fetchByIDError) {
            return false;
        } catch (SimpleCacheInvalidArgumentException $SimpleCacheError) {
            // As we will allow the project filter deal with telling users the name is too long.
            return false;
        } catch (\InvalidArgumentException $iae) {
            // Bad id, don't mark as found
            return false;
        }
        return true;
    }

    /**
     * Extends parent to add additional options (listed below) and to use cache if available.
     * To simplify the code, we support only a subset of options that are available in parent.
     * By default, deleted projects will not be included in the result. To include them,
     * FETCH_INCLUDE_DELETED option with value set to true must be passed in options.
     *
     * @param array                    $options              currently supported options are:
     *                                                       FETCH_BY_IDS - provide an array of ids to fetch
     *                                                       FETCH_BY_MEMBER - set to limit results to include only
     *                                                       projects having the given member
     *                                                       FETCH_BY_WORKFLOW - set to limit results to include only
     *                                                       projects and their branches for the given workflow. This
     *                                                       can NOT be used in conjunction with any other option.
     *                                                       FETCH_COUNT_FOLLOWERS - if true, each project will include
     *                                                       a 'followers' flag indicating the number of followers
     *                                                       FETCH_INCLUDE_DELETED - set to true to also include deleted
     *                                                       projects
     *                                                       FETCH_NO_CACHE - set to true to avoid using the cache
     * @param ConnectionInterface|null $connection
     * @return  Iterator|array              the list of zero or more matching project objects | an array of
     *                                                       projects that are linked to a given workflow.
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        $defaults = [
            ProjectModel::FETCH_BY_IDS          => null,
            ProjectModel::FETCH_BY_MEMBER       => null,
            ProjectModel::FETCH_BY_WORKFLOW     => null,
            ProjectModel::FETCH_COUNT_FOLLOWERS => null,
            ProjectModel::FETCH_INCLUDE_DELETED => false,
            IModelDAO::FETCH_NO_CACHE           => null,
            ProjectModel::FETCH_BY_KEYWORDS     => null,
            ProjectModel::FETCH_KEYWORDS_FIELDS => null,
            ProjectModel::FETCH_MAXIMUM         => null,
            ProjectModel::FETCH_AFTER           => null,
            IModelDAO::FETCH_SUMMARY            => [ProjectModel::FIELD_BRANCH_PATHS],
            IRequest::METADATA                  => null,
            IModelDAO::FILTER_PRIVATES          => false,
            self::FETCH_SEARCH                  => null
        ];
        // If we are searching, just return the output of the search method.
        if (isset($options[self::FETCH_SEARCH])) {
            return $this->search($options);
        }
        // throw if user passed option(s) we don't support
        $unsupported = array_diff(array_keys($options), array_keys($defaults));
        if (count($unsupported)) {
            throw new \InvalidArgumentException(
                'Following option(s) are not valid for fetching projects: ' . implode(', ', $unsupported) . '.'
            );
        }
        $options += $defaults;
        // throw if options are clearly invalid.
        if ($options[ProjectModel::FETCH_AFTER] && is_array($options[ProjectModel::FETCH_BY_IDS])) {
            throw new \InvalidArgumentException(
                'It is not valid to pass fetch by ids and also specify fetch after or fetch search.'
            );
        }
        // Return early if there is a fetch by id request for no data
        if (isset($options[ProjectModel::FETCH_BY_IDS]) && 0 === count($options[ProjectModel::FETCH_BY_IDS])) {
            return new Iterator();
        }
        // fetch all projects, try to get them from cache if possible
        if (!$options[ProjectModel::FETCH_BY_WORKFLOW] && !$options[IModelDAO::FETCH_NO_CACHE] &&
            !($options[ProjectModel::FETCH_MAXIMUM] && !$options[ProjectModel::FETCH_AFTER]) &&
            !($options[ProjectModel::FETCH_BY_IDS] || 75 < count((array)$options[ProjectModel::FETCH_BY_IDS]))) {
            $projects = $this->fetchRecords($options, $connection);
        }
        // For a single FETCH_BY_IDS or FETCH_BY_NAME, revert to fetchById unless DELETED projects are needed
        if ((isset($options[Key::FETCH_BY_NAME]) || count((array)$options[ProjectModel::FETCH_BY_IDS]) >= 1)) {
            $ids = isset($options[Key::FETCH_BY_NAME])
                ? (array)$options[Key::FETCH_BY_NAME]
                : (array)$options[ProjectModel::FETCH_BY_IDS];
            // Build the ids to have the prefix on them.
            foreach ($ids as $key => $value) {
                $ids[$key] = static::CACHE_KEY_PREFIX.$value;
            }
            // Now build the models of the Ids we are requesting.
            $projects = $this->buildModels($this->getCache(), $ids, $options, $connection);
        }
        // Projects do not have any indexed fields - but we can still support filter by keywords as long as we unset
        // the values so that the super class does not build an empty search expression. We support it here in the
        // model to use the in-built 'filter' method rather than the controller doing its own thing
        $keywords       = $options[ProjectModel::FETCH_BY_KEYWORDS];
        $keywordsFields = $options[ProjectModel::FETCH_KEYWORDS_FIELDS];
        unset($options[ProjectModel::FETCH_BY_KEYWORDS]);
        unset($options[ProjectModel::FETCH_KEYWORDS_FIELDS]);
        $workflow = $options[ProjectModel::FETCH_BY_WORKFLOW];
        if ($workflow !== null) {
            // At present we need to search by only branches. So including them automatically as part of the search.
            $options[ProjectModel::FETCH_BY_BRANCHES_WORKFLOW] =
                isset($options[ProjectModel::FETCH_BY_BRANCHES_WORKFLOW]) ?:$workflow;
            $options[ProjectModel::FETCH_SEARCH]               = call_user_func(
                static::MODEL  . '::' . 'makeSearchExpression',
                $options
            );
            // Fetch the Key DAO and fetch all the records from perforce counters. Replace the perforce prefix with
            // the cache prefix.
            $keyDAO = $this->services->get(IModelDAO::KEY_DAO);
            $keys   = str_replace(
                ProjectModel::KEY_PREFIX,
                self::CACHE_KEY_PREFIX,
                $keyDAO->search($options, ProjectModel::KEY_PREFIX)
            );
            // Now fetch the models from the cache.
            $projects = $this->buildModels($this->getCache(), $keys, $options, $connection);
        }
        // get projects from parent if either user requested fetching with no cache or cache is not available
        $projects = isset($projects) ? $projects : $this->fetchRecords($options, $connection);
        if ($keywords && $keywordsFields) {
            $projects->filter($keywordsFields, $keywords, [Iterator::FILTER_CONTAINS, Iterator::FILTER_NO_CASE]);
        }
        // handle FETCH_BY_MEMBER
        $member = $options[ProjectModel::FETCH_BY_MEMBER];
        if ($member) {
            $allGroups = $this->services->get(IModelDAO::GROUP_DAO)->fetchAll([], $connection)->toArray(true);
            $projects->filterByCallback(
                function (ProjectModel $project) use ($member, $allGroups) {
                    return $project->isMember($member, $allGroups);
                }
            );
        }
        // if caller requested follower counts, add them now.
        if ($options[ProjectModel::FETCH_COUNT_FOLLOWERS]) {
            $followers = UserConfig::fetchFollowerCounts(
                [UserConfig::COUNT_BY_TYPE => 'project'],
                $connection
            );
            foreach ($projects as $project) {
                $key = 'project:' . $project->getId();
                $project->set('followers', isset($followers[$key]) ? $followers[$key]['count'] : 0);
            }
        }
        if ($options[IModelDAO::FILTER_PRIVATES]) {
            // filter out projects not accessible to the current user
            $projects = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($projects);
        }
        return $projects;
    }

    /**
     * @inheritDoc
     * Calls the inherited search and provides additional functionality:
     *  -   Filtering of private projects
     *  -   Add description to formatted search results
     */
    protected function search(array $options, ConnectionInterface $connection = null)
    {
        $projects      = parent::search($options, $connection);
        $searchOptions = $options[self::FETCH_SEARCH];
        $raw           = (bool)($searchOptions[self::SEARCH_RETURN_RAW_ENTRIES] ?? false);
        // Get the ids from the search results
        $ids = $this->getSearchResultIds($projects, ProjectModel::FIELD_ID, $raw);
        // Fetch the models so that it can be determined if the project is private and to decorate results
        $projectModels = $this->fetchAll(
            [
                ProjectModel::FETCH_BY_IDS => array_unique($ids),
                IModelDAO::FILTER_PRIVATES => $options[IModelDAO::FILTER_PRIVATES] ?? true
            ],
            $connection
        );
        $originalSize  = sizeof($ids);
        if (!$raw) {
            foreach ($projectModels as $projectModel) {
                foreach ($projects as &$project) {
                    if ($projectModel->getId()
                            === $this->getSearchResultId($project, ProjectModel::FIELD_ID, false)) {
                        $project[ProjectModel::FIELD_DESCRIPTION] = $projectModel->getDescription();
                        break;
                    }
                }
            }
        }
        // If some were filtered we need to process the projects
        if (sizeof($projectModels) < $originalSize) {
            // Iterate the results of the search and remove any whose id is not found in the filtered results
            $projects = array_values(
                array_filter(
                    $projects,
                    function ($project) use ($projectModels, $raw) {
                        foreach ($projectModels as $projectModel) {
                            if ($projectModel->getId()
                                    === $this->getSearchResultId($project, ProjectModel::FIELD_ID, $raw)) {
                                return true;
                            }
                        }
                        return false;
                    }
                )
            );
        }
        return $projects;
    }

    /**
     * Fetch affected project and branches by given path
     * @param array               $options     The options we want to filter results by
     * @param ConnectionInterface $connection  The Perforce connection.
     * @return Iterator   Of Projects.
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    public function fetchAllByPath(array $options = [], ConnectionInterface $connection = null)
    {
        $defaults = [
            static::FETCH_BY_PATH              => [],
            static::FETCH_ALL_PATH_BRANCHES    => null,
            IModelDAO::FETCH_SUMMARY           => []
        ];
        // throw if user passed option(s) we don't support
        $unsupported = array_diff(array_keys($options), array_keys($defaults));
        if (count($unsupported)) {
            throw new \InvalidArgumentException(
                'Following option(s) are not valid for fetching projects: ' . implode(', ', $unsupported) . '.'
            );
        }
        $options += $defaults;
        // throw if options are clearly invalid.
        if (!count(($options[static::FETCH_BY_PATH]))) {
            throw new \InvalidArgumentException(
                'FetchAllByPath must be given at least path.'
            );
        }

        $this->logger->debug(
            "Redis/ProjectDAO:fetchAllByPath: Getting all projects/branches for paths [" .
            implode(", ", $options[static::FETCH_BY_PATH]) . "]"
        );
        $cacheService     = $this->getCache();
        $useLowercase     = !($connection?:$this->getConnection())->isCaseSensitive();
        $affectedProjects = [];
        $allKeys          = [];
        // Build up an array of projectId => branchId values
        foreach ((array)$options[static::FETCH_BY_PATH] as $path) {
            // Move into BranchPath::splitPath($path)
            $pathElements = BranchPath::splitPath($path);
            $depotPath    = "//";
            foreach ($pathElements as $element) {
                $depotPath .= $element;
                $allKeys[]  = static::buildPathKey($depotPath, $useLowercase?$this->lowercase:null);
            }
        }
        // Get all of the matching keys in one request
        $keyReferences = $allKeys?$cacheService->getMultiple($allKeys):[];
        // Merge all of the branch ids into a an array keyed on project id
        array_walk(
            $keyReferences,
            function ($projects) use (&$affectedProjects) {
                foreach ((array)$projects as $project => $branches) {
                    $affectedProjects[$project] = array_keys(
                        array_flip(array_merge($affectedProjects[$project] ?? [], $branches))
                    );
                }
            }
        );

        // Get the project data for the affected projects and (optionally) filter out the branches
        $this->logger->trace(
            "Redis/ProjectDAO:fetchAllByPath: Found " . count($affectedProjects) . " affected projects"
        );
        $models = $this->fetchAll(
            [ProjectModel::FETCH_BY_IDS => array_keys($affectedProjects), IModelDAO::FETCH_SUMMARY => []],
            $connection
        );
        if (isset($options[static::FETCH_ALL_PATH_BRANCHES])) {
            return $models;
        }
        foreach ($models as $project) {
            $project->setBranches(
                array_values(
                    array_filter(
                        $project->getBranches(),
                        function ($branch) use ($project, $affectedProjects) {
                            return in_array($branch['id'], $affectedProjects[$project->getId()]);
                        }
                    )
                )
            );
        }
        $this->logger->debug(
            "Redis/ProjectDAO:fetchAllByPath: Returning " . count($models) . " projects"
        );
        return $models;
    }

    /**
     * Determine which projects are affected by the given job.
     *
     * @param Job                 $job        the job to examine
     * @param ConnectionInterface $connection the perforce connection to use
     * @param array|null          $projects   The list of project to be used.
     * @return  array       a list of affected projects as values (auto-incrementing keys).
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    public function getAffectedByJob(Job $job, ConnectionInterface $connection, $projects = null)
    {
        // loop over projects and, for those with a valid job view,
        // see which are impacted by the passed job.
        $projects = $projects !== null ? $projects : $this->fetchAll([], $connection);
        return call_user_func(static::MODEL  . '::' . __FUNCTION__, $job, $connection, $projects);
    }

    /**
     * Fetch All the keys that we want to return as models.
     *
     * @param array               $options     The options we want to filter results by
     * @param ConnectionInterface $connection  The Perforce connection.
     * @return Iterator   Of Projects.
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    private function fetchRecords(array $options = [], ConnectionInterface $connection = null)
    {
        $cacheService    = $this->getCache();
        $redis           = $cacheService->getRedisResource();
        $namespace       = $cacheService->getNamespace();
        $namespaceLength = strlen($namespace);
        $keys            = [];
        $currentIndex    = null;
        // Fetch all keys from Redis and let the build model filter them.
        do {
            $nextKeys = $redis->sScan(
                $this->getModelSetValue($cacheService),
                $currentIndex,
                $namespace . static::CACHE_KEY_PREFIX . '*',
                static::SCAN_PAGE_SIZE
            );
            $keys     = array_merge(
                $keys,
                array_map(
                    function ($fullKey) use ($namespaceLength) {
                        return substr($fullKey, $namespaceLength);
                    },
                    $nextKeys?:[]
                )
            );
        } while ($nextKeys !== false);
        return $this->buildModels($cacheService, $keys, $options, $connection);
    }

    /**
     * Build the models for the given Keys. This applies the fetch_after and fetch_max requirements.
     * It also checks if we are wanting to fetch_include_deleted projects.
     *
     * @param SimpleCacheDecorator     $cacheService    This is the cache service
     * @param array                    $keys            The list of keys to fetch.
     * @param array|null               $options         The options to filter by
     * @param ConnectionInterface|null $connection      The connection to Perforce.
     * @return Iterator
     * @throws InvalidArgumentException
     */
    private function buildModels(
        $cacheService,
        array $keys = [],
        array $options = null,
        ConnectionInterface $connection = null
    ) {
        $class  = get_class($this);
        $func   = __FUNCTION__;
        $start  = DateTimeHelper::getTime();
        $models = new Iterator();
        $max    = $options[ProjectModel::FETCH_MAXIMUM];
        $after  = $options[ProjectModel::FETCH_AFTER];
        if (count($keys) > 0) {
            $this->logger->trace(
                sprintf(
                    "[%s]->[%s]: Value of FETCH_SUMMARY [%s]",
                    $class,
                    $func,
                    var_export($options[static::FETCH_SUMMARY], true)
                )
            );
            // Sort the keys into a order that makes it easier for after and max.
            natsort($keys);
            $batch        = array_chunk($keys, static::UNSERIALIZE_BATCH_SIZE);
            $afterMatched = false;
            $count        = 1;
            $batchSize    = sizeof($batch);
            foreach ($batch as $batchKeys) {
                $batchStart = DateTimeHelper::getTime();
                $records    = $cacheService->getMultiple($batchKeys);
                $batchEnd   = DateTimeHelper::getTime();
                $this->logger->trace(
                    sprintf(
                        "[%s]->[%s]: getMultiple %d of %d in %f seconds",
                        $class,
                        $func,
                        $count,
                        $batchSize,
                        ($batchEnd - $batchStart)
                    )
                );
                foreach ($records as $key => $model) {
                    // If we don't have a model skip to next one.
                    if (!$model) {
                        continue;
                    }
                    // Check if we are wanting to include deleted project or not.
                    if ($options[ProjectModel::FETCH_INCLUDE_DELETED] === false && $model->isDeleted()) {
                        continue;
                    }
                    // If we are fetching after a given ID ensure we don't include Projects before that.
                    if ($after && $model->getId() !== $after) {
                        if (!$afterMatched) {
                            continue;
                        }
                    } else {
                        // If this is the project that was request for records after skip it.
                        if ($model->getId() === $after) {
                            $afterMatched = true;
                            // As we don't want to include the after records continue to next element.
                            continue;
                        }
                    }
                    // We have passed all requirements so we must want to include this model.
                    if ($options[static::FETCH_SUMMARY]) {
                        $model = $model->getSummary($options[static::FETCH_SUMMARY]);
                    }
                    $models[$model->getId()] = $this->setConnection($model, $connection);
                    // Check that we have fill Max items we want.
                    if ($max > 0 && $models->count() === $max) {
                        break;
                    }
                };
                $count++;
            }
        }
        $this->logger->trace(
            sprintf(
                "[%s]->[%s]: in %f seconds",
                $class,
                $func,
                (DateTimeHelper::getTime() - $start)
            )
        );
        $this->logger->trace(
            sprintf(
                "[%s]->[%s]: %d MB, %d records",
                $class,
                $func,
                (int) (memory_get_usage() / 1024 / 1024),
                count($models)
            )
        );
        return $models;
    }

    /**
     * Generate extra indices in the cache for the models (other than the standard id link). By default
     * we do not create any extra indices, specific implementations will handle their own
     *
     * @param mixed  $projects  The Project models to generate indices for
     * @param boolean $rebuild  Generate the primary project key and regenerate all of the path keys
     * @return array
     * @throws InvalidArgumentException
     */
    public function generateModelKeys($projects, $rebuild = false)
    {
        $projectKeys  = [];
        $modelKeys    = [];
        $cacheService = $this->getCache();
        $useLowercase = !$this->getConnection()->isCaseSensitive();
        foreach ((array) $projects as $project) {
            $this->logger->info('Redis/ProjectDAO::generateModelKeys for ' . $project->getId());
            // Primary project key.
            $modelKey               = $this->buildModelKeyId($project);
            $projectKeys[$modelKey] = $project;
            $modelKeys[]            = $modelKey;
            // Build list of branch hashes.
            $projectKeys = $this->mergeBranchIndexKeys($cacheService, $projectKeys, $project, $useLowercase, $rebuild);
        }
        $this->addToSet($modelKeys);
        return $projectKeys;
    }

    /**
     * Iterator though the branches within this project. Identify the differences between the original and the
     * current branch mappings. Then merge these differences with the existing redis keys
     *
     * @param SimpleCacheDecorator $cacheService  This is the cache service
     * @param array                $projectKeys   The project keys we have.
     * @param ProjectModel         $project       The project we are deal with.
     * @param boolean              $useLowercase  Convert paths to lowercase before hashing
     * @param boolean              $rebuild       Merge all path keys, or just the updates
     * @return array  The list of updated hashes.
     * @throws InvalidArgumentException
     */
    protected function mergeBranchIndexKeys($cacheService, $projectKeys, $project, $useLowercase, $rebuild = false)
    {
        $this->logger->trace(
            "Redis/ProjectDAO::mergeBranchIndexKeys: merging project " . $project->getId() .
            " into " . implode(", ", array_keys($projectKeys))
        );
        $branchIndexKeys = [];
        $projectId       = $project->getId();
        // Work out the paths that need updates to the cache.
        $updatedPaths = $rebuild ? $project->getPaths() : $project->getUpdatedPaths();
        $this->logger->trace(
            "Redis/ProjectDAO::mergeBranchIndexKeys: path updates for " . $projectId .
            " are " . implode(", ", array_keys($updatedPaths))
        );
        foreach ($updatedPaths as $path => $branches) {
            $key = static::buildPathKey($path, $useLowercase ? $this->lowercase : null);
            // If projectKeys is passed in and has this key in it, there is no need to go to the cache
            $existing = isset($projectKeys[$key]) ? $projectKeys[$key] : $cacheService->get($key);
            // Special processing is required when a project no longer references a path
            if (count($branches) === 0) {
                unset($existing[$projectId]);
            } else {
                $existing[$projectId] = $branches;
            }
            $branchIndexKeys[$key] = $existing;
            $this->logger->trace(
                "Redis/ProjectDAO::mergeBranchIndexKeys: keys for $key after adding [$projectId=>["
                . implode(", ", $branches) ."]]  are "
                . implode(
                    ", ",
                    array_map(
                        function ($project, $branches) {
                            return $project.'['.implode(', ', $branches).']';
                        },
                        array_keys($branchIndexKeys[$key]??[]),
                        array_values($branchIndexKeys[$key]??[])
                    )
                )
            );
        }
        return array_merge($projectKeys, $branchIndexKeys);
    }

    /**
     * Build a redis key of the format 'path^<hash>' from the given path
     * @param $path
     * @param callable $lowercase function to perform case adjustment
     * @return string
     */
    protected static function buildPathKey($path, callable $lowercase = null)
    {
        return static::CACHE_PATH_PREFIX .
            hash(RedisService::HASHED_KEY_ALGORITHM, $lowercase ? $lowercase($path): $path);
    }

    /**
     * @return array
     */
    protected function getModelKeyPrefixes()
    {
        return [static::CACHE_KEY_PREFIX, static::CACHE_PATH_PREFIX];
    }

    /**
     * For an affected projects array of [<projectId> => [<branches>], ...] get a unique list of workflow ids. If there
     * are workflows defined at the branch level these take precedence over the project level. For example:
     *
     * Project1 -> workflow id 1
     * Project1 -> Branch1  -> workflow id 2
     * Project1 -> Branch2  -> workflow id 3
     *
     * In the above case '2' and '3'  would be part of the returned results.
     *
     * @param mixed         $affectedProjects   the affected projects
     * @return array unique workflow ids, or an empty array if none are found
     */
    public function getWorkflowsForAffectedProjects($affectedProjects)
    {
        $workflowIds       = [];
        $hasBranchWorkflow = false;
        $projectWorkflow   = null;
        if ($affectedProjects) {
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            foreach ($affectedProjects as $projectId => $branches) {
                $project         = $this->fetch($projectId, $p4Admin);
                $projectWorkflow = $project->getWorkflow();
                if ($branches) {
                    $populatedBranches = $project->getBranches();
                    foreach ($branches as $branch) {
                        $branchWorkflow = $project->getWorkflow($branch, $populatedBranches);
                        if ($branchWorkflow && $branchWorkflow !== $projectWorkflow) {
                            $hasBranchWorkflow = true;
                            $workflowIds[]     = $branchWorkflow;
                        }
                    }
                }
                // Only use the project level workflow if there were none on branches as they effectively replace the
                // project level workflow
                if ($projectWorkflow && !$hasBranchWorkflow) {
                    $workflowIds[] = $projectWorkflow;
                }
            }
        }
        return array_values(array_unique($workflowIds));
    }
    /**
     * Fetch metadata for all the models.
     * @param mixed         $models     iterator of models
     * @param array|null    $options    metadata options. Supports:
     *      IProject::FIELD_USERROLES   summary of open closed counts
     * @return array an array with a metadata element for each model according to the options provided. If options are
     * null all metadata is returned. For example
     *      [
     *          [
     *              'metadata' => [
     *                  'userRoles' => [owner, member, moderator, follower],
     *              ],
     *          ]
     *          ...
     *      ]
     * The returned values make it easy to merge in a metadata element when converting project models for output
     */
    public function fetchAllMetadata($models, array $options = null)
    {
        if ($options === null) {
            // If options are null assume all metadata is requested
            $options = [
                IProject::FIELD_USERROLES => true,
            ];
        }
        $metadata = [];
        if (!empty($options)) {
            foreach ($models as $project) {
                $modelMetadata = [];
                if (isset($options[IProject::FIELD_USERROLES])) {
                    $modelMetadata[IRequest::METADATA][IProject::FIELD_USERROLES] = [];
                    try {
                        // Try and get current logged in user.
                        $p4User = $this->services->get(ConnectionFactory::P4_USER);
                        // get the users roles.
                        $modelMetadata[IRequest::METADATA][IProject::FIELD_USERROLES]
                            = $project->getMembershipLevels($p4User->getUser(), true);
                    } catch (\Exception $err) {
                        // If we have landed here user isn't logged in.
                    }
                }
                $metadata[] = $modelMetadata;
            }
        }
        return $metadata;
    }

    /**
     * @inheritDoc
     * @see SearchEntryTrait::constructGroupEntries()
     */
    protected function buildSearchEntries($models) : array
    {
        return $this->constructEntries($models);
    }

    /**
     * @inheritDoc
     * $matches for projects will always be in the form <projectName><RedisService::SEARCH_PART_SEPARATOR><id>
     */
    protected function formatSearchResults(array $matches)
    {
        return $this->formatResults($matches, Project::FIELD_ID, Project::FIELD_NAME);
    }

    /**
     * Overrides the default behaviour to prevent projects being removed from redis unless the model is really not
     * found. Projects are soft deleted and so should remain in the cache for functions that use the
     * FETCH_INCLUDE_DELETED option
     * @param mixed                     $id             project id
     * @param ConnectionInterface|null  $connection     connection to use
     * @return mixed|null
     * @throws InvalidArgumentException
     */
    public function fetchByIdAndSet($id, ConnectionInterface $connection = null)
    {
        $connection   = $this->getConnection($connection);
        $nid          = $this->normalizeId($id);
        $model        = null;
        $cacheService = $this->getCache();
        try {
            // Bypass the cache and include deleted items
            $model = call_user_func(
                static::MODEL  . '::fetchAll',
                [
                    ProjectModel::FETCH_BY_IDS => [$id],
                    ProjectModel::FETCH_INCLUDE_DELETED => true
                ],
                $connection
            )->first();
            $cacheService->delete(static::CACHE_KEY_PREFIX . $nid);
            $this->deleteFromSet($nid, $cacheService);
            if ($model) {
                $cacheService->set(static::CACHE_KEY_PREFIX . $nid, $model);
                $this->addToSet([static::CACHE_KEY_PREFIX . $nid]);
                $this->populateSearchKeys($cacheService, [$model]);
            } else {
                // Since we are here there is no model, so we need to remove the search entry by id
                $this->removeSearchEntriesById($cacheService, $id);
                $this->services->get(SwarmLogger::SERVICE)
                    ->debug("$nid removed from the cache, fetchById found no record");
            }
        } catch (Exception $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err(sprintf("%s: %s", get_class($this), $e->getMessage()));
        }
        return $model ? $this->setConnection($model, $connection) : null;
    }

    /**
     * Soft delete a project by setting its deleted property to true. This will still make the record available in the
     * cache for calls that use FETCH_INCLUDE_DELETED
     * @param mixed $model  project to delete
     * @return mixed the project
     */
    public function delete($model)
    {
        return $this->save($model->setDeleted(true));
    }
}
