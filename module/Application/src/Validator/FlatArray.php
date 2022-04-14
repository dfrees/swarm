<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

/**
 * Validates that the given value is a flat array (value are not arrays or objects).
 */
class FlatArray extends AbstractValidator
{
    const NOT_ARRAY = 'notArray';
    const NOT_FLAT  = 'notFlat';

    const NOT_FLAT_MESSAGE  = "Array values must not be arrays or objects.";
    const NOT_ARRAY_MESSAGE = "Invalid type given. Array required.";

    protected $messageTemplates = [
        self::NOT_ARRAY => self::NOT_ARRAY_MESSAGE,
        self::NOT_FLAT  => self::NOT_FLAT_MESSAGE,
    ];

    /**
     * Returns true if $value is a flat array.
     *
     * @param   mixed   $value  value to check for flat array type.
     * @return  boolean         true if value is a flat array; false otherwise.
     */
    public function isValid($value)
    {
        if (!is_array($value)) {
            $this->error(self::NOT_ARRAY);
            return false;
        }

        if (in_array(true, array_map('is_array', $value)) || in_array(true, array_map('is_object', $value))) {
            $this->error(self::NOT_FLAT);
            return false;
        }

        return true;
    }
}
