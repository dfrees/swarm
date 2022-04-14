<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

use Application\Config\ConfigException;
use Application\Connection\ConnectionFactory;
use Application\Permissions\ConfigCheck;
use Groups\Model\Config;
use Groups\Model\Group;
use Groups\Model\IGroup;
use InvalidArgumentException;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator;
use P4\Spec\Exception\NotFoundException;
use P4\Validate\GroupName;
use P4\Validate\UserName;
use Projects\Model\Project;
use Redis\RedisService;
use P4\Exception as P4Exception;

/**
 * DAO to handle finding/saving groups.
 * @package Redis\Model
 */
class GroupDAO extends PluralAbstractDAO
{
    use SearchEntryTrait;
    // The key for the populated status of the group dataset
    const POPULATED_STATUS = IGroup::GROUP . "-" . AbstractDAO::POPULATED_STATUS;
    // The main key prefix to reference an individual record, for example 'group^fred'
    const CACHE_KEY_PREFIX = IGroup::GROUP . RedisService::SEPARATOR;
    // The Perforce class that handles users
    const MODEL = Group::class;
    // The key for the verify status of the group dataset
    const VERIFY_STATUS = IGroup::GROUP . "-" . AbstractDAO::VERIFY_STATUS;
    // The key used to index groups for starts with searches, within a given namespace
    const SEARCH_STARTS_WITH_KEY = AbstractDAO::SEARCH_STARTS_WITH . RedisService::SEPARATOR . IGroup::GROUP;
    // The key used to index groups for includes searches, within a given namespace
    const SEARCH_INCLUDES_KEY = AbstractDAO::SEARCH_INCLUDES . RedisService::SEPARATOR . IGroup::GROUP;

    private $groups = [];

    /**
     * Directly call the groups command to fetch the groups Perforce thinks this user is part of.
     * It is much quicker than using redis to fetch all and then go though each to check inclusion.
     * Investigation of SW-8906 has found that calling Perforce is better.
     * @param array $options
     * @param ConnectionInterface|null $connection
     * @return array|false|string
     */
    public function fetchUserGroups(array $options = [], ConnectionInterface $connection = null)
    {
        $options = (array)$options + [
                Group::FETCH_BY_USER      => null,
                Group::FETCH_INDIRECT     => null,
            ];
        // build the flags for the groups command. At present we will always add -i and -u <User> but incase
        // we need to have edge cases that doesn't want indirect we can change that here.
        $flags = [];
        if (isset($options[Group::FETCH_INDIRECT])) {
            $flags[] ="-i";
        }
        if (isset($options[Group::FETCH_BY_USER])) {
            $flags[] = "-u";
            $flags[] = $options[Group::FETCH_BY_USER];
        }
        // Get the groups list form P4D
        $rawGroupList = $connection->run("groups", $flags)->getData();
        // Now we get the models from redis for just the groups we are interested in.
        return parent::fetchAll(
            [
                Group::FETCH_BY_IDS => array_unique(
                    array_map(
                        function ($o) {
                            return $o['group'];
                        },
                        $rawGroupList
                    )
                )
            ],
            $connection
        );
    }

