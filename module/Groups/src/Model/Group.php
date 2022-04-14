<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Model;

use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use P4\Exception as P4Exception;

class Group extends \P4\Spec\Group implements IGroup
{
    protected $config  = null;
    const MAX_VALUE    = 950;
    const FETCH_BY_IDS = 'name';

    /**
     * Overrides parent to also serialize Config held against the group
     * @return string
     * @throws P4Exception
     */
    public function serialize()
    {
        $array                     = $this->toArrayStore();
        $array[self::FIELD_CONFIG] = $this->getConfig()->toArrayStore();
        return serialize($array);
    }

    /**
     * Overrides parent to also unserialize Config held against the group
     * @param string $serialized
     */
    public function unserialize($serialized)
    {
        $values       = unserialize($serialized);
        $this->config = new Config();
        $this->config->setRawValues($values[self::FIELD_CONFIG]);
        unset($values[self::FIELD_CONFIG]);
        $this->setRawValues($values);
        $this->config->setId($this->getId());
    }

    /**
     * Extends parent to allow undefined values to be set.
     *
     * @param string $field the name of the field to set the value of.
     * @param mixed  $value the value to set in the field.
     * @return Group|\P4\Spec\SingularAbstract provides a fluent interface
     * @throws \P4\Spec\Exception\Exception
     * @todo    remove this when/if we stop using fielded iterator for groups api
     */
    public function setRawValue($field, $value)
    {
        if (!$this->hasField($field)) {
            $this->values[$field] = $value;
            return $this;
        }

        return parent::setRawValue($field, $value);
    }

    /**
     * Extends parent to include adhoc fields.
     *
     * @return  array   a list of field names for this spec.
     * @throws \P4\Exception
     * @todo    remove this when/if we stop using fielded iterator for groups api
     */
    public function getFields()
    {
        return array_unique(array_merge(parent::getFields(), array_keys($this->values)));
    }

    /**
     * Creates a new Group object and sets the passed values on it.
     *
     * @param array $values array of values to set on the new group
     * @param Connection $connection connection to set on the new group
     * @param bool $setRawValues if true, then $values will be set as they are (avoids mutators)
     *                                      otherwise $values will be set by using mutators (if available)
     *                                      avoiding mutators might save some performance as it skips validating ids
     *                                      for users, owners and/or subgroups (unnecessary if populating from cache)
     * @return  Group       the populated group
     */
    public static function fromArray($values, Connection $connection = null, $setRawValues = false)
    {
        $group = new static($connection);
        // determine method to set the $values via
        $set = $setRawValues ? 'setRawValues' : 'set';

        // extract config data from $values into the config instance
        $config = isset($values[self::FIELD_CONFIG]) ? new Config($connection) : null;
        if ($config) {
            $config->$set($values[self::FIELD_CONFIG]);
            unset($values[self::FIELD_CONFIG]);
        }
        $group->$set($values);
        // set config on the group instance now
        // we do this after setting group values so the config gets the id
        if ($config) {
            $group->setConfig($config);
        }
        // if you provided an id; we defer populate to allow lazy loading.
        // in practice; we anticipate the object is already fully populated
        // so this really shouldn't make an impact.
        if (isset($values['Group'])) {
            $group->deferPopulate();
        }

        return $group;
    }

    /**
     * Extends fetch to use cache if available.
     *
     * @param string     $id         the id of the entry to fetch.
     * @param Connection $connection optional - a specific connection to use.
     * @return Group instance of the requested entry.
     * @throws SpecNotFoundException
     */
    public static function fetchById($id, Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        return parent::fetchById($id, $connection);
    }

