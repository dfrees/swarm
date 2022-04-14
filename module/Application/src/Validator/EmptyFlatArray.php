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
 * Class EmptyFlatArray
 * @package Application\Validator
 */
class EmptyFlatArray extends FlatArray
{
    const NOT_EMPTY = 'notEmpty';

    protected $messageTemplates = [
        self::NOT_ARRAY => self::NOT_ARRAY_MESSAGE,
        self::NOT_FLAT  => self::NOT_FLAT_MESSAGE,
        self::NOT_EMPTY => "Array must be empty"
    ];

    /**
     * Validates an array is empty
     * @param mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        $valid = parent::isValid($value);
        if ($valid === true) {
            $valid = sizeof($value) === 0;
            if (!$valid) {
                $this->error(self::NOT_EMPTY);
            }
        }
        return $valid;
    }
}
