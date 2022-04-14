<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Workflow\Filter;

use Application\Config\IDao;
use Application\InputFilter\DirectInput;
use Application\Validator\UniqueForField;
use Laminas\InputFilter\InputFilter as LaminasInputFilter;
use Application\Validator\IsArray;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\StringLength;
use Workflow\Model\IWorkflow;
use Laminas\InputFilter\Input;
use Workflow\Filter\IWorkflow as IWorkflowFilter;
use Application\Validator\Owners;
use Workflow\Validator\Rule;
use Application\Filter\FormBoolean;
use Workflow\Validator\Tests;
use Workflow\Filter\Tests as TestsFilter;

/**
 * Class Workflow. Filter to validate workflow data
 * @package Workflow\Filter
 */
class Workflow extends LaminasInputFilter implements IWorkflowFilter
{
    protected $services;
    private $existingId;

    /**
     * Workflow constructor. Supports options
     *
     * mode => the mode, for example MODE_ADD, MODE_EDIT or MODE_VIEW
     *
     * This filter is for standard workflows.
     * @see GlobalWorkflow
     *
     * Mode is allowed in options as typically we want to add filters at construction time when using as a service.
     * If an Input uses the value it must be set before the Input is added so that it is available

     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        // We need to be aware of an existing id (and update being performed) so that if a name
        // is changed we can determine we are not changing it to the same as a different record
        $this->existingId = isset($options[IWorkflow::ID]) ? $options[IWorkflow::ID] : null;
        $this->addDescriptionFilter();
        $this->addNameFilter();
        $this->addOnSubmitFilter();
        $this->addAutoApproveFilter();
        $this->addEndRulesFilter();
        $this->addCountedVotesFilter();
        $this->addOwnerFilter();
        $this->addUserExclusionsFilter();
        $this->addGroupExclusionsFilter();
        $this->addSharedFilter();
        $this->addTestsFilter();
    }

    /**
     * Set up the filter for 'tests'
     */
    protected function addTestsFilter()
    {
        $input = new DirectInput(IWorkflow::TESTS);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new TestsFilter());
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach(new Tests($this->services));
        $this->add($input);
    }

    /**
     * Set up the filter for 'shared'
     */
    protected function addSharedFilter()
    {
        $input = new DirectInput(IWorkflow::SHARED);
        $input->setRequired(true);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Set up the filter for 'group_exclusions'
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
     * Set up the filter for 'user_exclusions'
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

    /**
     * Set up the filter for 'owners'
     */
    protected function addOwnerFilter()
    {
        $input = new Input(IWorkflow::OWNERS);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach(new Owners($this->services));
        $this->add($input);
    }

    /**
     * Set up the filter for 'counted_votes'
     */
    protected function addCountedVotesFilter()
    {
        $input = new Input(IWorkflow::COUNTED_VOTES);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator(Rule::VALID_COUNTED_VOTES));
        $this->add($input);
    }

    /**
     * Set up the filter for 'end_rules'
     */
    protected function addEndRulesFilter()
    {
        $inputFilter = new LaminasInputFilter();

        $input = new Input(IWorkflow::UPDATE);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator(Rule::VALID_END_RULES));
        $inputFilter->add($input);

        $this->add($inputFilter, IWorkflow::END_RULES);
    }

    /**
     * Set up the filter for 'auto_approve'
     */
    protected function addAutoApproveFilter()
    {
        $input = new Input(IWorkflow::AUTO_APPROVE);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator(Rule::VALID_AUTO_APPROVE));
        $this->add($input);
    }

    /**
     * Set up the filter for 'on_submit'
     */
    protected function addOnSubmitFilter()
    {
        $inputFilter = new LaminasInputFilter();

        $input = new Input(IWorkflow::WITH_REVIEW);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator(Rule::VALID_WITH_REVIEW));
        $inputFilter->add($input);

        $input = new Input(IWorkflow::WITHOUT_REVIEW);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach($this->createRuleValidator(Rule::VALID_WITHOUT_REVIEW));
        $inputFilter->add($input);

        $this->add($inputFilter, IWorkflow::ON_SUBMIT);
    }

    /**
     * Set up the filter for 'name'
     */
    protected function addNameFilter()
    {
        $input = new Input(IWorkflow::NAME);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new StringLength(['min' => 1]), true)
            ->attach(
                new UniqueForField(
                    $this->services,
                    IWorkflow::WORKFLOW,
                    IDao::WORKFLOW_DAO,
                    IWorkflow::NAME,
                    [IWorkflow::ID => $this->existingId]
                )
            );
        $this->add($input);
    }

    /**
     * Set up the filter for 'description'
     */
    protected function addDescriptionFilter()
    {
        $input = new DirectInput(IWorkflow::DESCRIPTION);
        $input->setRequired(true);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 0]));
        $this->add($input);
    }

    /**
     * Create a validator for 'rule' and 'mode' values for a standard workflow
     * @param array $ruleSet the rule set that should be considered valid.
     * @return Rule
     */
    protected function createRuleValidator(array $ruleSet = [])
    {
        return new Rule(
            $this->services,
            [
                IWorkflowFilter::RULE_SET => $ruleSet
            ]
        );
    }
}
