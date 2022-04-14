<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration;

/**
 * Class Module for test integrations
 * @package TestIntegration
 */
class Module
{
    /**
     * Load and merge in configuration.
     * @return mixed
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
