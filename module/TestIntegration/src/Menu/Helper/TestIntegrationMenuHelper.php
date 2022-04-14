<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Menu\Helper;

use Application\Config\Services;
use Application\Permissions\Exception\ForbiddenException;
use Interop\Container\ContainerInterface;
use Application\Menu\Helper\BaseMenuHelper;
use Workflow\Model\IWorkflow;

/**
 * Class TestDefinitionMenuHelper
 * @package TestIntegration\Menu\Helper
 */
class TestIntegrationMenuHelper extends BaseMenuHelper
{
    /**
     * Specifically checks that workflows are enabled using the checker pattern and disables the menu item
     * if necessary.
     * @param ContainerInterface $container container to find services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $container, array $options = null)
    {
        parent::__construct($container, $options);
        $this->id = 'tests';
        try {
            $container->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER);
        } catch (ForbiddenException $e) {
            // Expected when workflows are disabled in configuration.
            $this->enabled = false;
        }
    }
}
