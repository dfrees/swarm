<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Validator;

use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Validator\ArrayValuesValidator;
use Application\Validator\FlatArray;
use Laminas\Validator\AbstractValidator;
use Workflow\Model\IWorkflow;
use Interop\Container\ContainerInterface;
use Workflow\Filter\IWorkflow as IWorkflowFilter;

/**
 * Class Rule. Validates the rule and mode parts of a workflow rule
 * @package Workflow\Validator
 */
class Rule extends AbstractValidator implements InvokableService
{
    // Values for valid rules
    const VALID_WITH_REVIEW    = [IWorkflow::NO_CHECKING, IWorkflow::APPROVED, IWorkflow::STRICT];
    const VALID_WITHOUT_REVIEW = [IWorkflow::NO_CHECKING, IWorkflow::AUTO_CREATE, IWorkflow::REJECT];
    const VALID_END_RULES      = [IWorkflow::NO_CHECKING, IWorkflow::NO_REVISION];
    const VALID_COUNTED_VOTES  = [IWorkflow::ANYONE, IWorkflow::MEMBERS];
    const VALID_AUTO_APPROVE   = [IWorkflow::NEVER, IWorkflow::VOTES];

    const VALID_STANDARD_MODES = [IWorkflow::MODE_INHERIT];
    const VALID_GLOBAL_MODES   = [IWorkflow::MODE_POLICY, IWorkflow::MODE_DEFAULT];
    const VALID_KEYS           = [IWorkflow::RULE, IWorkflow::MODE];
    const INVALID              = 'invalid';
    protected $services;
    protected $validRules;
    protected $messageTemplates = [IWorkflow::MODE => '', IWorkflow::RULE => '', self::INVALID => ''];

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->validRules = $options[IWorkflowFilter::RULE_SET];
        $translator       = $services->get(TranslatorFactory::SERVICE);
        $translated       = $translator->t("Value is required and can't be empty");

        $this->messageTemplates[IWorkflow::MODE] = $translated;
        $this->messageTemplates[IWorkflow::RULE] = $translated;
        $this->messageTemplates[self::INVALID]   = $translator->t(
            "Only [%s] and [%s] are allowed", [IWorkflow::RULE, IWorkflow::MODE]
        );
        parent::__construct($options);
    }

    /**
     * Checks the 'rule' and the 'mode' to ensure validity. Uses the isGlobal and ruleSet options. ruleSet enables a
     * valid array of rules to be set based on the parent (for example on_submit->with_review). isGlobal is used as
     * global and standard workflows allow different valid mode values.
     * @param mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        $validMode          = false;
        $validRule          = false;
        $ruleValueValidator = $this->buildRuleValidator();
        $modeValidator      = $this->buildModeValidator();

        $invalid = array_diff(array_keys($value), self::VALID_KEYS);
        $hasMode = isset($value[IWorkflow::MODE]);
        $hasRule = isset($value[IWorkflow::RULE]);
        if ($hasMode) {
            $validMode = $modeValidator->isValid($value[IWorkflow::MODE]);
            if (!$validMode) {
                $this->abstractOptions['messages'] += $modeValidator->getMessages();
            }
        } else {
            $this->error(IWorkflow::MODE);
        }
        if ($hasRule) {
            $validRule = $ruleValueValidator->isValid($value[IWorkflow::RULE]);
            if (!$validRule) {
                $this->abstractOptions['messages'] += $ruleValueValidator->getMessages();
            }
        } else {
            $this->error(IWorkflow::RULE);
        }
        if ($invalid) {
            $this->error(self::INVALID);
        }
        return $hasMode && $hasRule && $validMode && $validRule && !$invalid;
    }

    /**
     * Build a validator to delegate to for mode
     * @return ArrayValuesValidator
     */
    protected function buildModeValidator()
    {
        return new ArrayValuesValidator(
            $this->services->get(TranslatorFactory::SERVICE),
            self::VALID_STANDARD_MODES,
            IWorkflow::MODE,
            IWorkflow::MODE
        );
    }

    /**
     * Build a validator to delegate to for rule
     * @return FlatArray|ArrayValuesValidator
     */
    protected function buildRuleValidator()
    {
        if ($this->validRules) {
            return new ArrayValuesValidator(
                $this->services->get(TranslatorFactory::SERVICE),
                $this->validRules,
                IWorkflow::RULE,
                IWorkflow::RULE
            );
        } else {
            // If no valid rules have been provided assume anything goes
            return new FlatArray;
        }
    }
}
