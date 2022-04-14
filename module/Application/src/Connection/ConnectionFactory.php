<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Connection;

use Application\Config\ConfigManager;
use Application\Log\SwarmLogger;
use Interop\Container\ContainerInterface;
use P4\ClientPool\ClientPool;
use P4\Connection\Connection;
use P4\Connection\Exception\CommandException;
use P4\Log\Logger as P4Logger;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Authentication\Storage\Session;
use Application\Permissions\Exception\UnauthorizedException;
use Users\Model\Config as UsersConfig;
use P4\Connection\ConnectionInterface;
use P4\Exception as P4Exception;

/**
 * Sets up a connection based on the params passed to constructor combined
 * with those present on the service locator config.
 */
class ConnectionFactory implements FactoryInterface
{
    // PSR1.Methods.CamelCapsMethodName.NotCamelCaps has been disabled for this class in ruleset.xml
    // to enable the use of $this->{$requestedName}($services) where $requestedName is a service, for
    // example p4_config, p4_admin, p4_user

    const CLIENT_PREFIX = 'swarm-';
    // P4 parameter definitions
    const PORT     = 'port';
    const USER     = 'user';
    const PASSWORD = 'password';
    const CLIENT   = 'client';
    const TICKET   = 'ticket';
    const IPADDR   = 'ipaddr';
    const SVRNAME  = 'svrname';
    const SVRPASS  = 'svrpass';
    // Service definitions
    const P4_USER   = 'p4_user';
    const P4_ADMIN  = 'p4_admin';
    const P4        = 'p4';
    const P4_CONFIG = 'p4_config';

    /**
     * @inheritDoc
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return $this->{$requestedName}($services);
    }

    /**
     * Build a connection based on P4 parameters
     * @param ContainerInterface    $services   application services
     * @param array                 $p4         p4 parameters
     * @return ConnectionInterface              the connection
     * @throws P4Exception
     */
    public function buildConnection(ContainerInterface $services, array $p4) : ConnectionInterface
    {
        $logger = $services->get(SwarmLogger::SERVICE);

        // write p4 activity to the log
        P4Logger::setLogger($logger);

        // place the p4trust file under the data path.
        // the trust file has to be in a writable
        // location to support ssl enabled servers
        putenv('P4TRUST=' . DATA_PATH . '/p4trust');

        // use the factory to get an actual connection
        $connection = Connection::factory(
            $p4[self::PORT]     ?? null,
            $p4[self::USER]     ?? null,
            $p4[self::CLIENT]   ?? null,
            $p4[self::PASSWORD] ?? null,
            $p4[self::TICKET]   ?? null,
            null,
            $p4[self::IPADDR]   ?? null,
            $p4[self::SVRNAME]  ?? null,
            $p4[self::SVRPASS]  ?? null
        );

        if (isset($p4[self::IPADDR]) && isset($p4[self::USER]) && isset($p4[self::SVRNAME])) {
            // Build the message for the log file to give the admin or support help debugging
            $message = "P4 (" . spl_object_hash($this) . ") start command: Authenticating ".$p4[self::IPADDR]
                . " for user " . $p4[self::USER];
            // Output the details at level 7.
            $logger->log(P4Logger::DEBUG, $message);
        }
        // set the program and version
        $connection->setProgName(VERSION_NAME);
        $connection->setProgVersion(VERSION_RELEASE . '/' . VERSION_PATCHLEVEL);

        // if slow command logging thresholds are specified pass them along
        if (isset($p4['slow_command_logging'])) {
            $connection->setSlowCommandLogging($p4['slow_command_logging']);
        }

        // if pre-run callbacks were specified, add them
        if (isset($p4['callbacks']['pre_run'])) {
            foreach ((array) $p4['callbacks']['pre_run'] as $callback) {
                $connection->addPreRunCallback($callback);
            }
        }

        // if post-run callbacks were specified, add them
        if (isset($p4['callbacks']['post_run'])) {
            foreach ((array) $p4['callbacks']['post_run'] as $callback) {
                $connection->addPostRunCallback($callback);
            }
        }

        // give the connection a client manager
        $prefix = static::CLIENT_PREFIX;
        $connection->setService(
            'clients',
            function ($p4) use ($services, $prefix) {
                // we base our maximum number of clients on the number of workers
                // if we cannot determine the worker limit we use the default of 3.
                $config  = $services->get('Configuration');
                $workers = $config['queue']['workers'] ?? 3;

                // set the root and max. we double the workers to allow for use
                // of clients in web processes as well.
                // @todo user partitioning logic should move into the client pool
                $clients = new ClientPool($p4);
                $clients->setRoot(DATA_PATH . '/clients/' . strtoupper(bin2hex($p4->getUser())))
                        ->setPrefix($prefix)
                        ->setMax($workers * 2);

                return $clients;
            }
        );

        // lazily expose the depot_storage service via the Zend service manager
        $connection->setService(
            ConfigManager::DEPOT_STORAGE,
            function () use ($services) {
                return $services->get(ConfigManager::DEPOT_STORAGE);
            }
        );

        // lazily expose the translator service via the Zend service manager
        $connection->setService(
            'translator',
            function () use ($services) {
                return $services->get('translator');
            }
        );

        return $connection;
    }