    /**
     * Extends fetchAll to use cache if available.
     *
     * @param array $options optional - array of options to augment fetch behavior. Supported options are:
     *     FETCH_MAXIMUM      - set to integer value to limit to the first 'max' number of entries.
     *                          Note: Limits imposed client side.
     *     FETCH_BY_MEMBER    - Not supported
     *     FETCH_BY_USER      - get groups containing passed user (no wildcards).
     *     FETCH_INDIRECT     - used with FETCH_BY_MEMBER or FETCH_BY_USER to also list indirect matches.
     *     FETCH_BY_NAME      - get the named group. essentially a 'fetch' but performed differently (no wildcards).
     *                          Note: not compatible with FETCH_BY_MEMBER, FETCH_BY_USER or FETCH_INDIRECT
     *     FETCH_BY_USER_MODE - Used with FETCH_BY_USER. 'member' will check member field, 'owner' will check owner
     *                          field 'all' will check members and owners. Not set will default to user.
     *
     * @param Connection $connection optional - a specific connection to use.
     * @return  FieldedIterator         all matching records of this type.
     * @throws  \InvalidArgumentException       if FETCH_BY_MEMBER is used
     */
    public static function fetchAll($options = [], Connection $connection = null)
    {
        // Validate the various options by having parent generate fetch all flags.
        // We don't actually use the flags but the option verification is valuable.
        static::getFetchAllFlags($options);

        if (isset($options[static::FETCH_BY_MEMBER]) && $options[static::FETCH_BY_MEMBER]) {
            throw new \InvalidArgumentException(
                "The User Group model doesn't support FETCH_BY_MEMBER."
            );
        }

        // normalize connection
        $connection = $connection ?: static::getDefaultConnection();

        // TODO: this used to use the cache, GroupDAO needs to deal with options not supported by the parent
        return static::applyFetchAllFilters(
            parent::fetchAll($options, $connection)->toArray(true), $options, $connection
        );
    }

    public static function applyFetchAllFilters($groups, $options, $connection)
    {
        // now that parent is done with options; normalize them
        // if we do this earlier it will cause issues with parent
        $options = (array)$options + [
                static::FETCH_MAXIMUM => null,
                static::FETCH_BY_MEMBER => null,
                static::FETCH_BY_USER => null,
                static::FETCH_INDIRECT => null,
                static::FETCH_BY_NAME => null,
                static::FETCH_BY_USER_MODE => Group::USER_MODE_USER
            ];

        // always going to have an iterator as a result at this point; make it
        $result = new FieldedIterator;

        // Fetch by name is essentially a fetch that returns an iterator
        // handle that case early as it is simple
        if ($options[static::FETCH_BY_NAME]) {
            $id = $options[static::FETCH_BY_NAME];
            if (isset($groups[$id])) {
                $result[$id] = $groups[$id];
            }
            return $result;
        }

        // turn group arrays into objects and apply various filters if present
        $limit    = $options[static::FETCH_MAXIMUM];
        $user     = $options[static::FETCH_BY_USER];
        $indirect = $options[static::FETCH_INDIRECT];
        $config   = Config::fetchAll([], $connection)->toArray(true);
        foreach ($groups as $id => $group) {
            // if max limiting, stop when/if we exceed max
            if ($limit && count($result) >= $limit) {
                break;
            }

            // if filtering by member, exclude groups that don't match
            if ($user &&
                !static::isMember(
                    $user,
                    $id,
                    $indirect,
                    $connection,
                    null,
                    $options[static::FETCH_BY_USER_MODE],
                    $groups
                )
            ) {
                continue;
            }

            if (isset($config[$id])) {
                $groupConfig = $config[$id];
            } else {
                $groupConfig = new Config($connection);
                $groupConfig->setId($id);
            }
            $group->setConfig($groupConfig);
            // passes the filters, lets add it to the result
            $result[$id] = $group;
        }

        return $result;
    }

