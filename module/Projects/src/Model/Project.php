<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Model;

use Application\Model\ServicesModelTrait;
use Groups\Model\Group;
use P4\Connection\ConnectionInterface as Connection;
use P4\File\File;
use P4\Key\Key;
use P4\Log\Logger;
use P4\Model\Fielded\Iterator;
use P4\Spec\Client;
use P4\Spec\Exception\NotFoundException as P4SpectNotFoundException;
use P4\Spec\Job;
use Projects\Validator\BranchPath;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Record\Key\AbstractKey as KeyRecord;
use TestIntegration\Filter\EncodingValidator;
use Users\Model\Config as UserConfig;
use Users\Model\User;
use Application\Model\IndexTrait;
use Exception;
use Workflow\Model\Workflow;
use InvalidArgumentException;

class Project extends AbstractKey
{
    use ServicesModelTrait;
    use IndexTrait;
    const KEY_PREFIX    = 'swarm-project-';
    const UPGRADE_LEVEL = 1;
    const UPGRADE       = 'upgrade';

    const FETCH_BY_MEMBER            = 'member';
    const FETCH_BY_WORKFLOW          = 'workflow';
    const FETCH_BY_BRANCHES_WORKFLOW = 'branches';
    const FETCH_COUNT_FOLLOWERS      = 'countFollowers';
    const FETCH_INCLUDE_DELETED      = 'includeDeleted';

    const MEMBERSHIP_LEVEL_OWNER     = 'owner';
    const MEMBERSHIP_LEVEL_MEMBER    = 'member';
    const MEMBERSHIP_LEVEL_FOLLOWER  = 'following';
    const MEMBERSHIP_LEVEL_MODERATOR = 'moderator';

    const FIELD_ID                       = 'id';
    const FIELD_NAME                     = 'name';
    const FIELD_DESCRIPTION              = 'description';
    const FIELD_WORKFLOW                 = 'workflow';
    const FIELD_RETAIN_DEFAULT_REVIEWERS = 'retainDefaultReviewers';
    const FIELD_MINIMUM_UP_VOTES         = 'minimumUpVotes';
    const FIELD_MEMBERS                  = 'members';
    const FIELD_SUBGROUPS                = 'subgroups';
    const FIELD_BRANCH_PATHS             = 'paths';
    const FIELD_BRANCHES                 = 'branches';
    const FIELD_TESTS                    = 'tests';
    const FIELD_PRIVATE                  = 'private';

    const MAX_VALUE = Group::MAX_VALUE;

    protected $needsGroup = false;
    protected $fields     = [
        self::FIELD_NAME                     => [
            'accessor'  => 'getName',
            'mutator'   => 'setName'
        ],
        'defaults'                              => [
            'accessor'  => 'getDefaults',
            'mutator'   => 'setDefaults'
        ],
        self::FIELD_DESCRIPTION              => [
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ],
        self::FIELD_MEMBERS => [
            'accessor'  => 'getMembers',
            'mutator'   => 'setMembers'
        ],
        self::FIELD_SUBGROUPS     => [
            'accessor'  => 'getSubgroups',
            'mutator'   => 'setSubgroups',
            'unstored'  => true
        ],
        'owners'        => [
            'accessor'  => 'getOwners',
            'mutator'   => 'setOwners',
        ],
        self::FIELD_BRANCHES => [
            'index'     => 1602,
            'indexer'   => 'indexBranchWorkflows',
            'accessor'  => 'getBranches',
            'mutator'   => 'setBranches'
        ],
        'jobview'       => [
            'accessor'  => 'getJobview',
            'mutator'   => 'setJobview'
        ],
        'emailFlags'    => [
            'accessor'  => 'getEmailFlags',
            'mutator'   => 'setEmailFlags'
        ],
        self::FIELD_TESTS => [
            'accessor'  => 'getTests',
            'mutator'   => 'setTests'
        ],
        'deploy'        => [
            'accessor'  => 'getDeploy',
            'mutator'   => 'setDeploy'
        ],
        'deleted'                               => [
            'accessor'  => 'isDeleted',
            'mutator'   => 'setDeleted'
        ],
        self::FIELD_PRIVATE => [
            'accessor'  => 'isPrivate',
            'mutator'   => 'setPrivate'
        ],
        self::FIELD_WORKFLOW                 => [
            'index'         => 1601,
        ],
        self::FIELD_RETAIN_DEFAULT_REVIEWERS => [
            'accessor'  => 'areDefaultReviewersRetained',
            'mutator'   => 'setRetainDefaultReviewers'
        ],
        self::FIELD_MINIMUM_UP_VOTES         => [
            'accessor'  => 'getMinimumUpVotes',
            'mutator'   => 'setMinimumUpVotes'
        ],
        self::UPGRADE => ['hidden' => true]
    ];

    private static $definedFieldsCache = null;
    // Define default roles that will be looked for to establish if a user is interested in a project
    private static $projectRoles = [
        Project::MEMBERSHIP_LEVEL_MEMBER,
        Project::MEMBERSHIP_LEVEL_OWNER,
        Project::MEMBERSHIP_LEVEL_FOLLOWER,
        Project::MEMBERSHIP_LEVEL_MODERATOR
    ];