    /**
     * Fetch All the groups and filter out based on the options passed.
     *
     * @param array                    $options  Supported options are:
     *     FETCH_MAXIMUM      - set to integer value to limit to the first 'max' number of entries.
     *                          Note: Limits imposed client side.
     *     FETCH_BY_MEMBER    - Not supported
     *     FETCH_BY_USER      - get groups containing passed user (no wildcards).
     *     FETCH_INDIRECT     - used with FETCH_BY_MEMBER or FETCH_BY_USER to also list indirect matches.
     *     FETCH_BY_NAME      - get the named group. essentially a 'fetch' but performed differently (no wildcards).
     *                          Note: not compatible with FETCH_BY_MEMBER, FETCH_BY_USER or FETCH_INDIRECT
     *     FETCH_NO_CACHE     - set to true to avoid using the cache.
     *     FETCH_BY_USER_MODE - Used with FETCH_BY_USER. 'member' will check member field, 'owner' will check owner
     *                          field 'all' will check members and owners. Not set will default to user.
     * @param ConnectionInterface|null $connection  The specific connection to use.
     * @return mixed|Iterator
     * @throws ConfigException|P4Exception
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        // Much faster to issue a single command to Perforce for group inclusion then using redis.
        if (isset($options[Group::FETCH_BY_USER]) && isset($options[Group::FETCH_INDIRECT])) {
            return $this->fetchUserGroups($options, $connection);
        }
        if (!$this->groups && !isset($options[self::FETCH_SEARCH])) {
            $this->groups = parent::fetchAll($options, $connection)->toArray(true);
        }
        // now that parent is done with options; normalize them
        // if we do this earlier it will cause issues with parent
        $options += [
                Group::FETCH_MAXIMUM        => null,
                Group::FETCH_BY_MEMBER      => null,
                Group::FETCH_BY_USER        => null,
                Group::FETCH_INDIRECT       => null,
                Group::FETCH_BY_NAME        => null,
                Group::FETCH_BY_USER_MODE   => Group::USER_MODE_USER,
                self::FETCH_SEARCH          => null,
                Group::FETCH_BY_EXPAND      => null
            ];

        // If we are searching, just return the output of the search method.
        if (isset($options[self::FETCH_SEARCH])) {
            return $this->search($options);
        }

        // always going to have an iterator as a result at this point; make it
        $results = new Iterator;
        // Fetch by name is essentially a fetch that returns an iterator
        // handle that case early as it is simple
        if ($options[Group::FETCH_BY_NAME]) {
            // 'name' is aliased to FETCH_BY_IDS so it is possible there are multiple names
            $ids = (array)$options[Group::FETCH_BY_NAME];
            foreach ($ids as $id) {
                if (isset($this->groups[$id])) {
                    $results[$id] = $this->groups[$id];
                }
            }
        } else {
            // turn group arrays into objects and apply various filters if present
            $limit    = $options[Group::FETCH_MAXIMUM];
            $user     = $options[Group::FETCH_BY_USER];
            $indirect = $options[Group::FETCH_INDIRECT];
            foreach ($this->groups as $id => $group) {
                // if max limiting, stop when/if we exceed max
                if ($limit && count($results) >= $limit) {
                    break;
                }
                // if filtering by member, exclude groups that don't match
                if ($user &&
                    !$this->isMember(
                        $user,
                        $id,
                        $indirect,
                        $connection,
                        null,
                        $options[Group::FETCH_BY_USER_MODE],
                        $this->groups
                    )
                ) {
                    continue;
                }
                // passes the filters, lets add it to the result
                $results[$id] = $group;
            }
        }
        if ($options[Group::FETCH_BY_EXPAND]) {
            $resultsArray = $results->toArray(true);
            $ids          = array_keys($resultsArray);
            foreach ($ids as $id) {
                $this->fetchWithSubgroups($id, $connection, $resultsArray);
            }
            $results = new Iterator($resultsArray);
        }
        // TODO We are dynamically sorting every time. When we have functional equivalence we should look at redis
        // TODO sorting to avoid unserialize
        return isset($options[self::SORT]) ? $this->sort($options[self::SORT], $results) : $results;
    }

    /**
     * @inheritDoc
     * Calls the inherited search and provides additional functionality:
     *  -   Add description to formatted search results
     */
    protected function search(array $options, ConnectionInterface $connection = null)
    {
        $groups        = parent::search($options, $connection);
        $searchOptions = $options[self::FETCH_SEARCH];
        $raw           = (bool)($searchOptions[self::SEARCH_RETURN_RAW_ENTRIES] ?? false);
        $ids           = $this->getSearchResultIds($groups, Group::FIELD_ID, $raw);
        if (!$raw) {
            $groupModels = $this->fetchAll(
                [
                    Group::FETCH_BY_IDS => array_unique($ids)
                ],
                $connection
            );
            foreach ($groupModels as $groupModel) {
                foreach ($groups as &$group) {
                    if ($groupModel->getId() === $this->getSearchResultId($group, Group::FIELD_ID, false)) {
                        $description                      = $groupModel->getConfig()->getDescription();
                        $group[Config::FIELD_DESCRIPTION] = $description ? $description : "";
                        break;
                    }
                }
            }
        }
        return $groups;
    }

