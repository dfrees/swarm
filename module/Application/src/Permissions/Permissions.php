<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Checker;
use Application\Config\ConfigManager;
use Application\Config\ConfigException;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\Exception as PermissionsException;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Session\Container as SessionContainer;
use Application\Session\SwarmSession;
use Groups\Model\Group;
use Laminas\Session\Exception\RuntimeException;
use Projects\Model\Project as ProjectModel;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Exception;
use InvalidArgumentException;

/**
 * Allows enforcing or simply testing for various permissions:
 * - authenticated
 * - admin
 * - super
 * - member (of $project or $group)
 *
 * The 'checks' can be passed as a string (for all but member) or in an array.
 * Note, the member check is passed as the key with the project to test the value.
 *
 * e.g.:
 * is('admin')
 * or
 * isOne(array('admin', 'member' => $project))
 */
class Permissions extends Checker implements IPermissions
{
    use ConfigTrait;

    const MAX_ACCESS_CACHE_CONTAINER = 'max_access';
    const MAX_ACCESS_CACHE_EXPIRY    = 3600;
    const IS_IMMUTABLE               = 'isImmutable';
    const SUPER                      = 'super';
    const ADMIN                      = 'admin';
    const WRITE                      = 'write';
    const OPEN                       = 'open';
    const READ                       = 'read';
    const LIST                       = 'list';
    const MEMBER                     = 'member';
    const OWNER                      = 'owner';
    const PROJECT_ADD_ALLOWED        = 'projectAddAllowed';
    const GROUP_ADD_ALLOWED          = 'groupAddAllowed';
    const YOUR_NOT_ADMIN_PRIVILEGES  = 'Your account does not have admin privileges.';
    const YOUR_NOT_SUPER_PRIVILEGES  = 'Your account does not have super privileges.';
    const YOUR_NOT_A_MEMBER          = 'Your account is not a member of any of the passed items.';
    const LIMITED_TO_PROJECT_MEMBERS = 'This operation is limited to project members.';
    const LIMITED_TO_GROUP_MEMBERS   = 'This operation is limited to group members.';
    const PERMISSION_UNKNOWN         = 'The specified permission is unknown/invalid';

    const OPERATION_LIMITED_TO_OWNERS       = 'This operation is limited to project or group owners.';
    const ENFORCE_CALLED_WITH_NO_CONDITIONS = 'Permissions enforce called with no conditions.';
    const MEMBER_TEST_REQUIRES_INPUT        = 'The member test requires a project, group or a group ID as input.';
    const OWNER_TEST_REQUIRES_INPUT         = 'The owner access test requires a project or group as input.';

    /**
     * Run a permissions check.
     *
     * Note: This implementation will eventually take over from enforce which has been deprecated. (see SW-7291)
     *
     * @param string $check the check to perform
     * @param array|null $options
     * @throws Exception
     */
    public function check(string $check, array $options = null)
    {
        $this->getP4User();
        switch ($check) {
            case self::AUTHENTICATED:
                // this has already been handled by getting the P4 user
                break;
            default:
                break;
        }
    }

    /**
     * Get the current P4 user to check authorisation
     * @throws Exception
     */
    private function getP4User()
    {
        $services = $this->services;
        // all checks require access to the user's account so we actually start with
        // the authenticated test even if it isn't requested
        try {
            return $services->get(ConnectionFactory::P4_USER);
        } catch (ServiceNotCreatedException $e) {
            // dig down a level if possible; should result in 'unauthorized' exception
            throw $e->getPrevious() ?: $e;
        }
    }

    /**
     * Ensure all of the specified checks pass but simply return the result.
     * See class docs for usage.
     *
     * @param   string|array $checks the tests to try, all must pass
     * @return  bool            true if all checks pass, false otherwise
     * @throws Exception
     */
    public function is($checks)
    {
        try {
            $this->enforce($checks);
        } catch (PermissionsException $e) {
            return false;
        }

        return true;
    }

    /**
     * Ensure at least one of the specified checks passes but simply return the result.
     * See class docs for usage.
     *
     * @param   string|array $checks the tests to try, one must pass
     * @return  bool            true if at least one checks passes, false otherwise
     * @throws Exception
     */
    public function isOne($checks)
    {
        try {
            $this->enforceOne($checks);
        } catch (PermissionsException $e) {
            return false;
        }

        return true;
    }