    /**
     * Upgrade this record on save.
     *
     * @param   KeyRecord|null  $stored     an instance of the old record from storage or null if adding
     */
    protected function upgrade(KeyRecord $stored = null)
    {
        // For new projects set the current level
        if (!$stored) {
            $this->set(self::UPGRADE, static::UPGRADE_LEVEL);
            return;
        }
        // For upgraded projects do nothing
        if ((int)$stored->get(self::UPGRADE) >= static::UPGRADE_LEVEL) {
            return;
        }
        // Make sure all data is written for out-of-date schemas
        $this->original = null;
        try {
            if ((int)$stored->get(self::UPGRADE) === 0) {
                // Upgrade to level 1
                //  - repair the 1602 branches index that relates this project to workflows
                // Need to get all workflows so we can remove any potential index entries that were
                // created in the past as branches were updated
                $workflowDao = self::getWorkflowDao();
                $workflows   = $workflowDao->fetchAll([], $this->getConnection());
                if ($workflows) {
                    $projectWorkflow = $stored->getWorkflow();
                    $branchWorkflows = [];
                    // Gather current branch workflows if there are any
                    $branches = $stored->getBranches();
                    foreach ($branches as $branch) {
                        $branchWorkflow = $stored->getWorkflow($branch[self::FIELD_ID], $branches);
                        if ($branchWorkflow && $branchWorkflow != $projectWorkflow) {
                            $branchWorkflows[] = $branchWorkflow;
                        }
                    }
                    $workflowIds = [];
                    foreach ($workflows as $workflow) {
                        $workflowIds[] = $workflow->getId();
                    }
                    $remove = array_diff($workflowIds, $branchWorkflows);
                    if ($remove) {
                        // Remove all workflow id entries for 1602 against this project that are not still on branches
                        $this->removeIndexValue(1602, $remove, true, Workflow::class);
                    }
                }
                $this->set(self::UPGRADE, 1);
            }
        } catch (Exception $e) {
            // We do not want to prevent save if there is a problem but we need to log it
            Logger::log(
                Logger::ERR,
                sprintf(
                    "Failed to upgrade project [%s] to level [%s]. Exception is [%s]",
                    $this->getId(),
                    static::UPGRADE_LEVEL,
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Verifies if the specified record(s) exists.
     * Its better to call 'fetch' directly in a try block if you will
     * be retrieving the record on success.
     *
     * @param   string|int|array    $id     the entry id or an array of ids to filter
     * @param   Connection          $p4     the connection to use
     * @return  bool|array          true/false for single arg, array of existent ids for array input
     * @throws Exception
     */
    public static function exists($id, Connection $p4)
    {
        $found = [];
        foreach ((array)$id as $projectId) {
            try {
                $found[] = Project::fetch($projectId, $p4)->getId();
            } catch (RecordNotFoundException $nfe) {
                // Non-existent, don't mark as found
            } catch (InvalidArgumentException $iae) {
                // Bad id, don't mark as found
            }
        }
        return is_array($id) ? $found : count($found) != 0;
    }

    /**
     * Get all of the field names that are defined in this model (excludes ad-hoc fields).
     * Overridden to only get fields if not already statically set
     *
     * @return  array   a list of defined field names for this model.
     */
    public function getDefinedFields()
    {
        // field names are taken from the fields array
        // if the element value is an array, we assume the key is the
        // field name; otherwise, we assume the value is the field name.
        if (!static::$definedFieldsCache) {
            foreach ($this->fields as $key => $value) {
                static::$definedFieldsCache[] = is_array($value) ? $key : $value;
            }
        }
        return static::$definedFieldsCache;
    }

    /**
     * As project didn't have a fetchById implemented this in case the AbstractDAO had to fall back to model.
     * Note: Deleted projects are not included in the result.
     *
     * @param  string     $id      The id of the entry to fetch
     * @param  Connection $p4      A specific connection to use
     * @return Project             Instance of the requested entry
     * @throws NotFoundException   If project with the given id doesn't exist
     */
    public static function fetchById($id, Connection $p4)
    {
        return Project::fetch($id, $p4);
    }

    /**
     * Extends fetch to get Project.
     * Note: Deleted projects are not included in the result.
     *
     * @param   string          $id         the id of the entry to fetch
     * @param   Connection      $p4         a specific connection to use
     * @return Project|AbstractKey
     * @throws  RecordNotFoundException     if project with the given id doesn't exist
     */
    public static function fetch($id, Connection $p4)
    {
        $project = parent::fetch($id, $p4);

        // if we have a project and it is not deleted, return it
        if (isset($project) && !$project->isDeleted()) {
            return $project;
        }

        throw new RecordNotFoundException("Cannot fetch entry. Id does not exist.");
    }

    /**
     * Extends parent to add additional options (listed below) and to use cache if available.
     * To simplify the code, we support only a subset of options that are available in parent.
     * By default, deleted projects will not be included in the result. To include them,
     * FETCH_INCLUDE_DELETED option with value set to true must be passed in options.
     *
     * @param   array       $options    currently supported options are:
     *                                        FETCH_BY_IDS - provide an array of ids to fetch
     *                                     FETCH_BY_MEMBER - set to limit results to include only projects
     *                                                       having the given member
     *                                   FETCH_BY_WORKFLOW - set to limit results to include only projects
     *                                                       and their branches for the given workflow. This
     *                                                       can NOT be used in conjunction with any other option.
     *                               FETCH_COUNT_FOLLOWERS - if true, each project will include a 'followers'
     *                                                       flag indicating the number of followers
     *                               FETCH_INCLUDE_DELETED - set to true to also include deleted projects
     * @param   Connection  $p4             the perforce connection to use
     * @return  Iterator|array              the list of zero or more matching project objects | an array of
     *                                      projects that are linked to a given workflow.
     * @throws  InvalidArgumentException   if the caller passed option(s) we don't support
     * @throws  Exception                  for unexpected issues
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        // prepare default values for supported options
        $defaults = [
            static::FETCH_BY_IDS          => null,
            static::FETCH_BY_MEMBER       => null,
            static::FETCH_BY_WORKFLOW     => null,
            static::FETCH_COUNT_FOLLOWERS => null,
            static::FETCH_INCLUDE_DELETED => false,
            static::FETCH_BY_KEYWORDS     => null,
            static::FETCH_KEYWORDS_FIELDS => null,
            static::FETCH_MAXIMUM         => null,
            static::FETCH_AFTER           => null
        ];

        // throw if user passed option(s) we don't support
        $unsupported = array_diff(array_keys($options), array_keys($defaults));
        if (count($unsupported)) {
            throw new InvalidArgumentException(
                'Following option(s) are not valid for fetching projects: ' . implode(', ', $unsupported) . '.'
            );
        }

        $options += $defaults;

        // Return early if there is a fetch by id request for no data
        if (isset($options[static::FETCH_BY_IDS]) && 0 === count($options[static::FETCH_BY_IDS])) {
            return new Iterator();
        }

        // For a single FETCH_BY_IDS or FETCH_BY_NAME, revert to fetch unless DELETED projects are needed
        if ((isset($options[Key::FETCH_BY_NAME]) || count((array)$options[static::FETCH_BY_IDS]) === 1) &&
            !$options[static::FETCH_INCLUDE_DELETED]) {
            $projects = new Iterator();
            $id       = isset($options[Key::FETCH_BY_NAME])
                ? $options[Key::FETCH_BY_NAME]
            : current((array)$options[static::FETCH_BY_IDS]);
            try {
                $project                     = static::fetch($id, $p4);
                $projects[$project->getId()] = $project;
            } catch (NotFoundException $nfe) {
                // FetchAll allows data to not exist, with an empty array returned.
            }
        }

        // Projects do not have any indexed fields - but we can still support filter by keywords as long as we unset
        // the values so that the super class does not build an empty search expression. We support it here in the
        // model to use the in-built 'filter' method rather than the controller doing its own thing
        $keywords       = $options[static::FETCH_BY_KEYWORDS];
        $keywordsFields = $options[static::FETCH_KEYWORDS_FIELDS];
        unset($options[static::FETCH_BY_KEYWORDS]);
        unset($options[static::FETCH_KEYWORDS_FIELDS]);

        $workflow = $options[static::FETCH_BY_WORKFLOW];
        if ($workflow) {
            // At present we need to search by only branches. So including them automatically as part of the search.
            $options[static::FETCH_BY_BRANCHES_WORKFLOW] = isset($options[static::FETCH_BY_BRANCHES_WORKFLOW])
                ?:$workflow;
            $options[static::FETCH_SEARCH]               = static::makeSearchExpression($options);
        }

        // get projects from parent if either user requested fetching with no cache or cache is not available
        $projects = isset($projects) ? $projects : parent::fetchAll($options, $p4);

        if ($keywords && $keywordsFields) {
            $projects->filter($keywordsFields, $keywords, [Iterator::FILTER_CONTAINS, Iterator::FILTER_NO_CASE]);
        }

        // unless explicitly requested, filter out deleted projects
        if (!$options[static::FETCH_INCLUDE_DELETED]) {
            $projects->filter('deleted', false);
        }

        // handle FETCH_BY_MEMBER
        $member = $options[static::FETCH_BY_MEMBER];
        if ($member) {
            $allGroups = self::getGroupDao()->fetchAll([], $p4)->toArray(true);
            $projects->filterByCallback(
                function (Project $project) use ($member, $allGroups) {
                    return $project->isMember($member, $allGroups);
                }
            );
        }

        // if caller requested follower counts, add them now.
        if ($options[static::FETCH_COUNT_FOLLOWERS]) {
            $followers = UserConfig::fetchFollowerCounts(
                [UserConfig::COUNT_BY_TYPE => 'project'],
                $p4
            );

            foreach ($projects as $project) {
                $key = 'project:' . $project->getId();
                $project->set('followers', isset($followers[$key]) ? $followers[$key]['count'] : 0);
            }
        }

        return $projects;
    }

    /**
     * Produces a 'p4 search' expression for the given field/value pairs.
     *
     * Extends parent to put the branches and project level workflow search
     * together.
     *
     * @param   array   $conditions     field/value pairs to search for
     *
     * @return  string  a query expression suitable for use with p4 search
     */
    public static function makeSearchExpression($conditions)
    {
        $workflowSearches = [];
        // First find the workflow searches and remove them from the parent expression builder
        foreach ([Project::FETCH_BY_WORKFLOW, Project::FETCH_BY_BRANCHES_WORKFLOW] as $condition) {
            if (isset($conditions[$condition])) {
                $workflowSearches[] = parent::makeSearchExpression([$condition => $conditions[ $condition]]);
                unset($conditions[$condition]);
            }
        }
        $workflowSearchExpression = implode('|', $workflowSearches);

        // Now build the expression for any other conditions that we have passed in.
        $expression = parent::makeSearchExpression($conditions);
        // Put both the workflow search and any other searches together.
        $expression = $expression . $workflowSearchExpression;

        return $expression;
    }

    /**
     * The friendly name for this project.
     *
     * @return  string|null     the name for this project.
     */
    public function getName()
    {
        return $this->getRawValue(Project::FIELD_NAME);
    }

    /**
     * Set a friendly name for this project.
     *
     * @param   string|null     $name   the friendly name for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setName($name)
    {
        return $this->setRawValue(Project::FIELD_NAME, $name);
    }

    /**
     * The defaults for this project, always provided, can be empty.
     *
     * @return  array     the default values for this project.
     */
    public function getDefaults()
    {
        $defaults =  (array)$this->getRawValue('defaults') + ['reviewers' => []];
        ksort($defaults['reviewers']);
        return $defaults;
    }

    /**
     * Set the defaults for this project.
     *
     * @param   array     $defaults    an array of name/value pairs
     * @return  Project   to maintain a fluent interface
     */
    public function setDefaults(array $defaults)
    {
        return $this->setRawValue('defaults', $this->normaliseDefaults($defaults));
    }

    /**
     * Process a project/branch defaults value, applying normalisation as neccesary.
     * Reviewers will have normalised values for the required attribute; true, "1" or unset
     * @param array $defaults
     * @return array normalised $defaults
     */
    private function normaliseDefaults(array $defaults)
    {
        foreach ($defaults as $default => &$values) {
            if ($default === 'reviewers') {
                foreach ($values as &$reviewer) {
                    if (isset($reviewer['required']) && $reviewer['required'] !== "1") {
                        if ("false" === $reviewer['required']) {
                            unset($reviewer['required']);
                        } else {
                            $reviewer['required'] = true;
                        }
                    }
                }
            }
        }
        return $defaults;
    }
    /**
     * The description for this project.
     *
     * @return  string|null     the description for this project.
     */
    public function getDescription()
    {
        return $this->getRawValue(Project::FIELD_DESCRIPTION);
    }

    /**
     * Set a description for this project.
     *
     * @param   string|null     $description    the description for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue(Project::FIELD_DESCRIPTION, $description);
    }

    /**
     * Returns an array of member ids associated with this project.
     * @return  array   ids of all members for this project
     */
    public function getMembers()
    {
        $this->loadGroup();
        return $this->getSortedField(self::FIELD_MEMBERS);
    }

    /**
     * Returns an array of subgroups that are members of this project
     * (i.e. subgroups of the project group).
     *
     * @return  array   ids of all subgroups under this project
     */
    public function getSubgroups()
    {
        $this->loadGroup();
        return (array) $this->getRawValue(self::FIELD_SUBGROUPS);
    }

    /**
     * Get all members of this project recursively.
     *
     * @param bool        $flip    if true array keys are the user ids (default is false)
     * @param null|array  $groups  If groups are passed in forward to DAO
     * @return array|null flat list of all members
     * @throws \P4\Exception
     */
    public function getAllMembers($flip = false, $groups = null)
    {
        return self::getGroupDao()->fetchAllMembers($this->id, $flip, $groups, null, $this->getConnection());
    }

    /**
     * Get all Users and groups of this project
     * @param bool $flip if true array keys are the user ids (default is false)
     * @return array|null flat list of all members
     * @throws \P4\Exception
     */
    public function getUsersAndSubgroups($flip = false)
    {
        return self::getGroupDao()->fetchUsersAndSubgroups($this->id, $this->getConnection());
    }

    /**
     * Returns an array of owner ids associated with this project.
     * @param   bool    $flip       if true array keys are the user ids (default is false)
     * @return  array   ids of all owners for this project
     */
    public function getOwners($flip = false)
    {
        return $this->getSortedField('owners', $flip);
    }

    /**
     * Returns true if this project has one or more owners and false otherwise.
     *
     * @return  bool    true if project has at least one owner, false otherwise
     */
    public function hasOwners()
    {
        return count($this->getOwners()) > 0;
    }

    /**
     * Set owners for this project.
     *
     * @param   array|null  the owners for this project
     * @return  Project     to maintain a fluent interface
     */
    public function setOwners($owners)
    {
        return $this->setRawValue('owners', $owners);
    }

    /**
     * Get a list of users that follow this project.
     * Members are implicitly followers but are not listed by default.
     *
     * @param   bool|array  $excludeMembers     optional - exclude members (defaults to true)
     *                                          the list of members can be given (useful for performance)
     * @param   null|array  $groups             If groups are passed in forward to DAO
     * @return  array       a list of ids of users that are following this project
     * @throws \P4\Exception
     */
    public function getFollowers($excludeMembers = true, $groups = null)
    {
        $followers = UserConfig::fetchFollowerIds(
            $this->getId(),
            'project',
            $this->getConnection()
        );

        // optionally exclude members
        if ($excludeMembers) {
            $members   = is_array($excludeMembers) ? $excludeMembers : $this->getAllMembers(false, $groups);
            $followers = array_diff($followers, $members);
        }

        sort($followers, SORT_STRING);
        return $followers;
    }

    /**
     * Set an array of member ids for this project.
     *
     * @param   array|null  $members    an array of members or null
     * @return  Project     to maintain a fluent interface
     */
    public function setMembers($members)
    {
        return $this->setRawValue(self::FIELD_MEMBERS, $members);
    }

    /**
     * Set an array of subgroup ids for this project.
     *
     * @param   array|null  $groups     an array of groups or null
     * @return  Project     to maintain a fluent interface
     * @throws  InvalidArgumentException    if groups contains project id
     */
    public function setSubgroups($groups)
    {
        if (in_array($this->id, $groups, true)) {
            throw new InvalidArgumentException("Cannot set project as a subgroup of itself.");
        }

        return $this->setRawValue(self::FIELD_SUBGROUPS, $groups);
    }

    /**
     * The resulting array with entries for all known branches.
     *
     * This will be an array of arrays. Each sub-array should contain
     * keys for: id, name, paths and moderators.
     *
     * @param   string|null     $sortField  optional - field to sort branches list on (using natural,
     *                                      case-insensitive sort)
     * @param   array           $mainlines  optional - branch names to appear on top of the list when sorted
     * @return  array           the branches for this project
     */
    public function getBranches($sortField = null, array $mainlines = [])
    {
        if (!is_null($sortField) && !is_string($sortField)) {
            throw new InvalidArgumentException('Invalid $sortField format: $sortField must be a string or null.');
        }

        // normalize the branches array we are about to return.
        // we do this on read as there is the unlikely possibility
        // the data was modified externally.
        $branches = (array) $this->getRawValue(self::FIELD_BRANCHES);
        foreach ($branches as $id => $branch) {
            $branch             += [self::FIELD_ID => null];
            $branch             += [
                self::FIELD_ID           => null,
                'name'                   => null,
                self::FIELD_BRANCH_PATHS => [],
                'moderators'             => [],
                'moderators-groups'      => [],
                'defaults'               => [],
                Project::FIELD_WORKFLOW  => null,
                Project::FIELD_RETAIN_DEFAULT_REVIEWERS => false,
                Project::FIELD_MINIMUM_UP_VOTES => null
            ];
            $branch['defaults'] += ['reviewers' => []];
            ksort($branch['defaults']['reviewers']);
            $branch[self::FIELD_BRANCH_PATHS] = (array) $branch[self::FIELD_BRANCH_PATHS];
            $branches[$id]                    = $branch;
        }

        $branches = $this->sortBranches($branches, $sortField, $mainlines);
        return $branches;
    }

    /**
     * Sort the Branches into order and put mainline first if required.
     *
     * @param array $branches      The list of branches to sort
     * @param null  $sortField     The field we want to sort by
     * @param array $mainlines     The mainlines we want to put at the top.
     * @param bool  $caseSensitive Should we treat mainlines and branch field caseSensitive.
     * @return array  Of sorted branches.
     */
    public function sortBranches($branches, $sortField, array $mainlines = [], $caseSensitive = false)
    {
        // Put the mainlines into caseSensitive if required.
        $mainlines = $caseSensitive ? $mainlines : array_map('strtolower', $mainlines);
        // sort branches with special handling for mainline branches (will appear first)
        if ($sortField) {
            usort(
                $branches,
                function ($a, $b) use ($sortField, $mainlines, $caseSensitive) {
                    if (!array_key_exists($sortField, $a) || !array_key_exists($sortField, $b)) {
                        throw new InvalidArgumentException("Cannot sort branches: branch has no '$sortField' field.");
                    }
                    // Put the field we are sorting by into caseSensitive if required.
                    $aSortField = $caseSensitive ? $a[$sortField] : strtolower($a[$sortField]);
                    $bSortField = $caseSensitive ? $b[$sortField] : strtolower($b[$sortField]);
                    if (in_array($aSortField, $mainlines)) {
                        return -1;
                    } elseif (in_array($bSortField, $mainlines)) {
                        return 1;
                    }
                    return strnatcasecmp($aSortField, $bSortField);
                }
            );
        }
        return $branches;
    }

    /**
     * Index the branch workflows
     * @param mixed     $branchWorkflows        branch workflows to index
     * @return array|string[]
     */
    public function indexBranchWorkflows($branchWorkflows)
    {
        return array_map(
            function ($branch) {
                return isset($branch[Project::FIELD_WORKFLOW]) ? $branch[Project::FIELD_WORKFLOW] : '';
            },
            ($branchWorkflows ? $branchWorkflows : [])
        );
    }

    /**
     * Get a particular branch definition.
     *
     * @param   string  $id         the id of the branch definition to get
     * @param   mixed   $branches   the branches to search. If not provided the branches to search will be built from
     *                              project->getBranches, this saves branch information being rebuilt if it is already
     *                              known
     * @return  array   the branch definition (id, name and paths)
     * @throws  InvalidArgumentException    if no such branch defined
     */
    public function getBranch($id, $branches = null)
    {
        $branches = $branches ? $branches : $this->getBranches();
        foreach ($branches as $branch) {
            if ($branch[self::FIELD_ID] === $id) {
                return $branch;
            }
        }

        throw new InvalidArgumentException("Cannot get branch '$id'. Branch is not defined.");
    }

    /**
     * Get a particular branch definition without an error if the branch is not found.
     *
     * @param   string  $id         the id of the branch definition to get
     * @param   mixed   $branches   the branches to search. If not provided the branches to search will be built from
     *                              project->getBranches, this saves branch information being rebuilt if it is already
     *                              known
     * @return array|null the branch definition or null if the branch is not found
     */
    public function branchExists(string $id, $branches = null)
    {
        try {
            return $this->getBranch($id, $branches);
        } catch (InvalidArgumentException $noBranchException) {
            // The branch name may have been modified since the caller became aware of
            // it and the caller does not have the new name. For an existence check
            // return null rather than throwing an exception
            return null;
        }
    }

    /**
     * Set a branches array for this project.
     *
     * This should be an array of arrays. Each sub-array should contain
     * keys for: id, default, name, paths and moderators.
     *
     * @param   array|null  $branches   the branches for this project
     * @return  Project     to maintain a fluent interface
     */
    public function setBranches($branches)
    {
        if (!empty($branches)) {
            // First loop though each branch entire we have.
            foreach ($branches as $key => &$branch) {
                if (isset($branch[Project::FIELD_RETAIN_DEFAULT_REVIEWERS])) {
                    $branch[Project::FIELD_RETAIN_DEFAULT_REVIEWERS] =
                        (bool)$branch[Project::FIELD_RETAIN_DEFAULT_REVIEWERS];
                }
                if (isset($branch['moderators'])) {
                    // Get the moderators and ensure unique members. Cast is required for backwards
                    // compatibility with API versions that allowed a string value
                    $originalModerators           = (array) $branch['moderators'];
                    $branches[$key]['moderators'] = array_values(array_unique($originalModerators));
                }

                if (isset($branch['moderators-groups'])) {
                    // Get the moderator groups and ensure unique groups.
                    $originalModeratorsGroups            = $branch['moderators-groups'];
                    $branches[$key]['moderators-groups'] = array_values(array_unique($originalModeratorsGroups));
                }

                if (isset($branch['defaults'])) {
                    $branch['defaults'] = $this->normaliseDefaults($branch['defaults']);
                }
            }
        }
        // Set and return the branches data.
        return $this->setRawValue(self::FIELD_BRANCHES, $branches);
    }

    /**
     * Get list of moderators from given branches of this project.
     *
     * @param array|null $branches optional - limit branches to collect moderators from
     * @param array|null $groups   optional - The groups we should use to check if is a moderator.
     * @return  array       list of moderators for specified branches
     * @throws \P4\Exception
     */
    public function getModerators(array $branches = null, $groups = null)
    {
        $moderators = [];
        foreach ((array) $this->getRawValue(self::FIELD_BRANCHES) as $branch) {
            if (is_null($branches) || in_array($branch[self::FIELD_ID], $branches)) {
                $branch += [
                    'moderators'            => [],
                    'moderators-groups'     => [],
                ];
                if ($branch['moderators']) {
                    $moderators = array_merge($moderators, (array)$branch['moderators']);
                }
                if ($branch['moderators-groups']) {
                    foreach ((array) $branch['moderators-groups'] as $moderatorGroup) {
                        $moderators = array_merge(
                            self::getGroupDao()->fetchAllMembers(
                                $moderatorGroup,
                                false,
                                $groups,
                                null,
                                $this->getConnection()
                            ),
                            $moderators
                        );
                    }
                }
            }
        }
        $uniqueModerators = [];
        if ($moderators) {
            $uniqueModerators = array_values(array_unique($moderators));
            sort($uniqueModerators, SORT_STRING);
        }
        return $uniqueModerators;
    }

    /**
     * Get list of user and groups as moderators from given branches of this project.
     *
     * @param   array|null  $branches   optional - limit branches to collect moderators from
     * @return  array       list of moderators for specified branches
     */
    public function getModeratorsWithGroups(array $branches = null)
    {
        $moderators['Users']  = [];
        $moderators['Groups'] = [];
        foreach ((array) $this->getRawValue(self::FIELD_BRANCHES) as $branch) {
            if (is_null($branches) || in_array($branch[self::FIELD_ID], $branches)) {
                $branch += [
                    'moderators'            => [],
                    'moderators-groups'     => [],
                ];

                $moderators['Users'] = array_merge($moderators['Users'], (array) $branch['moderators']);
                sort($moderators['Users'], SORT_STRING);
                if ($branch['moderators-groups']) {
                    $moderators['Groups'] = array_merge($moderators['Groups'], (array) $branch['moderators-groups']);
                    sort($moderators['Groups'], SORT_STRING);
                }
            }
        }
        return $moderators;
    }


    /**
     * The jobview for this project.
     *
     * @return  string|null     the jobview for this project.
     */
    public function getJobview()
    {
        return $this->getRawValue('jobview');
    }

    /**
     * Set a jobview for this project.
     *
     * @param   string|null     $jobview    the jobview for this project or null
     * @return  Project         to maintain a fluent interface
     */
    public function setJobview($jobview)
    {
        return $this->setRawValue('jobview', $jobview);
    }

    /**
     * Returns an array of email/notification flags set on this project.
     *
     * @return  array   names for all email flags currently set on this project
     */
    public function getEmailFlags()
    {
        return (array) $this->getRawValue('emailFlags');
    }

    /**
     * Returns the value of the specified email flag, if it exists, or null if it could not be found.
     *
     * @param   string      $flag   specific email flag we are looking for
     * @return  mixed|null  value of the flag if found, or null if the flag was not found
     */
    public function getEmailFlag($flag)
    {
        $emailFlags = $this->getEmailFlags();
        return isset($emailFlags[$flag]) ? $emailFlags[$flag] : null;
    }

    /**
     * Set an array of active email/notification flags on this comment.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  Project     to maintain a fluent interface
     */
    public function setEmailFlags($flags)
    {
        return $this->setRawValue('emailFlags', (array)$flags);
    }

    /**
     * An array containing the keys 'enabled' and 'url'
     * to reflect the test settings.
     *
     * @param   string|null     $key    optional - a specific key to retrieve
     * @return  array|null      an array with keys for enabled and url.
     */
    public function getTests($key = null)
    {
        $values = (array) $this->getRawValue(self::FIELD_TESTS) + ['enabled' => false, 'url' => null];

        // handle 2015.4 to 2016.1 upgrade on the fly
        // - renamed 'postParams' key to 'postBody'
        // - renamed 'postFormat' value 'GET' to 'URL'
        if (array_key_exists('postParams', $values) && !array_key_exists('postBody', $values)) {
            $values['postBody'] = $values['postParams'];
            unset($values['postParams']);
        }
        if (array_key_exists('postFormat', $values) && $values['postFormat'] === 'GET') {
            $values['postFormat'] = EncodingValidator::URL;
        }

        if ($key) {
            return isset($values[$key]) ? $values[$key] : null;
        }

        return $values;
    }

    /**
     * Set tests enabled and url properties.
     *
     * @param   array|null  $tests  array with keys for enabled and url or null
     * @return  Project     to maintain a fluent interface
     */
    public function setTests($tests)
    {
        return $this->setRawValue(self::FIELD_TESTS, $tests);
    }

    /**
     * An array containing the keys 'enabled' and 'url'
     * to reflect the deployment settings.
     *
     * @param   string|null     $key    optional - a specific key to retrieve
     * @return  array|null      an array with keys for enabled and url.
     */
    public function getDeploy($key = null)
    {
        $values = (array) $this->getRawValue('deploy') + ['enabled' => false, 'url' => null];

        if ($key) {
            return isset($values[$key]) ? $values[$key] : null;
        }

        return $values;
    }

    /**
     * Set deployment enabled and url properties.
     *
     * @param   array|null  $deploy     array with keys for enabled and url or null
     * @return  Project     to maintain a fluent interface
     */
    public function setDeploy($deploy)
    {
        return $this->setRawValue('deploy', $deploy);
    }

    /**
     * Boolean value indicating whether this project is deleted.
     *
     * There might be records missing this field (it has been added later).
     * We convert missing values to false indicating the project is considered to
     * be deleted only if the 'deleted' field is present and its value is true.
     *
     * @return  boolean     true if this projects is deleted, false otherwise
     */
    public function isDeleted()
    {
        return (bool) $this->getRawValue('deleted');
    }

    /**
     * Set whether this project is deleted (true) or not (false).
     *
     * @param   boolean     $deleted    pass true to indicate that this project is deleted
     *                                  and false to indicate that this project is active
     * @return  Project     to maintain a fluent interface
     */
    public function setDeleted($deleted)
    {
        return $this->setRawValue('deleted', (bool) $deleted);
    }

    /**
     * Boolean value indicating whether this project is private.
     *
     * There might be records missing this field (it has been added later).
     * We convert missing values to false indicating the project is considered to
     * be public by default.
     *
     * Private projects are visible to members and owners only.
     *
     * @return  boolean     true if this projects is private, false otherwise
     */
    public function isPrivate()
    {
        return (bool) $this->getRawValue(self::FIELD_PRIVATE);
    }

    /**
     * Set whether this project is private (true) or not (false).
     *
     * @param   boolean     $private    pass true to indicate that this project is private
     *                                  and false to indicate that this project is public
     * @return  Project     to maintain a fluent interface
     */
    public function setPrivate($private)
    {
        return $this->setRawValue(self::FIELD_PRIVATE, (bool) $private);
    }

    /**
     * Return client specific for this project.
     * Each branch of this project is mapped as top-level folder in client's view:
     *
     *  <branch-path> //<client-id>/<branch-id>/...
     *
     * At the moment, branches with multiple paths are mapped to the same folder.
     * Client will be created if doesn't exist.
     *
     * @param bool $ignoreCommonPath  Ignore Common path to help get correct change.
     *
     * @return string   name of the client specific to this project
     * @throws \P4\Exception
     */
    public function getClient($ignoreCommonPath = false)
    {
        $p4     = $this->getConnection();
        $client = 'swarm-project-' . $this->getId();
        // prepare view mappings based on the project's branches
        $view = [];
        foreach ($this->getBranches() as $branch) {
            $id = $branch[self::FIELD_ID];
            // Get the Common Path
            $commonPath = $ignoreCommonPath
                ? '//'
                : File::getCommonPath($branch[self::FIELD_BRANCH_PATHS], $p4->isCaseSensitive());
            foreach ($branch[self::FIELD_BRANCH_PATHS] as $path) {
                $this->buildClientView($view, $path, $commonPath, $client, $id);
            }
        }
        return $this->createOrUpdateClient($p4, $client, $view);
    }

    /**
     * Return client specific for this project branch.
     *
     * Client will be created if doesn't exist.
     *
     * @param int  $id                This is the branch ID
     * @param bool $ignoreCommonPath  Ignore Common path to help get correct change.
     *
     * @return mixed
     * @throws \P4\Exception
     */
    public function getBranchClient($id, $ignoreCommonPath = false)
    {
        $p4             = $this->getConnection();
        $client         = 'swarm-project-' . $this->getId();
        $interestBranch = $this->getBranch($id);
        // prepare view mappings based on the project's branches
        $view = [];
        // Get the Common Path
        $commonPath = $ignoreCommonPath
            ? '//'
            : File::getCommonPath($interestBranch[self::FIELD_BRANCH_PATHS], $p4->isCaseSensitive());
        foreach ($interestBranch[self::FIELD_BRANCH_PATHS] as $path) {
            $this->buildClientView($view, $path, $commonPath, $client, $id);
        }
        return $this->createOrUpdateClient($p4, $client, $view);
    }

    /**
     * Build me the client view for the paths given.
     *
     * @param array  $view        This is the client view that has been built and appended to.
     * @param string $path        The path that we add build a client mapping for.
     * @param string $commonPath  This is the common path for all the paths in this branch.
     * @param string $client      The client name.
     * @param int    $id          The branch ID.
     */
    private function buildClientView(&$view, $path, $commonPath, $client, $id)
    {
        // Find if the path starts with a '+' or '-' sign and remove from path
        preg_match('#^(-|\+){1}//([^/]+)#', $path, $match);
        $sign = null;
        if ($match) {
            $sign = $match[1];
            $path = substr($path, 1);
        }
        // First remove the common Path from the path
        $clientSide = substr($path, strlen($commonPath));
        // Take the current wildcard or file name
        $baseName = basename($path);
        // Check if the clientSide has a forward slash and remove if it does.
        $clientSide = substr($clientSide, 0, 1) !== '/' ? $clientSide : substr($clientSide, 1);
        // Build the client view for this path
        $view[] = '"' . $sign . $path . '" "//' . $client . '/' . $id . '/'
            . ($sign === '+' ? $baseName : $clientSide). '"';
    }

    /**
     * Create or updated existing client with new view.
     *
     * @param Connection $p4     The p4 connection to be used.
     * @param string     $client The client name.
     * @param array      $view   This is the client view that has been built and will be used.
     *
     * @return mixed
     */
    private function createOrUpdateClient($p4, $client, $view)
    {
        // normalize and verify the client view spec
        $data = $p4->run('client', ['-o', $client])->expandSequences()->getData(-1);
        $old  = new Client;
        $old->setView((array) $data['View']);
        $new = new Client;
        $new->setView($view);

        if ($old->getView() != $new->getView() || !isset($data['Update'])) {
            $p4->run(
                'client',
                '-i',
                [
                    'Host' => '',
                    'View' => $view,
                    'Root' => DATA_PATH . '/tmp'
                ] + $data
            );
        }

        return $client;
    }

    /**
     * Determine which projects are affected by the given job.
     *
     * @param Job        $job      the job to examine
     * @param Connection $p4       the perforce connection to use
     * @param Project    $projects The list of projects if any.
     * @return  array       a list of affected projects as values (auto-incrementing keys).
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\Exception
     */
    public static function getAffectedByJob(Job $job, Connection $p4, $projects = null)
    {
        // loop over projects and, for those with a valid job view,
        // see which are impacted by the passed job.
        $projects = $projects !== null ? $projects : static::fetchAll([], $p4);
        $affected = [];
        foreach ($projects as $project) {
            // extract the job view and break out the various field=value filter(s) on whitespace
            // we generate a conditions array with field ids as keys and a regex pattern as value
            $matched = false;
            $jobview = trim($project->getJobview());
            $filters = preg_split('/\s+/', $jobview);
            $fields  = array_combine(
                array_map('strtolower', $job->getFields()),
                $job->getFields()
            );
            foreach ($filters as $filter) {
                if (!preg_match('/^([^=()|]+)=([^=()|]+)$/', $filter, $matches)) {
                    continue;
                }

                // we escape the pattern but re-activate originally un-escaped '*'
                // characters as being wildcard matches
                list(, $field, $pattern) = $matches;
                $field                   = strtolower($field);
                $pattern                 = '/^' . preg_quote($pattern, '/') . '$/i';
                $pattern                 = preg_replace('/(^|[^\\\\])\\\\\*/', '$1.*', $pattern);

                // if the job lacks the requested field or pattern doesn't match; skip this project
                // we use the 'fields' array to do a case insensitive lookup of the field name
                if (!isset($fields[$field]) || !preg_match($pattern, $job->get($fields[$field]))) {
                    continue 2;
                }

                $matched = true;
            }

            // only include the project if it matched at least one expression. we don't
            // want projects that lack a job view, or contain only invalid views, to hit.
            if ($matched) {
                $affected[] = $project->getId();
            }
        }

        return $affected;
    }

    /**
     * Extends parent to also save the group if server allows admins to do so.
     *
     * @return  Project     to maintain a fluent interface
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\Exception
     */
    public function save()
    {
        // if admins can manage groups, mark the members field as not being
        // directly stored on the key to avoid data duplication.
        $this->fields[self::FIELD_MEMBERS]['unstored'] = true;

        // if the server is too old for admins to manage groups or the 'members'
        // and 'subgroups' fields have not been populated, we're done
        if ((!array_key_exists(self::FIELD_MEMBERS,   $this->values) &&
             !array_key_exists(self::FIELD_SUBGROUPS, $this->values))
        ) {
            parent::save();
            return $this;
        }
        $groupDao = self::getGroupDao();
        // attempt to fetch any existing group with this projects raw id
        $group = false;
        $isAdd = false;
        try {
            $group = $groupDao->fetchById($this->id, $this->getConnection());
        } catch (P4SpectNotFoundException $e) {
            unset($e);
        } catch (InvalidArgumentException $e) {
            unset($e);
        }

        // early exit if member and subgroups lists haven't changed
        if ($group
            && $group->getUsers() == $this->getRawValue(self::FIELD_MEMBERS)
            && $group->getSubgroups() == $this->getRawValue(self::FIELD_SUBGROUPS)
        ) {
            parent::save();
            return $this;
        }

        // if the fetch failed, setup a new group as its an add
        if (!$group) {
            $isAdd = true;
            $group = new Group($this->getConnection());
            $group->setId($this->id);
            $group->addOwner($this->getConnection()->getUser());
        }

        // ensure the group has the new list of members and subgroups
        $group->setUsers((array) $this->getRawValue(self::FIELD_MEMBERS));
        $group = $group->setSubgroups((array) $this->getRawValue(self::FIELD_SUBGROUPS));

        // if this is an edit and we're an owner pass editAsOwner = true to allow admin access
        // if this is an add and we're not a super user pass addAsAdmin = true to improve our chances
        $groupDao->save(
            $group,
            !$isAdd && in_array($this->getConnection()->getUser(), $group->getOwners()),
            $isAdd  && !$this->getConnection()->isSuperUser()
        );

        parent::save();
        return $this;
    }

    /**
     * Tests whether the given user is a member of this project.
     *
     * @param string      $userId ID of the user we are checking membership
     * @param null|array  $groups The groups to be used to search for members.
     * @return  bool    whether or not the user is a member of this project
     * @throws \P4\Exception
     */
    public function isMember($userId, $groups = null)
    {
        if (!$userId) {
            return false;
        }
        $members = $this->getAllMembers(false, $groups);
        return $this->getConnection()->stringMatches($userId, $members);
    }

    /**
     * Tests whether the given user is an owner of this project.
     *
     * @param   string  $userId     ID of the user we are checking ownership
     * @return  bool    whether or not the user is an owner of this project
     */
    public function isOwner($userId)
    {
        if (!$userId) {
            return false;
        }
        $owners = $this->getOwners();
        return in_array($userId, $owners);
    }

    /**
     * Tests whether the given user is following this project.
     *
     * @param string        $userId ID of the user we are checking
     * @param array|null    $groups optional - The groups we should use to check
     * @return  bool    whether or not the user is a follower of this project
     * @throws \P4\Exception
     */
    public function isFollowing($userId, $groups = null)
    {
        if (!$userId) {
            return false;
        }
        $members   = $this->getAllMembers(false, $groups);
        $followers = $this->getFollowers($members, $groups);
        return in_array($userId, $followers);
    }

    /**
     * Tests whether the given user is a moderator of this project.
     *
     * @param string     $userId   ID of the user we are checking
     * @param array|null $branches optional - limit branches to collect moderators from
     * @param array|null $groups   optional - The groups we should use to check if is a moderator.
     * @return  bool    whether or not the user is a moderator of this project
     * @throws \P4\Exception
     */
    public function isModerator($userId, array $branches = null, $groups = null)
    {
        if (!$userId) {
            return false;
        }
        $moderators = $this->getModerators($branches, $groups);
        return in_array($userId, $moderators);
    }

    /**
     * Gets Membership levels of this project.
     *
     * @param string        $userId     ID of the user we are checking the membership
     * @param bool          $asArray    Whether to return results as an array or imploded string
     * @param array|null    $groups     optional - The groups we should use to check if the user is involved
     * @return  string  list of membership
     * @throws \P4\Exception
     */
    public function getMembershipLevels($userId, $asArray = false, $groups = null)
    {
        if (!$userId) {
            return false;
        }
        $membershipLevels = [];
        if ($this->isOwner($userId)) {
            array_push($membershipLevels, 'Owner');
        }
        if ($this->isMember($userId, $groups)) {
            array_push($membershipLevels, 'Member');
        }
        if ($this->isFollowing($userId, $groups)) {
            array_push($membershipLevels, 'Following');
        }
        if ($this->isModerator($userId, null, $groups)) {
            array_push($membershipLevels, 'Moderator');
        }

        return $asArray === true ? $membershipLevels : implode(", ", $membershipLevels);
    }

    /**
     * Indicate whether a user has any of the given membership level for a project.
     *
     * @param User        $user    User object to query
     * @param array|null  $roles   optional - A list of roles to check for involvement
     * @param array|null  $groups  optional - The groups we should use to check if the user is involved
     * @return  bool    true/false  involved/not involved
     * @throws \P4\Exception
     */
    public function isInvolved(User $user, $roles = null, $groups = null)
    {
        $involved = false;
        if ($user) {
            $id           = $user->getId();
            $rolesToCheck = Project::$projectRoles;
            if ($roles) {
                $rolesToCheck = array_intersect($rolesToCheck, $roles);
            }
            // Check members/owners first so we can exit early if found
            $involved = in_array(Project::MEMBERSHIP_LEVEL_MEMBER, $rolesToCheck) && $this->isMember($id, $groups) ||
                        in_array(Project::MEMBERSHIP_LEVEL_OWNER,  $rolesToCheck) && $this->isOwner($id);
            if (!$involved) {
                $follows  =
                    in_array(Project::MEMBERSHIP_LEVEL_FOLLOWER, $rolesToCheck)
                        ? $user->getConfig()->getFollows('project')
                        : null;
                $involved =
                    $follows && in_array($this->getId(), $follows) ||
                    (in_array(Project::MEMBERSHIP_LEVEL_MODERATOR, $rolesToCheck)
                        && $this->isModerator($id, null, $groups));
            }
        }
        return $involved;
    }

    /**
     * Gets Membership level of this project for sorting.
     *
     * @param string        $userId     ID of the user we are checking the membership
     * @param array|null    $groups     optional - The groups we should use to check if the user is involved
     * @return  int     level for sorting
     * @throws \P4\Exception
     */
    public function getMembershipLevelForSort($userId, $groups = null)
    {
        if (!$groups) {
            $groupDao = self::getGroupDao();
            $groups   = $groupDao->fetchAll([], $this->getConnection());
        }
        if ($this->isOwner($userId)) {
            return 1;
        } elseif ($this->isMember($userId, $groups)) {
            return 2;
        } elseif ($this->isFollowing($userId, $groups)) {
            return 3;
        } elseif ($this->isModerator($userId, null, $groups)) {
            return 4;
        }
        return 0;
    }

    /**
     * Fetch project group to populate members and subgroups if needed.
     *
     * On 2012.1+ p4d instances members and subgroups are stored in a group
     * with the id swarm-project-<projectId>. On servers older than 2012.1
     * admin users are unable to manage groups so members are stored in the
     * project key and subgroups are not supported.
     */
    protected function loadGroup()
    {
        $setMembers   = !array_key_exists(self::FIELD_MEMBERS,   $this->values) && $this->needsGroup;
        $setSubgroups = !array_key_exists(self::FIELD_SUBGROUPS, $this->values) && $this->needsGroup;

        if (!$setMembers && !$setSubgroups) {
            return;
        }

        $members   = [];
        $subgroups = [];
        try {
            $groupDao  = self::getGroupDao();
            $group     = $groupDao->fetchById($this->id, $this->getConnection());
            $members   = $group->getUsers();
            $subgroups = $group->getSubgroups();
        } catch (\P4\Spec\Exception\NotFoundException $e) {
        } catch (InvalidArgumentException $e) {
        }

        $this->setRawValue(self::FIELD_MEMBERS,   $setMembers   ? $members   : $this->getRawValue(self::FIELD_MEMBERS));
        $this->setRawValue(
            self::FIELD_SUBGROUPS,
            $setSubgroups
            ? $subgroups : $this->getRawValue(self::FIELD_SUBGROUPS)
        );
        $this->needsGroup = false;
    }

    /**
     * Extends parent to flag models as requiring a group populate if
     * the server version is new enough to make it possible for admins.
     *
     * @param   Key             $key        the key to record'ize
     * @param   string|callable $className  ignored
     * @return  AbstractKey     the record based on the passed key's data
     */
    protected static function keyToModel($key, $className = null)
    {
        $model             = parent::keyToModel($key);
        $model->needsGroup = true;
        return $model;
    }

    /**
     * Gets the project part of 'swarm-project-xxx'
     * @param mixed $projectName the project name
     * @return mixed the stripped project if it is a project or the value if not. Note that
     * preg_replace will return a string so if project name is 1 then "1" will be returned
     */
    public static function getProjectName($projectName)
    {
        return preg_replace('/^' . Project::KEY_PREFIX . '/', '', $projectName);
    }

    /**
     * Tests to see if the name starts with 'swarm-project-'
     * @param mixed $projectName the project name
     * @return bool true if the name is a Swarm project name
     * @see Project::getProjectName()
     */
    public static function isProjectName($projectName)
    {
        // Do a loose comparison so that integer values compare correctly
        // For example getProjectName returns "1" for 1 and matching strictly would return false
        return Project::getProjectName($projectName) != $projectName;
    }

    /**
     * Gets the workflow id defined at the project level or on a branch if the branch id is provided.
     * A workflow is optional so this may return null when no workflow is set
     * @param string            $branchId       Optional value. If provided and the branch is found
     *                                          the workflow for the branch will be returned.
     *                                          If a workflow is not set on the branch it will fall back to the workflow
     *                                          at the project level (this may also be not set)
     * @param mixed             $branches       the branches to search. If not provided the branches to search will be
     *                                          built from project->getBranches, this saves branch information being
     *                                          rebuilt if it is already known
     * @return  string|null     the workflow id or null if the workflow was not found.
     */
    public function getWorkflow($branchId = null, $branches = null)
    {
        $workflowId = $this->getRawValue(Project::FIELD_WORKFLOW);
        if ($branchId) {
            $branch = $this->branchExists($branchId, $branches);
            if ($branch && isset($branch[Project::FIELD_WORKFLOW])) {
                $workflowId = $branch[Project::FIELD_WORKFLOW];
            }
        }
        return $workflowId;
    }

    /**
     * Links a project to a workflow by specifying the workflow id.
     * @param string    $workflowId     the id for the workflow to link to the project
     * @return mixed the project
     */
    public function setWorkflow($workflowId)
    {
        return $this->setRawValue(Project::FIELD_WORKFLOW, $workflowId);
    }

    /**
     * Sets whether default project level reviewers are retained.
     * @param bool  $retained   true to retain
     * @return AbstractKey
     */
    public function setRetainDefaultReviewers($retained)
    {
        return $this->setRawValue(Project::FIELD_RETAIN_DEFAULT_REVIEWERS, (bool)$retained);
    }

    /**
     * Gets whether default reviewers are retained
     * @param null|string $branchId     if provided the retention value from the branch is returned if the branch is
     *                                  found. Branch values are not inherited from the project
     * @return bool
     */
    public function areDefaultReviewersRetained($branchId = null)
    {
        $retained = false;
        if ($branchId) {
            $branch = $this->branchExists($branchId);
            if ($branch && isset($branch[Project::FIELD_RETAIN_DEFAULT_REVIEWERS])) {
                $retained = (bool)$branch[Project::FIELD_RETAIN_DEFAULT_REVIEWERS];
            }
        } else {
            $retained = (bool)$this->getRawValue(Project::FIELD_RETAIN_DEFAULT_REVIEWERS);
        }
        return $retained;
    }

    /**
     * Get the Minimum UpVotes required.
     *
     * @param null|string   $branchId       if provided the minimum up vote value from the branch is returned if the
     *                                      branch is found. Branch values are not inherited from the project
     * @param mixed         $branches       the branches to search. If not provided the branches to search will be built
     *                                      from project->getBranches
     * @return array|mixed|null
     */
    public function getMinimumUpVotes($branchId = null, $branches = null)
    {
        $minimumUpVotes = $this->getRawValue(Project::FIELD_MINIMUM_UP_VOTES);

        if ($branchId) {
            $branch = $this->branchExists($branchId, $branches);
            if ($branch && isset($branch[Project::FIELD_MINIMUM_UP_VOTES])) {
                $minimumUpVotes = $branch[Project::FIELD_MINIMUM_UP_VOTES];
            }
        }
        return $minimumUpVotes;
    }

    /**
     * Set the Minimum UpVotes required.
     * @param mixed $total This is the value that is going to be saved for the minimum up vote.
     *
     * @return AbstractKey
     */
    public function setMinimumUpVotes($total)
    {
        return $this->setRawValue(Project::FIELD_MINIMUM_UP_VOTES, $total);
    }

    /**
     * Diff the original and new branches to produce a list of paths with their referring project branch ID(s).
     *
     * @return array An array of arrays
     */
    public function getUpdatedPaths()
    {
        $original = isset($this->original[self::FIELD_BRANCHES])
            ? array_column($this->original[self::FIELD_BRANCHES], null, self::FIELD_ID) : [];
        return static::diffPathArrays($this->getBranches(), $original);
    }

    /**
     * Process all of the branches of a project to produce a list of paths with their referring branch ids.
     *
     * @return array An array of arrays
     */
    public function getPaths()
    {
        return static::diffPathArrays($this->getBranches());
    }

    /**
     * Generate the path differences difference between 2 sets of branch/path mappings.
     * @param array $allBranches   the full set of branch mappings
     * @param array $original      optional set of mappings to be ignored
     * @return array
     */
    protected static function diffPathArrays($allBranches, $original = [])
    {
        $allPaths  = [];
        $pathDiffs = [];
        foreach ($allBranches as $branch) {
            $addedPaths   = [];
            $removedPaths = [];
            $branchId     = $branch[self::FIELD_ID];
            $branchPaths  = $branch[self::FIELD_BRANCH_PATHS];
            $allPaths     = array_merge_recursive(
                $allPaths,
                array_fill_keys(BranchPath::trimWildcards($branchPaths), [$branchId])
            );

            if (array_key_exists($branchId, $original)) {
                // Need to know whether the paths have changed
                $excludedPaths = (array)$original[$branch[self::FIELD_ID]][self::FIELD_BRANCH_PATHS];
                if ($branchPaths !== $excludedPaths) {
                    $removedPaths = array_diff($excludedPaths, $branchPaths);
                    $addedPaths   = array_diff($branchPaths, $excludedPaths);
                }
            } else {
                // This is a new branch
                $addedPaths = $branchPaths;
            }
            // Merge the removed and added paths into the updated path list
            $pathDiffs = array_merge_recursive(
                $pathDiffs,
                array_fill_keys(BranchPath::trimWildcards($addedPaths), [$branchId]),
                array_fill_keys(BranchPath::trimWildcards($removedPaths), [])
            );
        }

        return array_filter(
            array_merge($pathDiffs, array_intersect_key($allPaths, $pathDiffs)),
            function ($path) {
                return strpos($path, '-') !== 0;
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Gets a summary representation of the project by setting fields to empty values to limit use of
     * machine resources
     * @param array $excludedFields fields to exclude. Currently supports FIELD_BRANCHES to set branches to an empty
     *                              array or FIELD_BRANCH_PATHS to keep branch data but set the path array on each
     *                              branch to be empty
     * @return $this
     */
    public function getSummary(array $excludedFields)
    {
        $excludeBranches = in_array(self::FIELD_BRANCHES, $excludedFields);
        if ($excludeBranches) {
            $this->setBranches([]);
        }
        if (!$excludeBranches && in_array(self::FIELD_BRANCH_PATHS, $excludedFields)) {
            $this->setBranches(
                array_map(
                    function ($branch) {
                        $branch[self::FIELD_BRANCH_PATHS] = [];
                        return $branch;
                    },
                    (array) $this->getRawValue(self::FIELD_BRANCHES)
                )
            );
        }
        return $this;
    }
}
