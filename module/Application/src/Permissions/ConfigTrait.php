<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Connection\ConnectionFactory;
use Application\Config\ConfigException;

/**
 * Trait ConfigTrait. To provide access to global and instance level configuration concerned with permissions.
 * @package Application\Permissions
 */
trait ConfigTrait
{

    /**
     * Gets whether we emulate IP protections when checking permissions. Initially defaults to global level setting
     * (default of true if no global setting). Value can be overridden at the P4D instance level by setting it at the
     * instance level.
     * Relies on the trait user to have $this->services set
     * @return bool true if we emulate IP protections, false otherwise
     * @throws ConfigException
     */
    public function getEmulateIpProtections()
    {
        $config               = $this->services->get(ConfigManager::CONFIG);
        $emulateIpProtections = ConfigManager::getValue(
            $config,
            IConfigDefinition::SECURITY_EMULATE_IP_PROTECTIONS,
            true
        );

        $p4Config = $this->services->get(ConnectionFactory::P4_CONFIG);
        if (isset($p4Config[IConfigDefinition::EMULATE_IP_PROTECTIONS])
            && is_bool($p4Config[IConfigDefinition::EMULATE_IP_PROTECTIONS])) {
            $emulateIpProtections = $p4Config[IConfigDefinition::EMULATE_IP_PROTECTIONS];
        }
        return $emulateIpProtections;
    }
}
