<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Saml\Service;

use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Interop\Container\ContainerInterface;
use OneLogin\Saml2;

/**
 * Class Saml
 *
 * @package Saml\Service
 */
class Saml extends Saml2\Auth implements InvokableService
{
    /**
     * @inheritdoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $config = $services->get(ConfigManager::CONFIG);
        $logger = $services->get(SwarmLogger::SERVICE);
        if (isset($config['saml'])) {
            // Build a path for the IDP to callback to that has the instance
            // identifier as part of it. The config will specify the host and
            // port, we will append the endpoint detail
            if (!isset($config['saml']['sp']['assertionConsumerService']['url']) ||
                empty($config['saml']['sp']['assertionConsumerService']['url'])) {
                $logger->err('Configuration error sp assertionConsumerService url not set');
                throw new \RuntimeException("Saml sp assertionConsumerService url not set");
            }
            $url = $config['saml']['sp']['assertionConsumerService']['url'] .
                (P4_SERVER_ID ? "/" . P4_SERVER_ID : "") .
                '/api/v10/session';

            $config['saml']['sp']['assertionConsumerService']['url'] = $url;
            $logger->debug('Saml sp assertionConsumerService url: ' . $url);
            try {
                parent::__construct($config['saml']);
                $logger->debug('Saml authentication instance constructed successfully');
            } catch (Saml2\Error $e) {
                $logger->err('OneLogin_Saml2_Error: ' . $e);
                throw $e;
            }
        } else {
            throw new \RuntimeException("Saml requested - no configuration found.");
        }
    }
}
