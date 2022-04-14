<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\InputFilter;

use Application\Validator\Callback as CallbackValidator;
use Laminas\Filter\Word\CamelCaseToSeparator;
use Laminas\InputFilter\InputFilter as LaminasInputFilter;
use Laminas\Validator\Digits;
use Laminas\Validator\ValidatorChain;

/**
 * Extends parent by adding support for:
 *  - add/edit modes
 *  - mark fields as 'notAllowed' forcing the validation to always fail (unless
 *    the value is empty and the field is not required)
 */
class InputFilter extends LaminasInputFilter
{
    const MODE_ADD  = 'add';
    const MODE_EDIT = 'edit';
    const MODE_VIEW = 'view';
    // Option value to set mode at service build time
    const MODE = 'mode';

    const INTEGER_ERROR  = "%s must be an integer greater than 0";
    const SET_MODE_ERROR = 'Invalid mode specified. Must be add, edit or view.';

    protected $mode;

    /**
     * Mark given element as 'not allowed'. Validation of such element will always
     * fail. Given element will also be marked 'not required' to avoid failing if
     * value is not present.
     *
     * @param   string          $element    element to mark as not-allowed
     * @return  InputFilter     provides fluent interface
     */
    public function setNotAllowed($element)
    {
        $input = isset($this->inputs[$element]) ? $this->inputs[$element] : null;
        if (!$input) {
            throw new \InvalidArgumentException(
                "Cannot set '$element' element NotAllowed - element not found."
            );
        }

        // tweak the element to:
        //  - make it not required (also sets allow empty)
        //  - don't allow empty values to overrule the opposite after making it not required
        //  - set our own validator chain containing only one validator always failing
        $validatorChain = new ValidatorChain;
        $validatorChain->attach(
            new CallbackValidator(
                function ($value) {
                    return 'Value is not allowed.';
                }
            )
        );
        $input->setRequired(false)
              ->setAllowEmpty(false)
              ->setValidatorChain($validatorChain);

        return $this;
    }

    /**
     * Set the filter mode (one of add or edit).
     *
     * @param   string          $mode   'add' or 'edit'
     * @return  InputFilter     provides fluent interface
     * @throws  \InvalidArgumentException
     */
    public function setMode($mode)
    {
        if ($mode !== static::MODE_ADD && $mode !== static::MODE_EDIT && $mode !== static::MODE_VIEW) {
            throw new \InvalidArgumentException(self::SET_MODE_ERROR);
        }

        $this->mode = $mode;

        return $this;
    }

    /**
     * Get the current mode (add or edit)
     *
     * @return  string  'add' or 'edit'
     * @throws  \RuntimeException   if mode has not been set
     */
    public function getMode()
    {
        if (!$this->mode) {
            throw new \RuntimeException("Cannot get mode. No mode has been set.");
        }

        return $this->mode;
    }

    /**
     * Return true if in add mode, false otherwise.
     *
     * @return  boolean     true if in add mode, false otherwise
     */
    public function isAdd()
    {
        return $this->getMode() === static::MODE_ADD;
    }

    /**
     * Return true if in edit mode, false otherwise.
     *
     * @return  boolean     true if in edit mode, false otherwise
     */
    public function isEdit()
    {
        return $this->getMode() === static::MODE_EDIT;
    }

    /**
     * Return true if in view mode, false otherwise.
     *
     * @return  boolean     true if in view mode, false otherwise
     */
    public function isView()
    {
        return $this->getMode() === static::MODE_VIEW;
    }

    /**
     * Guard against exceptions in setValidationGroup by filtering out unknown input names.
     *
     * @param  array        $inputs  list of input names to validate
     * @return InputFilter  provides fluent interface
     */
    public function setValidationGroupSafe(array $inputs)
    {
        return $this->setValidationGroup(array_intersect($inputs, array_keys($this->inputs)));
    }

