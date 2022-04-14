<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Filter;

use Application\InputFilter\DirectInput;
use Application\Validator\IsArray;
use Interop\Container\ContainerInterface;
use Workflow\Filter\IWorkflow as IWorkflowFilter;
use Workflow\Model\IWorkflow;
use Workflow\Validator\GlobalRule;
use Workflow\Validator\Rule;

/**
 * Class GlobalWorkflow. Specialisation of a filter for global workflows
 * @package Workflow\Filter
 */
class GlobalWorkflow extends Workflow
{
    /**
     * GlobalWorkflow constructor. Override to remove the 'shared' value as it is not relevant for global data
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        parent::__construct($services, $options);
        $this->remove(IWorkflow::SHARED);
    }

    /**
     * Override to create a validator for 'rule' and 'mode' values for a global workflow
     * @param array $ruleSet the rule set that should be considered valid.
     * @return GlobalRule|Rule
     */
    protected function createRuleValidator(array $ruleSet = [])
    {
        return new GlobalRule(
            $this->services,
            [
                IWorkflowFilter::RULE_SET => $ruleSet
            ]
        );
    }

    /**
     * Set up the filter for 'group_exclusions'. Override to allow values in the array
     */
    protected function addGroupExclusionsFilter()
    {
        $input = new DirectInput(IWorkflow::GROUP_EXCLUSIONS);
        $input->setRequired(true);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator());
        $this->add($input);
    }

    /**
     * Set up the filter for 'user_exclusions'. Override to allow values in the array
     */
    protected function addUserExclusionsFilter()
    {
        $input = new DirectInput(IWorkflow::USER_EXCLUSIONS);
        $input->setRequired(true);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator());
        $this->add($input);
    }
}
