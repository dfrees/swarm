<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Depot;

use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Interop\Container\ContainerInterface;
use Record\File\FileService;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for 'depot_storage'
 * @package Application\Depot
 */
class FileStorageFactory implements FactoryInterface
{
    /**
     * @inheritDoc
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get(ConfigManager::CONFIG);
        $config = $config[ConfigManager::DEPOT_STORAGE] + [ConfigManager::BASE_PATH => null];

        $depot = new FileService($services->get(ConnectionFactory::P4_ADMIN));
        $depot->setConfig($config);

        return $depot;
    }
}