    /**
     * Gets the 'p4' service connection.
     * @param ContainerInterface    $services       application services
     * @return ConnectionInterface                  if authenticated the 'p4_user' service connection is returned,
     *                                              otherwise the 'p4_admin' service connection is returned
     */
    private function p4(ContainerInterface $services) : ConnectionInterface
    {
        // if we have a logged in user, we want to use their connection
        // to perforce. otherwise, we will use the admin connection
        if ($services->get('permissions')->is('authenticated')) {
            return $services->get(self::P4_USER);
        }

        // doesn't appear anyone is logged in, run as admin
        return $services->get(self::P4_ADMIN);
    }

    /**
     * Gets the 'p4_config' service.
     * @param ContainerInterface    $services       application services
     * @return array                                the 'p4_config' configuration
     */
    private function p4_config(ContainerInterface $services)
    {
        $config = $services->get(ConfigManager::CONFIG) + [self::P4 => []];
        $p4     = (array) $config[self::P4];

        // handle multi-p4-server setups
        if (P4_SERVER_ID) {
            if (!isset($p4[P4_SERVER_ID][self::PORT])) {
                throw new \RuntimeException("Invalid P4 Server ID");
            }

            // note we merge the general p4 config with the server specific
            // config such that specific options win and we fallback to general
            $p4 = $p4[P4_SERVER_ID] + $p4;
        }

        return $p4;
    }

    /**
     * Gets the 'p4_admin' service connection.
     * @param ContainerInterface    $services       application services
     * @return ConnectionInterface                  the 'p4_admin' service connection
     * @throws NoServerSelectedException
     * @throws P4Exception
     */
    private function p4_admin(ContainerInterface $services) : ConnectionInterface
    {
        if (MULTI_P4_SERVER && !strlen(P4_SERVER_ID)) {
            throw new NoServerSelectedException;
        }

        $p4 = $services->get(self::P4_CONFIG);
        return $this->buildConnection($services, $p4);
    }

    /**
     * Gets the 'p4_user' service connection.
     * @param ContainerInterface    $services       application services
     * @return ConnectionInterface                  the 'p4_user' service connection
     * @throws UnauthorizedException
     * @throws P4Exception
     */
    private function p4_user(ContainerInterface $services) : ConnectionInterface
    {
        if (MULTI_P4_SERVER && !strlen(P4_SERVER_ID)) {
            throw new UnauthorizedException;
        }

        $p4       = $services->get(self::P4_CONFIG);
        $auth     = $services->get('auth');
        $identity = $auth->hasIdentity() ? (array) $auth->getIdentity() : [];

        // can't get a user specific connection if user is not authenticated
        if (!isset($identity['id']) || !strlen($identity['id'])) {
            throw new UnauthorizedException;
        }

        // Validate that we have enabled proxy mode.
        if (isset($p4[ConfigManager::PROXY_MODE]) && $p4[ConfigManager::PROXY_MODE] === true) {
            // set additional settings to allow P4API to valid connection.
            $p4[self::IPADDR]  = $_SERVER['REMOTE_ADDR']    ?? null;
            $p4[self::SVRNAME] = $p4[self::USER]            ?? null;
            $p4[self::SVRPASS] = $p4[self::PASSWORD]        ?? null;
        }

        // tweak the 'p4' settings to use the users id/ticket and ensure password isn't present
        $p4[self::USER]   = $identity['id'];
        $p4[self::TICKET] = $identity[self::TICKET] ?? null;
        unset($p4[self::PASSWORD]);
        $connection = $this->buildConnection($services, $p4);
        // verify the user is authenticated.
        // if the ticket/password is invalid, try to clean up the auth and user
        // services to reflect the anonymous state (someone may have fetched them
        // before us leaving them otherwise in a bad state).
        if (!$connection->isAuthenticated()) {
            // if our bad connection is the default; clear it
            if (Connection::hasDefaultConnection() && Connection::getDefaultConnection() === $connection) {
                Connection::clearDefaultConnection();
            }

            // if using session-based auth, empty/destroy the session
            if ($auth->getStorage() instanceof Session) {
                $session = $services->get('session');
                $session->start();
                $auth->getStorage()->write(null);
                $session->destroy(['send_expire_cookie' => true, 'clear_storage' => true]);
                $session->writeClose();
            }

            // if the user service is already instantiated, clear
            // the existing object; we want to try and clear out
            // anyone who already has a copy.
            try {
                if ($services->get(self::USER)) {
                    $services->get(self::USER)
                        ->setId(null)
                        ->setEmail(null)
                        ->setFullName(null)
                        ->setJobView(null)
                        ->setReviews([])
                        ->setConfig(new UsersConfig);
                }
            } catch (CommandException $e) {
                // Cannot get the user from services as credentials are incorrect, we will
                // throw the UnauthorizedException with the command failure message
                throw new UnauthorizedException($e->getMessage(), $e->getCode(), $e);
            }

            throw new UnauthorizedException;
        } elseif (in_array($connection->getMFAStatus(), ['required'])) {
            // Multi-factor authentication(mfa) is required, check the auth. workflow for this route
            $router     = $services->get('router');
            $config     = $services->get(ConfigManager::CONFIG);
            $routeMatch = $router->match($services->get('request'));
            if (!in_array($routeMatch->getMatchedRouteName(), $config['security']['mfa_routes'])) {
                throw new UnauthorizedException;
            }
        }
        return $connection;
    }
}
