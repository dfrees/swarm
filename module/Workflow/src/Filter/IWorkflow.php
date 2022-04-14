<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Workflow\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IWorkflow. Define common values and specification to support the workflow filter
 * @package Workflow\Filter
 */
interface IWorkflow extends InvokableService
{
    // Used in options to indicate a valid set of rules
    const RULE_SET = 'ruleSet';
}