    /**
     * Ensure all of the specified checks pass throwing on failure.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, all must pass
     * @return  Permissions     to maintain a fluent interface
     * @throws  UnauthorizedException       if the user is not authenticated
     * @throws  ForbiddenException          if the user is logged in but fails a check
     * @throws  InvalidArgumentException   for invalid checks or invalid data on a check
     * @throws  Exception                  if unexpected errors occur
     * @deprecated the ConfigCheck->check or ConfigCheck->checkAll pattern should be used in future
     * @see ConfigCheck
     */
    public function enforce($checks)
    {
        $checks   = (array) $checks;
        $services = $this->services;
        $p4User   = $this->getP4User();
        foreach ($checks as $check => $value) {
            if ((string) $check === (string)(int) $check) {
                $check = $value;
                $value = null;
            }

            switch ($check) {
                case self::AUTHENTICATED:
                    // this has already been handled
                    break;
                case self::ADMIN:
                    if ($this->getMaxAccess() !== self::ADMIN && $this->getMaxAccess() !== self::SUPER) {
                        throw new ForbiddenException(self::YOUR_NOT_ADMIN_PRIVILEGES);
                    }
                    break;
                case self::SUPER:
                    if ($this->getMaxAccess() !== self::SUPER) {
                        throw new ForbiddenException(self::YOUR_NOT_SUPER_PRIVILEGES);
                    }
                    break;
                case self::MEMBER:
                    // this check has different meaning based on the input:
                    // - if the value is an instance of the Project class, it will enforce a user
                    //   to be a member of that project
                    // - if the value is an instance of the Group class, it will enforce a user
                    //   to be direct or indirect member of that group
                    // - if the value is a string, it will consider the value as Perforce group id
                    //   and check if the user is a direct or indirect member
                    // An array of Group IDs and/or Project objects may be passed. The result will
                    // be true if the user is a member of at least one of the passed items.

                    // Deal with an array of values; we'll just call ourselves for each and complain if none hit
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            if ($this->is([self::MEMBER => $item])) {
                                break 2;
                            }
                        }
                        throw new ForbiddenException(self::YOUR_NOT_A_MEMBER);
                    }

                    // Deal with projects
                    if ($value instanceof ProjectModel) {
                        if (!$value->isMember($p4User->getUser())) {
                            throw new ForbiddenException(self::LIMITED_TO_PROJECT_MEMBERS);
                        }

                        break;
                    }

                    // Deal with groups
                    $value = $value instanceof Group ? $value->getId() : $value;
                    if (is_string($value)) {
                        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
                        $groupDAO = $services->get(IModelDAO::GROUP_DAO);
                        if (!$groupDAO->isMember($p4User->getUser(), $value, true, $p4Admin)) {
                            throw new ForbiddenException(self::LIMITED_TO_GROUP_MEMBERS);
                        }
                        break;
                    }

                    // Looks like an invalid input value; complain loudly
                    throw new InvalidArgumentException(
                        self::MEMBER_TEST_REQUIRES_INPUT
                    );
                    break;
                case self::OWNER:
                    if (!$value instanceof ProjectModel && !$value instanceof Group) {
                        throw new InvalidArgumentException(
                            self::OWNER_TEST_REQUIRES_INPUT
                        );
                    }
                    if (!$p4User->stringMatches($p4User->getUser(), $value->getOwners())) {
                        throw new ForbiddenException(self::OPERATION_LIMITED_TO_OWNERS);
                    }
                    break;
                case self::PROJECT_ADD_ALLOWED:
                    // this check will pass if user is allowed to add projects and fails otherwise
                    // user is allowed to add projects if:
                    //  - is authenticated (this is handled implicitly by this method)
                    //  - is also an admin if $config['projects']['add_admin_only'] is set to true
                    //  - is also a member of at least one of the groups specified in
                    //    $config['projects']['add_groups_only'] if this value is set and not empty
                    $config = $services->get(ConfigManager::CONFIG);

                    // check admin restriction if required (for backwards compatibility, we take
                    // values from $config['security']['add_project_admin_only'] as defaults)
                    $adminOnly = isset($config[ConfigManager::SECURITY][ConfigManager::ADD_PROJECT_ADMIN_ONLY])
                        && $config[ConfigManager::SECURITY][ConfigManager::ADD_PROJECT_ADMIN_ONLY];
                    $adminOnly = isset($config[ConfigManager::PROJECTS][ConfigManager::ADD_ADMIN_ONLY])
                        ? (bool) $config[ConfigManager::PROJECTS][ConfigManager::ADD_ADMIN_ONLY]
                        : $adminOnly;
                    if ($adminOnly) {
                        $this->enforce(self::ADMIN);
                    }

                    // check project groups restriction if specified (for backwards compatibility,
                    // we take values from $config['security']['add_project_groups'] as defaults)
                    $addProjectGroups = isset($config[ConfigManager::SECURITY][ConfigManager::ADD_PROJECT_GROUPS])
                        ? array_filter((array) $config[ConfigManager::SECURITY][ConfigManager::ADD_PROJECT_GROUPS])
                        : false;
                    $addProjectGroups = isset($config[ConfigManager::PROJECTS][ConfigManager::ADD_GROUPS_ONLY])
                        ? array_filter((array) $config[ConfigManager::PROJECTS][ConfigManager::ADD_GROUPS_ONLY])
                        : $addProjectGroups;
                    if ($addProjectGroups) {
                        $this->enforce([self::MEMBER => $addProjectGroups]);
                    }
                    break;
                case self::GROUP_ADD_ALLOWED:
                    // this check will pass if user is allowed to add groups in Swarm and fails otherwise
                    // we honour Perforce restrictions, i.e. user must be admin if server >=2012.1
                    // or super for older servers (<2012.1)
                    $this->enforce($p4User->isServerMinVersion('2012.1') ? self::ADMIN : self::SUPER);
                    break;
                default:
                    throw new InvalidArgumentException(
                        self::PERMISSION_UNKNOWN
                    );
            }
        }

        return $this;
    }

    /**
     * Ensure at least one of the specified checks passes throwing on failure.
     * See class docs for usage.
     *
     * @param   string|array    $checks     the tests to try, one must pass
     * @return  Permissions     to maintain a fluent interface
     * @throws  ForbiddenException          if the user is logged in but fails a check
     * @throws  InvalidArgumentException   for invalid checks or invalid data on a check
     * @throws  Exception                  if unexpected errors occur
     */
    public function enforceOne($checks)
    {
        foreach ($checks as $key => $value) {
            try {
                $this->enforce([$key => $value]);
                return $this;
            } catch (PermissionsException $e) {
                // ignored if we hit at least one success
            }
        }

        // if we didn't encounter any passing checks, throw either the last exception
        // we hit or, if no checks were present, a complaint about the lack of checks.
        throw isset($e) ? $e : new ForbiddenException(self::ENFORCE_CALLED_WITH_NO_CONDITIONS);
    }

     /**
     * Get max access level for the given connection. The value is stored in time-based session cache.
     *
     * @return  string|false    max level access or false
     * @throws ConfigException
     */
    protected function getMaxAccess()
    {
        $p4      = $this->services->get(ConnectionFactory::P4_USER);
        $session = $this->services->get(SwarmSession::SESSION);
        $key     = md5(serialize([$p4->getUser(), $p4->getPort()]));
        $cache   = new SessionContainer(static::MAX_ACCESS_CACHE_CONTAINER, $session);

        try {
            $maxAccess = $cache[$key];
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), self::IS_IMMUTABLE) === false) {
                throw $e;
            }
            $session->start();
            $maxAccess = $cache[$key];
            $session->writeClose();
        }

        // if max-access is cached, we're done
        if ($maxAccess) {
            return $maxAccess;
        }
        // session cache is empty or has expired, determine max access level from the connection
        $remoteIp     = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $maxAccess    = $p4->getMaxAccess();
        $remoteAccess = $remoteIp && $this->getEmulateIpProtections() ? $p4->getMaxAccess($remoteIp) : $maxAccess;

        // we take the weaker value of general vs. host-restricted access if they differ
        if ($maxAccess !== $remoteAccess) {
            // if we don't recognize the access level, log the case and return false
            $levels = array_flip([self::LIST, self::READ, self::OPEN, self::WRITE, self::ADMIN, self::SUPER]);
            if (!isset($levels[$maxAccess]) || !isset($levels[$remoteAccess])) {
                $logger = $this->services->get(SwarmLogger::SERVICE);
                $logger->warn(
                    "Unrecognized access level '" . (!isset($levels[$maxAccess]) ? $maxAccess : $remoteAccess)
                );
                return false;
            }
            $maxAccess = $levels[$remoteAccess] < $levels[$maxAccess] ? $remoteAccess : $maxAccess;
        }

        // update the max-access cache and flag it to expire after 1 hour
        $session->start();
        $cache[$key] = $maxAccess;
        $cache->setExpirationSeconds(static::MAX_ACCESS_CACHE_EXPIRY, $key);
        $session->writeClose();

        return $maxAccess;
    }
}
