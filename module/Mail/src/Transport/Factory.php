<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Mail\Transport;

use Application\Config\ConfigManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Mail\Transport\File;
use Laminas\Mail\Transport\FileOptions;
use Laminas\Mail\Transport\SmtpOptions;
use Laminas\Mail\Transport\Sendmail;

/**
 * Factory to build mail transport.
 * @package Mail\Transport
 */
class Factory implements FactoryInterface
{
    const SERVICE = 'mailer';

    /**
     * Builds a transport based on configuration.
     *
     * If ['mail']['transport']['path'] is provided in config a file transport is built
     * else if ['mail']['transport']['host'] is provided in config an Smtp transport is built
     * otherwise a Sendmail transport is built with ['mail']['transport']['sendmail_parameters']
     *
     * @param ContainerInterface    $services       application services
     * @param string                $requestedName  name of instance
     * @param array|null            $options        not currently used
     * @return Smtp|object|File|Sendmail
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config    = $services->get(ConfigManager::CONFIG);
        $transport = $config['mail']['transport'];

        if ($transport['path'] ?? null) {
            return new File(new FileOptions($transport));
        } elseif ($transport['host'] ?? null) {
            return new Smtp(new SmtpOptions($transport));
        }
        return new Sendmail($transport['sendmail_parameters'] ?? null);
    }
}
