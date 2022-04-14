<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TagProcessor\Filter;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Interop\Container\ContainerInterface;

/**
 * Class Keywords
 *
 * @package TagProcessor\Filter
 */
class WipKeyword extends TagFilter
{
    /**
     * Convenience constructor allows passing patterns at creation time.
     *
     * @param ContainerInterface $services
     * @param array|null $options
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $config                       = $services->get(ConfigManager::CONFIG);
        $options[TagFilter::PATTERNS] = ConfigManager::getValue(
            $config, ConfigManager::REVIEW_WIP
        );
        parent::__construct($services, $options);
    }
}
