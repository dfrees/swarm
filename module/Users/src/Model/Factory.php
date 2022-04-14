<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Model;

use Api\Controller\ICacheController;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Interop\Container\ContainerInterface;
use P4\Exception;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Users\Authentication\Service as AuthService;

// User requires its own factory rather than being able to use InvokableServiceFactory
// because of a mismatch with constructors when it would be required to implement InvokableService
// and extend Users\Model\User
/**
 * Service to get a user.
 * @package Users\Model
 */
class Factory implements FactoryInterface
{
    const PASSWORD_INVALID_OR_UNSET = 'Perforce password (P4PASSWD) invalid or unset.';
    const SECURITY_LEVEL_CHANGED    = 'The security level of this server requires the password to be reset.';
    private $services;

    /**
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $this->services = $services;

        $auth     = $services->get(AuthService::AUTH);
        $logger   = $services->get(SwarmLogger::SERVICE);
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $identity = (array) $auth->getIdentity() + ['id' => null];
        try {
            return $this->getUserObject($identity, $p4Admin);
        } catch (Exception $e) {
            // Just in case someone has changed the password or security we should reset the cache.
            if (strpos($e->getMessage(), self::PASSWORD_INVALID_OR_UNSET) === false ||
                strpos($e->getMessage(), self::SECURITY_LEVEL_CHANGED) === false) {
                $logger->trace("Resetting all cache in case password or security has changed");
                $result = $services->get(ICacheController::CONFIG_CACHE)->delete(null);
                $logger->trace("Reset Results:: ". var_export($result, true));
            }
            return $this->getUserObject($identity, $p4Admin);
        }
    }

    private function getUserObject($identity, $p4Admin)
    {
        $userDao = $this->services->get(IModelDAO::USER_DAO);
        // if the user exists; return the full object
        if ($userDao->exists($identity[ 'id' ], $p4Admin)) {
            return $userDao->fetchById($identity[ 'id' ], $p4Admin);
        }
        // user didn't exist; return an empty model (will have a null id)
        return new User($p4Admin);
    }
}