    /**
     * Test if the passed user is a direct (or if recursive is set, even indirect)
     * member of the specified group.
     *
     * @param string     $user          the user id to check membership for
     * @param string     $group         the group id we are looking in
     * @param bool       $recursive     true if we are also checking sub-groups,
     *                                  false for only testing direct membership
     * @param Connection $connection    optional - a specific connection to use.
     * @param array|null $seen          groups we've seen as keys (used when recursing)
     * @param string     $userMode      'user', 'owner' or 'all' to control fields searched
     * @param array|null $groups        candidate groups, fetchAll will be used if not provided
     * @return  bool        true if user is a member of specified group (or sub-group if recursive), false otherwise
     * @throws \Exception
     */
    public static function isMember(
        $user,
        $group,
        $recursive = false,
        Connection $connection = null,
        array $seen = null,
        $userMode = self::USER_MODE_USER,
        $groups = null
    ) {
        // do basic input validation
        if (!static::isValidUserId($user)) {
            throw new \InvalidArgumentException(
                'Is Member expects a valid username.'
            );
        }
        if (!static::isValidId($group)) {
            throw new \InvalidArgumentException(
                'Is Member expects a valid group.'
            );
        }

        $groups = $groups ? $groups : parent::fetchAll(
            [
                static::FETCH_BY_MEMBER => $user,
                static::FETCH_INDIRECT => $recursive
            ],
            $connection
        )->toArray(true);

        // if the group they asked for doesn't exist, not a member
        if (!isset($groups[$group])) {
            return false;
        }

        // Check the user mode and return true if the user is found
        switch ($userMode) {
            case static::USER_MODE_USER:
                if (in_array($user, $groups[$group]->getUsers())) {
                    return true;
                }
                break;

            case static::USER_MODE_OWNER:
                if (in_array($user, $groups[$group]->getOwners())) {
                    return true;
                }
                break;

            case static::USER_MODE_ALL:
                if (in_array($user, $groups[$group]->getUsers()) || in_array($user, $groups[$group]->getOwners())) {
                    return true;
                }
                break;
        }

        // if recursion is on, check all sub-groups
        // avoid circular references by tracking which groups we've seen
        if ($recursive) {
            $seen = (array)$seen + [$group => true];
            foreach ($groups[$group]->getSubgroups() as $sub) {
                if (!isset($seen[$sub]) &&
                    static::isMember($user, $sub, true, $connection, $seen, $userMode, $groups)) {
                    return true;
                }
            }
        }

        // if we make it to the end they aren't a member
        return false;
    }

    /**
     * Get config record for this group. If there is no config record, make one.
     * Config records are useful for storage of arbitrary group settings.
     *
     * @return  Config  the associated group config record
     * @throws \P4\Exception
     */
    public function getConfig()
    {
        if (!$this->config instanceof Config) {
            try {
                $config = Config::fetch($this->getId(), $this->getConnection());
            } catch (RecordNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }
            if (!isset($config)) {
                $config = new Config($this->getConnection());
                $config->setId($this->getId());
            }
            $this->config = $config;
        }
        return $this->config;
    }

    /**
     * Set the config record for this group.
     *
     * @param Config $config the config record to associate with this group
     * @return  Group   provides a fluent interface
     */
    public function setConfig(Config $config)
    {
        $config->setId($this->getId());
        $this->config = $config;
        return $this;
    }

    /**
     * Return true if this group has config, false otherwise.
     *
     * @return  boolean     true if this instance has a config, false otherwise
     */
    public function hasConfig()
    {
        return $this->config !== null;
    }

    /**
     * Extends save to store the config record.
     *
     * @param bool       $editAsOwner           save the group as a group owner
     * @param bool       $addAsAdmin            add the group as admin
     * @param Connection $configConnection      the connection to use when saving the config
     *                                          this allows one connection for the spec and another for the config
     * @return  Group   provides a fluent interface
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\Exception
     */
    public function save($editAsOwner = false, $addAsAdmin = false, Connection $configConnection = null)
    {
        parent::save($editAsOwner, $addAsAdmin);
        if ($this->config instanceof Config) {
            $this->config->setConnection($configConnection ?: $this->getConnection());
            $this->config->setId($this->getId());
            $this->config->save();
        }
        return $this;
    }

    /**
     * Gets the group part of 'swarm-group-xxx'
     * @param mixed $groupName the group name
     * @return mixed the stripped group if it is a group or the value if not. Note that
     * preg_replace will return a string so if group name is 1 then "1" will be returned
     */
    public static function getGroupName($groupName)
    {
        return preg_replace('/^' . Config::KEY_PREFIX . '/', '', (string)$groupName);
    }

    /**
     * Tests to see if the name starts with 'swarm-group-'.
     * @param mixed $groupName the group name
     * @return bool true if the name is a Swarm group name
     * @see Group::getGroupName()
     */
    public static function isGroupName($groupName)
    {
        // Do a loose comparison so that integer values compare correctly
        // For example getGroupName returns "1" for 1 and matching strictly would return false
        return Group::getGroupName($groupName) != $groupName;
    }
}
