<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration;

use Application\Checker;
use Application\Config\ConfigManager;
use Application\Permissions\Exception\ForbiddenException;
use Application\Config\ConfigException;

/**
 * Class TestDefinitionChecker. Checks for test definitions.
 * @package TestIntegration
 */
class TestDefinitionChecker extends Checker
{
    const TEST_DEFINITIONS_ARE_NOT_ENABLED = 'Test definitions are not enabled';

    /**
     * Performs a check to to see that workflows are enabled in configuration. Currently enabling of
     * test definitions is linked directly to the workflow configuration setting.
     * @param string            $check      the name of the check
     * @param array|null        $options    optional data to assist the check
     * @throws ForbiddenException if workflows are not enabled in configuration
     * @throws ConfigException
     */
    public function check(string $check, array $options = null)
    {
        // Check if workflows are enabled to see if tests are enabled. Currently tests
        // depend on the workflow value but having a custom checker allows us to easily
        // vary that later
        $workflowsEnabled = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::WORKFLOW_ENABLED,
            false
        );
        if (!$workflowsEnabled) {
            throw new ForbiddenException(self::TEST_DEFINITIONS_ARE_NOT_ENABLED);
        }
    }
}