    /**
     * Sort the groups by the sort by fields
     * @param array     $sortBy     sort by fields
     * @param Iterator  $groups     groups iterator
     * @return mixed
     */
    private function sort(array $sortBy, $groups)
    {
        return $groups->sortByCallback(
            function ($a, $b) use ($sortBy) {
                foreach ($sortBy as $field => $reverse) {
                    switch ($field) {
                        case 'isEmailEnabled':
                            $aFlags = $a->getConfig()->getEmailFlags() ?? [];
                            $bFlags = $b->getConfig()->getEmailFlags() ?? [];
                            $aValue = (bool)array_filter($aFlags);
                            $bValue = (bool)array_filter($bFlags);
                            break;
                        case 'name':
                            $aValue = $a->getConfig()->getName() ?? $a->getId();
                            $bValue = $b->getConfig()->getName() ?? $b->getId();
                            break;
                        case 'isInGroup':
                            // isInGroup is a 'special' field passed through that isn't actually a group field and
                            // is handled elsewhere. We don't want the overhead of checking fields for this
                            $aValue = null;
                            $bValue = null;
                            break;
                        default:
                            $aValue = $a->hasField($field) ? $a->get($field) : null;
                            $bValue = $b->hasField($field) ? $b->get($field) : null;
                            break;
                    }
                    $order = strnatcasecmp($aValue, $bValue);
                    if ($order) {
                        return $order * ($reverse ? -1 : 1);
                    }
                }
                return 0;
            }
        );
    }

    /**
     * Indicate whether the given user is relevant to the given group, with the option of traversing any sub-groups.
     * N.B. relevance is determined by userMode of member/owner/either
     *
     * @param string              $user       the user id to check membership for
     * @param string              $group      the group id we are looking in
     * @param bool                $recursive  true if we are also checking sub-groups,
     *                                        false for only testing direct membership
     * @param ConnectionInterface $connection optional - a specific connection to use.
     * @param array|null          $seen       groups we've seen as keys (used when recursing)
     * @param string              $userMode   'user', 'owner' or 'all' to control fields searched
     * @param array|null          $groups     groups to search. If not provided a fetchWithSubgroups will be executed to
     *                                        search
     * @return  bool        true if user is a member of specified group (or sub-group if recursive), false otherwise
     * @throws ConfigException
     * @throws P4Exception
     */
    public function isMember(
        $user,
        $group,
        $recursive = false,
        ConnectionInterface $connection = null,
        array $seen = null,
        $userMode = Group::USER_MODE_USER,
        $groups = null
    ) {
        // do basic input validation
        if (!(new UserName)->isValid($user)) {
            throw new InvalidArgumentException(
                'Is Member expects a valid username.'
            );
        }
        if (!(new GroupName)->isValid($group)) {
            throw new InvalidArgumentException(
                'Is Member expects a valid group.'
            );
        }

        // try and get the group cache. if we fail, fall back to a live check
        $groups     = $groups ? $groups : $this->fetchWithSubgroups($group, $connection);
        $groupModel = $connection->getValue($group, $groups);
        // if the group they asked for doesn't exist, not a member
        if ($groupModel == null) {
            return false;
        }

        $connection = $connection ? $connection : $groupModel->getConnection();
        // Check the user mode and return true if the user is found
        switch ($userMode) {
            case Group::USER_MODE_USER:
                if ($connection->stringMatches($user, $groupModel->getUsers())) {
                    return true;
                }
                break;

            case Group::USER_MODE_OWNER:
                if ($connection->stringMatches($user, $groupModel->getOwners())) {
                    return true;
                }
                break;

            case Group::USER_MODE_ALL:
                if ($connection->stringMatches($user, $groupModel->getUsers())
                    || $connection->stringMatches($user, $groupModel->getOwners())) {
                    return true;
                }
                break;
        }

        // if recursion is on, check all sub-groups
        // avoid circular references by tracking which groups we've seen
        if ($recursive) {
            $seen = (array)$seen + [$group => true];
            foreach ($groupModel->getSubgroups() as $sub) {
                $seenModel = $connection->getValue($sub, $seen);
                if ($seenModel == null && $this->isMember($user, $sub, true, $connection, $seen, $userMode, $groups)) {
                    return true;
                }
            }
        }

        // if we make it to the end they aren't a member
        return false;
    }

    /**
     * Just get the list of member ids associated with the passed group.
     *
     * @param string               $id        The id of the group to fetch members of.
     * @param array                $options   Optional - array of options to augment fetch behavior.
     *                                           FETCH_INDIRECT - used to also list indirect matches.
     * @param ConnectionInterface $connection Optional - a specific connection to use.
     * @return  array       an array of member ids
     */
    public function fetchMembers($id, $options = [], ConnectionInterface $connection = null)
    {
        $seen    = [];
        $recurse = function ($id) use (&$recurse, &$seen, $connection) {
            $group     = $this->fetchById($id, $connection);
            $users     = $group->getUsers();
            $seen[$id] = true;
            foreach ($group->getSubgroups() as $sub) {
                if (!isset($seen[$sub])) {
                    $users = array_merge($users, $recurse($sub));
                }
            }
            sort($users, SORT_STRING);
            return $users;
        };
        // if indirect fetching is enabled; go recursive
        if (isset($options[Group::FETCH_INDIRECT]) && $options[Group::FETCH_INDIRECT]) {
            return array_unique($recurse($id));
        }
        return $this->fetchById($id, $connection)->getUsers();
    }

