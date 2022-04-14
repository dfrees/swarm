<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Application\InputFilter\DirectInput;
use Application\Validator\BetweenInt;
use Application\Validator\IsBool;

/**
 * Trait FilterTrait. Some common filter definitions
 * @package Application\Filter
 */
trait FilterTrait
{
    /**
     * Validate an integer field. Asserts that the value is between 1 and maxValue inclusive.
     * @param string    $field          field to validate
     * @param int|null  $defaultValue   default value if not supplied
     * @param int       $maxValue       maximum value, default to PHP_INT_MAX
     * @param bool      $required       whether the field is required, default false
     */
    public function addInt(
        string $field,
        int $defaultValue = null,
        int $maxValue = PHP_INT_MAX,
        bool $required = false
    ) {
        $input = new DirectInput($field);
        $input->setRequired($required);
        if ($defaultValue !== null) {
            $input->getFilterChain()->attach(new DefaultValue([DefaultValue::DEFAULT => 100]));
        }
        $input->getValidatorChain()->attach(new BetweenInt(['min' => 1, 'max' => $maxValue, 'inclusive' => true]));
        $this->add($input);
    }

    /**
     * Validate boolean field using FormBoolean
     * @param string    $field      field to validate
     * @param bool      $required   whether the field is required, default false
     */
    public function addBool(string $field, bool $required = false)
    {
        $input = new DirectInput($field);
        $input->setRequired($required);
        $input->getFilterChain()->attach(new FormBoolean());
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }
}
