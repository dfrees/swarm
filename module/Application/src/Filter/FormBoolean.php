<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Laminas\Filter\AbstractFilter;

/**
 * Class FormBoolean. Filter to translate values to true/false when recognised as equivalent
 * @package Application\Filter
 */
class FormBoolean extends AbstractFilter
{
    const NULL_AS_FALSE  = 'nullAsFalse';
    const FALSE_VALUE    = 'falseValue';
    const TRUE_VALUE     = 'trueValue';
    private $nullAsFalse = true;
    private $falseValue;
    private $trueValue;

    /**
     * FormBoolean constructor. Supports
     *
     * 'nullAsFalse' => true|false
     * 'falseValue'  => default unset or set value from constructor
     * 'trueValue'   => default unset or set value from constructor
     * By default null is treated as false. If 'nullAsFalse' is set to false then null will not be converted
     * @param array|null $options
     */
    public function __construct($options = null)
    {
        if ($options && isset($options[self::NULL_AS_FALSE]) && $options[self::NULL_AS_FALSE] === false) {
            $this->nullAsFalse = false;
        }
        if ($options && isset($options[self::FALSE_VALUE])) {
            $this->falseValue = $options[self::FALSE_VALUE];
        }
        if ($options && isset($options[self::TRUE_VALUE])) {
            $this->trueValue = $options[self::TRUE_VALUE];
        }
    }

    /**
     * Convert the value.
     * '0', 0, false, 'off', '' and, 'false' are considered false (boolean).
     * If options specify then null will also be considered false, this is the default
     * '1', 1, true, 'on' and 'true' are considered true (boolean)
     *
     * In all other cases the value will simply be returned
     *
     * @param mixed $value
     * @return bool|mixed|string|null
     */
    public function filter($value)
    {
        if ($value === '0'
            || $value === false
            || $value === 0
            || $value === 'off'
            || (is_string($value) && empty($value))
            || (is_string($value) && strtolower($value) === 'false')
            || (is_null($value) && $this->nullAsFalse)
        ) {
            return isset($this->falseValue) ? $this->falseValue : false;
        } elseif ($value === '1'
            || $value === true
            || $value === 1
            || $value === 'on'
            || (is_string($value) && strtolower($value) === 'true')) {
            return isset($this->trueValue) ? $this->trueValue : true;
        }
        return $value;
    }
}