    /**
     * Fetch an array keyed on group id with a value of group model for the group and its subgroups
     * @param mixed                     $id             group id to fetch
     * @param ConnectionInterface|null  $connection     perforce connection to use
     * @param array                     $groups         groups already seen and populated, defaults to empty array
     * @return array the group record and all its subgroups (recursively)
     */
    public function fetchWithSubgroups($id, ConnectionInterface $connection = null, array &$groups = []) : array
    {
        $seen       = [];
        $connection = $connection ?: $this->services->get(ConnectionFactory::P4_ADMIN);
        $recurse    = function ($id, $connection) use (&$recurse, &$seen, &$groups) {
            try {
                if (isset($groups[$id])) {
                    $group = $groups[$id];
                } else {
                    $group       = $this->fetchById($id, $connection);
                    $groups[$id] = $group;
                }
                $seen[$id] = true;
                foreach ($group->getSubgroups() as $sub) {
                    if (!isset($seen[$sub])) {
                        $recurse($sub, $connection);
                    }
                }
            } catch (\Exception $e) {
                // The initial id is not a group, empty array will be returned
            }
        };
        $recurse($id, $connection);
        return $groups;
    }

    /**
     * Fetches a single unique list of all the members in all of the groups
     * provided in the array of group identifiers.
     *
     * @param array $groups ids of groups in an array. Can be the group id
     *                      directly or swarm-group-id.
     * @return array   flat list of all members
     * @throws \Application\Config\ConfigException
     */
    public function fetchAllGroupsMembers(array $groups)
    {
        // Iterate over each group and fetch all members
        $members = [];
        foreach ((array)$groups as $group) {
            $members = array_merge($members, $this->fetchAllMembers(Group::getGroupName($group)));
        }
        return array_values(array_unique($members));
    }

    /**
     * Get all members of this group recursively.
     *
     * @param mixed               $id         id of group to get users in
     * @param bool                $flip       if true array keys are the group ids (default is false)
     * @param Iterator|null       $groups     list of groups to use (used when recursing)
     * @param array|null          $seen       groups we've seen as keys (used when recursing)
     * @param ConnectionInterface $connection optional - connection to use
     * @return  array               flat list of all members
     * @throws ConfigException
     */
    public function fetchAllMembers(
        $id,
        $flip = false,
        $groups = null,
        array $seen = null,
        ConnectionInterface $connection = null
    ) : array {
        $connection = $connection ? $connection : $this->services->get(ConnectionFactory::P4_ADMIN);
        $groups     = $groups ?: $this->fetchWithSubgroups($id, $connection);
        if (!isset($groups[$id])) {
            return [];
        }
        $seen      = (array)$seen + [$id => true];
        $group     = $groups[$id];
        $users     = $group->getUsers();
        $users     = $users ? array_flip($users) : [];
        $subGroups = $group->getSubgroups();
        $subGroups = $subGroups ? $subGroups : [];

        // recursively explore sub-groups, but don't re-evaluate groups we've already seen
        foreach ($subGroups as $subGroup) {
            if (!isset($seen[$subGroup])) {
                $users += $this->fetchAllMembers($subGroup, true, $groups, $seen, $connection);
            }
        }
        if (!$flip) {
            ksort($users, SORT_STRING);
        }
        return $flip ? $users : array_keys($users);
    }

    /**
     * Get all users and groups of this group.
     * Optimized to avoid hydrating groups and to use the group cache directly.
     *
     * @param mixed               $id         id of group to get users in
     * @param ConnectionInterface $connection optional - connection to use
     * @return array flat list of all members
     */
    public function fetchUsersAndSubgroups($id, ConnectionInterface $connection = null) : array
    {
        $connection = $connection ?: $this->getConnection();
        try {
            $group                = $this->fetchById($id, $connection);
            $allMembers['Users']  = $group->getUsers();
            $allMembers['Groups'] = $group->getSubgroups();
            return $allMembers;
        } catch (NotFoundException $nfe) {
            return [];
        }
    }

