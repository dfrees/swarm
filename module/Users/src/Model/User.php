<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Model;

use Application\Model\ServicesModelTrait;
use Application\Permissions\ConfigCheck;
use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\Spec\Exception\Exception;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Group;
use Record\Exception\NotFoundException as RecordNotFoundException;
use P4\Spec\PluralAbstract;
use InvalidArgumentException;
use P4\Exception as P4Exception;

class User extends \P4\Spec\User implements IUser
{
    use ServicesModelTrait;

    const   MFA_AUTH_METHOD = 'perforce+2fa';
    const   AUTHMETHOD      = 'AuthMethod';

    protected $config = null;

    /**
     * Extends exists to use cache if available.
     *
     * @param   string|array    $id             the id to check for or an array of ids to filter.
     * @param   Connection      $connection     optional - a specific connection to use.
     * @return  bool|array true if the given id matches an existing user.
     */
    public static function exists($id, Connection $connection = null)
    {
        // before we muck with things; capture if it's plural or singular mode
        $plural = is_array($id);

        // normalize the input to an array of valid ids
        $ids = [];
        foreach ((array) $id as $value) {
            if (static::isValidId($value)) {
                $ids[] = $value;
            }
        }

        $users = parent::fetchAll(
            [
                static::FETCH_BY_NAME => $ids,
                static::FETCH_MAXIMUM => count($ids)
            ],
            $connection
        );

        $connection      = $connection ?: static::getDefaultConnection();
        $isCaseSensitive = $connection->isCaseSensitive();

        $lower    = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        $filtered = [];
        if (!$isCaseSensitive) {
            foreach ($users as $key => $value) {
                $users[$lower($key)] = $value;
            }
        }
        foreach ($ids as $id) {
            $idToSearch = $id;
            if (!$isCaseSensitive) {
                $idToSearch = $lower($id);
            }
            if (isset($users[$idToSearch])) {
                $filtered[] = $id;
            }
        }
        return $plural ? $filtered : count($filtered) != 0;
    }

    /**
     * Extends fetch to get the user.
     *
     * @param string     $id         the id of the entry to fetch.
     * @param Connection $connection optional - a specific connection to use.
     * @return  PluralAbstract  instance of the requested entry.
     * @throws SpecNotFoundException
     */
    public static function fetchById($id, Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        $user       = parent::fetchById($id, $connection);
        if ($user) {
            return $user;
        }

        throw new SpecNotFoundException("Cannot fetch user $id. Record does not exist.");
    }

    /**
     * Extends fetchAll to use cache if available.
     *
     * @param   array       $options    optional - array of options to augment fetch behavior.
     *                                  supported options are:
     *
     *                                  FETCH_MAXIMUM - set to integer value to limit to the
     *                                                  first 'max' number of entries.
     *                                  FETCH_BY_NAME - set to user name pattern (e.g. 'jdo*'),
     *                                                  can be a single string or array of strings.
     *
     * @param   Connection  $connection optional - a specific connection to use.
     * @return  FieldedIterator         all matching records of this type.
     */
    public static function fetchAll($options = [], Connection $connection = null)
    {
        $connection = $connection ?: static::getDefaultConnection();
        $options    = (array) $options + [
            static::FETCH_MAXIMUM   => null,
            static::FETCH_BY_NAME   => null
            ];

        $users = parent::fetchAll($options, $connection);

        // each user needs to be cloned and handed a connection
        $result = new FieldedIterator;
        $limit  = $options[static::FETCH_MAXIMUM];
        $names  = $options[static::FETCH_BY_NAME];
        $names  = is_string($names) ? [$names] : $names;
        foreach ($users as $id => $user) {
            // if max limiting, stop when/if we exceed max
            if ($limit && count($result) >= $limit) {
                break;
            }

            // if filtering by name, exclude users that don't match
            if (is_array($names)) {
                $match = false;
                foreach ($names as $name) {
                    // to match p4 behavior, we run $name through preg_quote then use a
                    // preg_replace to make \* into .+ (or .* if its at the end).
                    $pattern = '/^' . preg_quote($name, '/') . '$/';
                    $pattern = preg_replace('/\\\\\*/', '.+', $pattern);
                    $pattern = preg_replace('/\.+$/',   '.*', $pattern);
                    if (preg_match($pattern . (!$connection->isCaseSensitive() ? 'i' : ''), $id)) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            $user = clone $user;
            $user->setConnection($connection);
            $result[$id] = $user;
        }

        return $result;
    }

    /**
     * A convenience method to filter all invalid/non-existent user ids from a passed list.
     *
     * @param   array|string    $users      one or more user ids to filter for validity
     * @param   Connection      $connection optional - a specific connection to use.
     * @param   array           $blacklist  optional list of user ids to blacklist. Gives the
     * caller the opportunity to exclude users prevented from being logged in or mentioned for example.
     * @return  array           the filtered result
     */
    public static function filter($users, Connection $connection = null, $blacklist = [])
    {
        $caseSensitive = $connection->isCaseSensitive();

        // we don't want user ids which contain wildcards, isValidId
        // should remove these and any other wacky input values
        foreach ($users as $key => $user) {
            $isBlacklisted = ConfigCheck::isExcluded($user, $blacklist, $caseSensitive);

            if ($isBlacklisted || !static::isValidId($user)) {
                unset($users[$key]);
            }
        }
        // if, after filtering, we have no users; simply return
        if (!$users) {
            return $users;
        }
        // leverage fetchAll to do the heavy lifting
        return static::fetchAll(
            [static::FETCH_BY_NAME => $users],
            $connection
        )->invoke('getId');
    }

    /**
     * Get config record for this user. If there is no config record, make one.
     * Config records are useful for storage of arbitrary user settings.
     *
     * @return Config  the associated user config record
     * @throws P4Exception
     */
    public function getConfig()
    {
        if (!$this->config instanceof Config) {
            try {
                $config = Config::fetch($this->getId(), $this->getConnection());
            } catch (RecordNotFoundException $e) {
            } catch (InvalidArgumentException $e) {
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
     * Set the config record for this user.
     *
     * @param   Config  $config     the config record to associate with this user
     * @return  User    provides a fluent interface
     */
    public function setConfig(Config $config)
    {
        $config->setId($this->getId());
        $this->config = $config;

        return $this;
    }

    /**
     * Extends save to store the config record.
     *
     * @return  User    provides a fluent interface
     * @throws \P4\Spec\Exception\Exception
     */
    public function save()
    {
        parent::save();

        if ($this->config instanceof Config) {
            $this->config->setId($this->getId());
            $this->config->save();
        }

        return $this;
    }

    /**
     * Overrides the P4 library function to use the Group DAO to fetch records to take advantage of any
     * caching.
     * @return mixed|FieldedIterator the groups
     * @throws Exception
     * @throws P4Exception
     */
    public function getGroups()
    {
        // Keep the existing parent validation of the ID.
        if (!static::isValidId($this->getId())) {
            throw new Exception("Cannot get groups. No user id has been set.");
        }

        return self::getGroupDao()->fetchAll(
            [
                Group::FETCH_BY_USER => $this->getId(),
                Group::FETCH_INDIRECT => true
            ],
            $this->getConnection()
        );
    }
}
