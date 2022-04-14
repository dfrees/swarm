<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Workflow;

use Application\Config\ConfigManager;
use Application\Checker;
use Application\Config\IDao;
use Application\Permissions\Exception\ForbiddenException;
use Application\Config\ConfigException;

/**
 * Class WorkflowChecker. Class to enforce a workflow check. Configured in module.config.php to be
 * called when a workflow check is to be enforced
 * @package Workflow
 */
class WorkflowChecker extends Checker
{
    const WORKFLOW_ARE_NOT_ENABLED = 'Workflows are not enabled';

    /**
     * Checks if workflows are enabled in configuration.
     * @param string            $check      the name of the check
     * @param array|null        $options    optional data to assist the check
     * @throws ForbiddenException if workflows are not enabled in configuration and a return value has not been
     *                            requested with $options[Checker::RETURN_VALUE] set to any value
     * @throws ConfigException
     * @return bool whether workflows are enabled if requested with $options[Checker::RETURN_VALUE] set to any value,
     * otherwise an ForbiddenException will be thrown
     */
    public function check(string $check, array $options = null)
    {
        $workflowsEnabled = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::WORKFLOW_ENABLED,
            false
        );

        $return = $options && isset($options[Checker::RETURN_VALUE]);
        if ($workflowsEnabled) {
            // Enabled so ensure that we have a global workflow in key data
            $wfDao            = $this->services->get(IDao::WORKFLOW_DAO);
            $migratedWorkflow = $wfDao->importGlobalWorkflow();
            // Ensure we have global tests in key data. Do this after the workflow so we can add the global
            // tests to the global workflow. Only do this step if we did actually migrate the global
            // workflow; if a user managed to delete all the test definitions we do not want to keep trying
            // to migrate them - should be a one shot process
            if ($migratedWorkflow) {
                $tdDao = $this->services->get(IDao::TEST_DEFINITION_DAO);
                $tdDao->importGlobalTests();
            }
        } else {
            if (!$return) {
                throw new ForbiddenException(self::WORKFLOW_ARE_NOT_ENABLED);
            }
        }
        return $workflowsEnabled;
    }
}
