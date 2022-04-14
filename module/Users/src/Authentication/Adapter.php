<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Authentication;

use Application\Config\ConfigManager;
use Application\Model\ServicesModelTrait;
use P4\Connection\Connection;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\LoginException;
use P4\Spec\Exception\NotFoundException;
use P4\Spec\User;
use P4\Validate\UserName as UserNameValidator;
use Laminas\Authentication\Adapter\AdapterInterface;
use Laminas\Authentication\Result;

class Adapter implements AdapterInterface
{
    use ServicesModelTrait;

    protected $user;
    protected $password;
    protected $p4;
    protected $userP4;
    protected $config;
    protected $saml;

    /**
     * Sets username, password and connection for authentication.
     * @param string                $user
     * @param string                $password
     * @param ConnectionInterface   $p4
     * @param array|null            $config
     * @param bool                  $saml       whether the adapter should use Saml, defaults to false
     */
    public function __construct($user, $password, ConnectionInterface $p4, $config = null, $saml = false)
    {
        $this->user     = $user;
        $this->password = $password;
        $this->p4       = $p4;
        $this->config   = $config;
        $this->saml     = $saml;
    }

    /**
     * Performs an authentication attempt
     * @return Result
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     */
    public function authenticate()
    {
        // note when we fetch a user against a case insensitive server,
        // the user id may come back with different case.
        // from the fetch point on we use the authoritative user id returned
        // by the server not the user provided value.
        $user      = false;
        $validator = new UserNameValidator;
        if ($validator->isValid($this->user)) {
            try {
                $user = ServicesModelTrait::getUserDao()->fetchById($this->user, $this->p4);
            } catch (NotFoundException $e) {
                // User does not exist in the cache or perforce - note that configurable
                // auth.ldap.userautocreate may be set so we will still try to login below.
                // It would require super access to read the configurable so it is easier
                // just to do an extra login and not worry if it fails.
            }
        }

        // if this is a service/operator user they cannot run the required commands
        // to use swarm; return a failure
        if ($user && ($user->getType() == User::SERVICE_USER || $user->getType() == User::OPERATOR_USER)) {
            return new Result(Result::FAILURE_UNCATEGORIZED, null);
        }

        // Set remoteAddr to null and then if we have the settings enabled fetch the REMOTE_ADDR
        $remoteAddr = null;
        $p4Config   = $this->config ? $this->config[ConfigManager::P4] : null;
        $proxyMode  = $p4Config ? $p4Config[ConfigManager::PROXY_MODE] : null;
        // If we are in multiP4D mode check that everything is set and use that value instead of default.
        if (MULTI_P4_SERVER && P4_SERVER_ID && isset($p4Config[P4_SERVER_ID])
            && isset($p4Config[P4_SERVER_ID][ConfigManager::PROXY_MODE])) {
            $proxyMode = $p4Config[P4_SERVER_ID][ConfigManager::PROXY_MODE];
        }

        if (isset($proxyMode) && $proxyMode === true && isset($_SERVER['REMOTE_ADDR'])) {
            $remoteAddr = $_SERVER['REMOTE_ADDR'];
        }

        // try and authenticate against current p4 server.
        $this->userP4 = Connection::factory(
            $this->p4->getPort(),
            $this->user,
            null,
            $this->password,
            null,
            null,
            $remoteAddr,
            $this->p4->getUser(),
            $this->p4->getPassword()
        );

        if ($this->saml === true) {
            $this->userP4->setSamlLogin(
                $this->user,
                ConfigManager::getValue($this->config, ConfigManager::SAML_HEADER),
                IHelper::SAML_REQUEST
            );
        }

        // if the password looks like it may be a ticket;
        // test it for that case first
        if (preg_match('/^[A-Z0-9]{32}$/', $this->password)) {
            if ($this->userP4->isAuthenticated()) {
                return new Result(
                    Result::SUCCESS,
                    ['id' => $this->user, 'ticket' => $this->password]
                );
            }
        }

        // try to login using the password
        // get a host unlocked ticket so we can use it with other services
        try {
            $ticket = $this->userP4->login(true);

            return new Result(
                Result::SUCCESS,
                ['id' => $this->user, 'ticket' => $ticket]
            );
        } catch (LoginException $e) {
            return new Result(
                $e->getCode(),
                null,
                [$e->getMessage()]
            );
        }
    }

    /**
     * Get the connection instance most recently used to authenticate the user.
     *
     * @return  Connection|null     connection used for login or null if no auth attempted
     */
    public function getUserP4()
    {
        return $this->userP4;
    }
}
