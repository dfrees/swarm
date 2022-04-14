<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Laminas\Validator\AbstractValidator;

/**
 * Class ArrayValuesValidator. Validate a value against a known list of valid values
 * @package Application\Validator
 */
class ArrayValuesValidator extends AbstractValidator
{
    const CASE_SENSITIVE = 'case_sensitive';
    const SUPPORT_ARRAYS = 'support_arrays';
    protected $translator;
    protected $validValues;
    protected $field;
    protected $errorKey;
    protected $options;

    /**
     * ArrayValuesValidator constructor.
     * @param mixed     $translator     to translate messages
     * @param array     $validValues    values that are valid
     * @param string    $errorKey       key for any error message
     * @param string    $field          the field being validated, will be referenced on the error message
     * @param array     $options        options for the validation. Supports 'case_sensitive' = true|false to be
     *                                  used for string comparisons. Default is a case insensitive match
     */
    public function __construct($translator, array $validValues, string $errorKey, string $field, array $options = [])
    {
        parent::__construct();
        $this->translator  = $translator;
        $this->validValues = $validValues;
        $this->field       = $field;
        $this->errorKey    = $errorKey;
        $options          += [
            self::CASE_SENSITIVE => false,
            self::SUPPORT_ARRAYS => false
        ];
        $this->options     = $options;
    }

    /**
     * Validate the value against valid values. If invalid error messages will be set up in the form:
     *
     * ['messages'][<errorKey>] = 'Invalid [<field>], must be one of [<validValues>]'
     * If the value is a string the comparison will be case insensitive
     *
     * @param mixed $value  value to validate
     * @return bool true if valid
     */
    public function isValid($value)
    {
        $valid     = true;
        $badValues = [];
        if (is_string($value)) {
            $valid = $this->isInArray($value);
        } elseif (is_array($value)) {
            // If we are supporting Arrays then we should validate each value.
            if ($this->options[self::SUPPORT_ARRAYS]) {
                foreach ((array)$value as $val) {
                    if (!$this->isInArray($val)) {
                        $badValues[] = $val;
                        $valid       = false;
                    }
                }
            } else {
                // If we are not supporting array just set bad value to 'array'
                $valid       = false;
                $badValues[] = 'arrays not supported';
            }
        } elseif (!in_array($value, $this->validValues, true)) {
            $valid = false;
        }
        if ($valid === false) {
            $this->abstractOptions['messages'][$this->errorKey] =
                $this->translator->t(
                    "Invalid %s [%s], must be one of [%s]",
                    [
                        $this->field,
                        empty($badValues) ? $value : implode(', ', $badValues),
                        implode(', ', $this->validValues)
                    ]
                );
        }
        return $valid;
    }

    /**
     * Is value is valid Values that is apart of the constructor.
     * @param string $value The value we want to check exist in valid values.
     *
     * @return bool Return true if is in valid values.
     */
    private function isInArray($value)
    {
        // Case insensitive comparison for string
        return $this->options[self::CASE_SENSITIVE] === true
            ? in_array($value, $this->validValues)
            : in_array(strtolower($value), array_map('strtolower', $this->validValues));
    }
}
