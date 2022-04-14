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
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Option;
use Application\Permissions\ConfigCheck;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Redis\RedisService;
use Users\Model\IUser;
use Users\Model\User;

/**
 * DAO to handle finding/saving users.
 * @package Redis\Model
 */
class UserDAO extends PluralAbstractDAO
{
    use SearchEntryTrait;
    // The main key prefix to reference an individual record, for example 'user^fred'
    const CACHE_KEY_PREFIX = IUser::USER . RedisService::SEPARATOR;
    // The Perforce class that handles users
    const MODEL = User::class;
    // The Key used by userDAO to know if it has been pre populated.
    const POPULATED_STATUS = IUser::USER . "-" . AbstractDAO::POPULATED_STATUS;
    // The key for the verify status of the user dataset
    const VERIFY_STATUS = IUser::USER . "-" . AbstractDAO::VERIFY_STATUS;
    // When populating maximum set to null to fetch all records. The user model does not support FETCH_AFTER
    const FETCH_MAXIMUM = null;
    // The key used to index users for starts with searches, within a given namespace
    const SEARCH_STARTS_WITH_KEY = AbstractDAO::SEARCH_STARTS_WITH . RedisService::SEPARATOR . IUser::USER;
    // The key used to index users for includes searches, within a given namespace
    const SEARCH_INCLUDES_KEY = AbstractDAO::SEARCH_INCLUDES . RedisService::SEPARATOR . IUser::USER;

    /**
     * Call the parent fetchById first converting the id to the correct case
     * @param string                    $id         the id
     * @param ConnectionInterface|null  $connection the connection
     * @return mixed
     * @throws SpecNotFoundException
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        // Check for not null explicitly (0 is a possible numeric id)
        if ($id !== null) {
            return parent::fetchById($id, $connection);
        } else {
            throw new SpecNotFoundException("Cannot fetch user $id. Record does not exist.");
        }
    }

    /**
     * Call the parent fetchAll and return the result with excluding users by default
     * @param array $options
     * @param ConnectionInterface|null $connection
     * @return array|Iterator
     * @throws ConfigException
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        $defaults      = [
            IRequest::IGNORE_EXCLUDE_LIST => false,
        ];
        $usersIterator = new Iterator;
        $options      += $defaults;
        $connection    = $this->getConnection($connection);
        $users         = parent::fetchAll($options, $connection);
        if (!$options[IRequest::IGNORE_EXCLUDE_LIST] && !isset($options[UserDAO::FETCH_SEARCH])) {
            $config = $this->services->get(ConfigManager::CONFIG);
            // check if the server is case sensitive
            $caseSensitive = $connection->isCaseSensitive();
            $excludeList   = $options[IRequest::IGNORE_EXCLUDE_LIST]
                ? []
                : ConfigManager::getValue($config, ConfigManager::MENTIONS_USERS_EXCLUDE_LIST, []);
            foreach ($users as $user) {
                // if the user id is on the user exclude list
                // do not include that user in the list
                if (ConfigCheck::isExcluded($user->getId(), $excludeList, $caseSensitive)) {
                    continue;
                }
                $usersIterator[$user->getId()] = $user;
            }
            return $usersIterator;
        }
        return $users;
    }

    /**
     * Call the parent fetchById first converting the id to the correct case
     *
     * @param string                   $id         the id
     * @param ConnectionInterface|null $connection the connection
     * @param array                    $fields
     * @return mixed
     * @throws SpecNotFoundException
     */
    public function fetchAuthUser(
        $id,
        ConnectionInterface $connection = null,
        $fields = [
            User::ID_FIELD, User::EMAIL_FIELD, User::FULL_NAME_FIELD, User::TYPE_FIELD, Option::IS_ADMIN,
            Option::IS_SUPER,
        ]
    ) {
        $user       = $this->fetchById($id, $connection);
        $p4User     = $this->services->get(ConnectionFactory::P4_USER);
        $userValues = $user->getValuesArray();

        $userValues[Option::IS_ADMIN] = $p4User->isAdminUser(true);
        $userValues[Option::IS_SUPER] = $p4User->isSuperUser();

        $filteredUser = $fields ? array_intersect_key($userValues, array_flip($fields)) : $userValues;
        return $filteredUser;
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

    /**
     * @inheritDoc
     * We need to create set members in such a way that they can be matched by either their lowercase id or their
     * lowercase name, while still retaining their original (potentially, case-sensitive) id and name.
     * For example, if we have a user with an id of 'User1' and a name of 'Jimbob', we need to create an entry that
     * will allow you to match on any part of 'User1' (in any case) and any part of 'Jimbob' (in any case). As long as
     * we convert the search term to lowercase, having `user1` and 'jimbob' as part of our entry will accomplish this.
     *
     * We also need a separator between the words so we don't match on `er1ji`. For this example, let's take ':' as
     * our separator. Assuming the name (if different from  the id) is what will be most familiar to searchers, we
     * should have the name come first. So we are now looking at 'jimbob:user1'.
     *
     * Since, we don't actually have enough information from 'jimbob:user1' to identify the user (I.e. if the server
     * is case-sensitive) we need to include the original information. Including the original information will also
     * allow us to return matches to something like an autocomplete API, without the need for a further lookup. We
     * should have another separator, though, so we can easily split off the original data from the lowercase data, say,
     * '^'. So, we now have something like:
     *     'jimbob:user1^'Jimbob:User1'
     *
     * However, we are not quite done yet because we are also dealing with a sorted set, which is ordered
     * lexicographically. This type of set allows you to do a `starts with` search. If we only had the
     * 'jimbob:user1^'Jimbob:User1' entry, we could never match on 'starts with "user"'! So we need an extra entry
     * for the sorted set. It needs to have the form:
     *     'user1:jimbob^'Jimbob:User1'
     *
     * If you noticed, we switched the order of the lowercase words but not the order of the original words. If we build
     * the search entries with the original id as the last component, then we can always get the id with
     * end(split(':', $entry).
     *
     * For this example we would wind up with a single entry for our includes set:
     *     'jimbob:user1^'Jimbob:User1'
     * and two entries for our starts with, sorted set:
     *     'jimbob:user1^'Jimbob:User1'
     *     'user1:jimbob^'Jimbob:User1'
     *
     * Lastly, if you have a case where the name and the id are the same, say, 'User1' then you will wind up building
     * duplicate entries for the starts with sorted set, example:
     *     'user1:user1^User1:User1
     *     'user1:user1^User1:User1
     * However, it doesn't matter because we are dealing with a set, which automatically deals with duplicates.
     */
    protected function buildSearchEntries($models)
    {
        return $this->constructEntries($models);
    }

    /**
     * @inheritDoc
     * $matches for users will always be in the form <username><RedisService::SEARCH_PART_SEPARATOR><fullname>
     */
    protected function formatSearchResults(array $matches)
    {
        return $this->formatResults($matches, User::FIELD_ID, User::FIELD_NAME);
    }

    /**
     * Get the value to use in search entries. Overrides the abstract to get the full name of the user
     * @param mixed $model  model for the search entry
     * @return mixed
     */
    protected function getSearchEntryValue($model)
    {
        return $model->getFullName();
    }
}
