<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Validator;

use Application\I18n\TranslatorFactory;
use Application\Validator\ArrayValuesValidator;
use Workflow\Model\IWorkflow;

/**
 * Class GlobalRule. Specialisation for global rule and mode validation
 * @package Workflow\Validator
 */
class GlobalRule extends Rule
{
    /**
     * Build a validator to delegate to for mode. Override for particular global values
     * @return ArrayValuesValidator
     */
    protected function buildModeValidator()
    {
        return new ArrayValuesValidator(
            $this->services->get(TranslatorFactory::SERVICE),
            self::VALID_GLOBAL_MODES,
            IWorkflow::MODE,
            IWorkflow::MODE
        );
    }
}