    /**
     * Optional boolean field. Accepted values are 0, 1, '0', '1', true, false, 'true', 'false'
     * @param $field        string  the field name
     * @param $translator   mixed   the translator for messages
     */
    public function addBooleanValidator($field, $translator)
    {
        // For compatibility with older versions of PHP
        $filter = $this;
        $this->add(
            [
                'name'              => $field,
                'required'          => false,
                'continue_if_empty' => true,
                'filters'    => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) use ($translator, $field, $filter) {
                                // If we have a valid boolean use filter_var to convert our
                                // value (for example from 'false' to false). If not valid
                                // leave it unmodified for the validator to display a message
                                $filtered = $value;
                                if ($filter->validateBoolean($field, $value) === true) {
                                    $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                                }
                                return $filtered;
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) use ($translator, $field, $filter) {
                                return $filter->validateBoolean($field, $value, $translator);
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Optional Integer field. Accepts values that are of type integer
     *
     * @param $field        string  the field name
     * @param $translator   mixed   the translator for messages
     * @param $allowNull    boolean whether a null value is permitted, false by default
     */
    public function addIntegerValidator($field, $translator, $allowNull = false)
    {
        $filter = $this;
        $this->add(
            [
                'name'              => $field,
                'required'          => false,
                'continue_if_empty' => false,
                'filters'           => [
                    ['name' => 'StringTrim'],
                    [
                        'name'        => 'Callback',
                        'options'     => [
                            'callback'  => function ($value) use ($field, $filter, $allowNull, $translator) {
                                return $filter->callbackValidateInteger(
                                    $value,
                                    $field,
                                    $filter,
                                    $translator,
                                    $allowNull
                                );
                            }
                        ]
                    ]
                ],
                'validators'   => [
                    [
                        'name'        => '\Application\Validator\Callback',
                        'options'     => [
                            'callback'  => function ($value) use ($translator, $field, $filter, $allowNull) {
                                return $filter->validateIntegerGreaterThanZero($field, $value, $translator, $allowNull);
                            }
                        ]
                    ]
                ],
            ]
        );
    }

    /**
     * To allow the branch Validator call the same validator as the project level does.
     *
     * @param $value   mixed         This is the value we are checking.
     * @param $field   string        This is the field that we are checking from.
     * @param $filter  InputFilter   This is to allow us to call functions from within callback.
     * @param null $translator
     * @param $allowNull    boolean     whether null or '' is accepted
     *
     * @return mixed     return value or translated message
     */
    public function callbackValidateInteger($value, $field, $filter, $translator = null, $allowNull = false)
    {
        return $filter->validateIntegerGreaterThanZero($field, $value, $translator, $allowNull);
    }

    /**
     * Validates that the value is greater than zero
     * @param $field        string      the field name
     * @param $value        mixed       the value
     * @param $translator   mixed       translator for messages (optional)
     * @param $allowNull    boolean     whether null or '' is accepted
     * @return mixed    return value or translated message
     */
    public function validateIntegerGreaterThanZero($field, $value, $translator = null, $allowNull = false)
    {
        $digitValidator = new Digits;
        $valid          = ($value === '' || $value === null && $allowNull) ||
            $digitValidator->isValid($value) && ((int) $value > 0);
        $field          = $this->checkForCamelCase($field);
        $returnValue    = (int) $value !== 0 ? (int) $value : null;
        if ($valid) {
            return $returnValue;
        } else {
            return $translator ?
                $translator->t(self::INTEGER_ERROR, [$field])
                : ($value || $value === "0" || $value === 0 ? $value : null);
        }
    }

    /**
     * Check for CamelCase field and then split the works out. Then down case all characters and upper case the
     * first character only.
     *
     * @param string  $field  This is the field we have been provide from the filter.
     *
     * @return string         That has been convert into the right case if required.
     */
    public function checkForCamelCase($field)
    {
        // First check that the filed has any upper case characters, if it does assume this
        // is camel Case and split them up.
        if ((bool) preg_match('/[A-Z]/', $field)) {
            $camelCaseToSeparator = new CamelCaseToSeparator(' ');
            return ucfirst(strtolower($camelCaseToSeparator->filter($field)));
        }
        return $field;
    }

    /**
     * Validates the boolean field
     * @param $field        string      the field name
     * @param $value        mixed       the value
     * @param $translator   mixed       translator for messages (optional)
     * @return bool|array whether the value is valid
     */
    public function validateBoolean($field, $value, $translator = null)
    {
        $valid = is_bool($value);
        if (!$valid) {
            if (is_scalar($value)) {
                $stringVal = strtolower((string) $value);
                $valid     = in_array($stringVal, ['true', 'false', '1', '0']);
            }
        }
        if ($valid) {
            return true;
        } else {
            return $translator ?
                $translator->t(
                    "%s must be a boolean value or a value that can be converted to boolean.",
                    [$field]
                )
            : $value;
        }
    }
}