    /**
     * A convenience method to filter all invalid/non-existent group ids from a passed list.
     *
     * @param array|string        $groups     one or more group ids to filter for validity
     * @param ConnectionInterface $connection optional - a specific connection to use.
     * @param array               $excludeList  optional - Array of blacklisted groups.
     * @param boolean             $keyPrefix  optional - Weather you want to "swarm-group-" prepended.
     * @return  array           the filtered result
     */
    public function filter($groups, ConnectionInterface $connection = null, $excludeList = [], $keyPrefix = true)
    {
        $caseSensitive = $connection->isCaseSensitive();

        $groupIds = [];
        // we don't want group ids which contain wildcards, isValidId
        // should remove these and any other wacky input values
        foreach ($groups as $key => $group) {
            $groupName = Group::getGroupName($group);

            //Skip blacklisted groups
            if (ConfigCheck::isExcluded($groupName, $excludeList, $caseSensitive)) {
                continue;
            }
            if ((new GroupName)->isValid($groupName)
                && $this->exists($groupName, $connection)) {
                // leverage fetchAll to do the heavy lifting
                $groupId = $this->fetchById($groupName, $connection)->getId();
                if (!empty($groupId)) {
                    $groupIds[] = ($keyPrefix === true ? Config::KEY_PREFIX : '') . $groupId;
                }
            }
        }
        return $groupIds;
    }

    /**
     * Save a model both to the p4d server and the cache.
     *
     * There are special considerations for groups over the shared dao save. Groups have both a key record(config) and
     * a spec record, which require different levels of permission to save into p4d. Key data can only be saved by
     * super or admin. Group-spec data can be added by an admin or super, and updated by an owner or super. This is why
     * there are the additional flags.
     *
     * @param mixed $model
     * @param bool $editAsOwner
     * @param bool $addAsAdmin
     * @return mixed
     */
    public function save($model, $editAsOwner = false, $addAsAdmin = false)
    {
        $cacheService = $this->getCache();
        $keys         = $this->generateModelKeys([$model]);
        $p4admin      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $result       = $model->save($editAsOwner, $addAsAdmin, $p4admin);
        $cacheService->setMultiple($keys);
        $this->populateSearchKeys($cacheService, [$model]);
        return $result;
    }

    /**
     * Delete a group from both p4d and the cache.
     *
     * There are special considerations from the standard delete in play, and some legacy code that has to be left
     * in place for now and avoided from here. It used to be that Group::delete was passed a connection for deleting
     * the group/spec and used the $model->connection to remove the key data. As there is no longer the capacity to
     * have two connections available in the Group::delete, this code deletes the key(config) first with the admin
     * connection, then invokes the Group::delete. The Group::delete will find no key data to delete and will,
     * therefore, not try to use an unpriviledged connection to do so.
     *
     * @param mixed $model
     * @return mixed
     */
    public function delete($model)
    {
        $config  = $model->getConfig();
        $p4admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // First delete the additional Swarm metadata(the config), using the admin connection
        if ($config && Config::exists($config->getId(), $p4admin)) {
            $config->setConnection($p4admin)->delete();
        }
        // Then delete the model
        return parent::delete($model);
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
     * Includes the model in the search entries if it is a group name and not a project name
     * @param mixed $model      the model
     * @return bool
     */
    protected function includeSearchEntry($model) : bool
    {
        return !Project::isProjectName($model->getId());
    }

    /**
     * Gets the value from the model to be used with search entries. Overrides the abstract
     * function to return the name from the config associated with the group
     * @param mixed $model
     * @return mixed
     */
    protected function getSearchEntryValue($model)
    {
        return $model->getConfig()->getName();
    }

    /**
     * @inheritDoc
     * $matches for groups will always be in the form <groupName><RedisService::SEARCH_PART_SEPARATOR><id>
     */
    protected function formatSearchResults(array $matches)
    {
        return $this->formatResults($matches, Group::FIELD_ID, Group::FIELD_NAME);
    }

    /**
     * Converts the id to a normalized value.
     * @param string                $id         the id
     * @param ConnectionInterface   $connection connection details
     * @return string the normalized id. If the connection specifies non case sensitive that a lowercase version of
     * the id is returned, otherwise the id is returned unchanged.
     */
    protected function normalizeId($id, ConnectionInterface $connection = null)
    {
        return $this->getCaseSpecificId($id, $connection);
    }
}
