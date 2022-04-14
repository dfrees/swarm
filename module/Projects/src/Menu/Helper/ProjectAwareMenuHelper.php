<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Menu\Helper;

use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Log\SwarmLogger;
use Application\Menu\Helper\BaseMenuHelper;
use Application\Menu\Helper\IMenuHelper;
use Application\Permissions\Permissions;
use Interop\Container\ContainerInterface;

/**
 * Context-aware menu helper that that modifies a menu item for
 * a given project or returns the original item if no context is provided
 * Class ProjectAwareMenuHelper
 * @package Application\Menu\Helper
 */
class ProjectAwareMenuHelper extends BaseMenuHelper
{
    protected $project = null;

    /**
     * @inheritDoc
     * Overrides the BaseMenuHelper's constructor to account for project-specific options
     * @param ContainerInterface $container
     * @param array|null $options
     */
    public function __construct(ContainerInterface $container, array $options = null)
    {
        parent::__construct($container, $options);
        // When there is a context extract the values related to a project
        $this->project = $options[IMenuHelper::CONTEXT] ?? null;
    }

    /**
     * Builds a menu item and checks to see whether it is available based upon the settings for the
     * current project context.
     * @return array|null
     */
    public function getMenu()
    {
        $item = $this->buildMenu();
        // Clear the menu item where there is a project and its attributes prohibit it
        if ($this->project && $this->isDisabled()) {
            $item = null;
        }
        return $item;
    }

    /**
     * Build a menu item that includes the projectId if necessary
     * @return array|null
     */
    protected function buildMenu()
    {
        $item = parent::buildMenu();
        if ($this->project) {
            if (filter_var($item[self::TARGET], FILTER_VALIDATE_URL) === false) {
                $item[self::TARGET] = "/projects/" . urlencode($this->project->getId()) . $item[self::TARGET];
            }
        }
        return $item;
    }

    /**
     * Report whether this menuitem is unavailable for the current project context
     * @return bool
     */
    protected function isDisabled()
    {
        $disabled = false;
        switch ($this->id) {
            case 'changes':
            case 'files':
                $disabled = count($this->project->getBranches()) === 0;
                break;
            case 'jobs':
                $disabled = empty(trim($this->project->getJobview()));
                break;
            case 'overview':
                $disabled = empty($this->services->get(Services::GET_PROJECT_README)->getReadme($this->project));
                break;
            case 'settings':
                $disabled = !$this->canAccessSettings();
                break;
            default:
                break;
        }
        return $disabled;
    }

    /**
     * Determines whether or not the user has permission to edit project settings
     * @return bool
     */
    protected function canAccessSettings()
    {
        $config      = $this->services->get(ConfigManager::CONFIG);
        $permissions = $this->services->get(Permissions::PERMISSIONS);
        $hasOwner    = ['admin', 'owner' => $this->project];
        $noOwner     = ['admin', 'member' => $this->project];
        try {
            $allowSettings = ConfigManager::getValue($config, ConfigManager::PROJECTS_ALLOW_VIEW_SETTINGS);
        } catch (\Exception $error) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->debug('There was an issue getting the config for: ' . ConfigManager::PROJECTS_ALLOW_VIEW_SETTINGS);
            $logger->debug($error->getMessage());
            return false;
        }
        if ($allowSettings) {
            $hasOwner = array_merge($hasOwner, ['member' => $this->project]);
        }
        return $this->project->hasOwners() ? $permissions->isOne($hasOwner) : $permissions->isOne($noOwner);
    }
}
