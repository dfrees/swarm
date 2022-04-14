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
 * Class IsBool. Simple validation to ensure a value is a bool.
 * @package Application\Validator
 */
class IsBool extends AbstractValidator
{
    const INVALID = 'invalid';

    protected $messageTemplates = [
        self::INVALID => "Invalid type given. bool required.",
    ];

    /**
     * Returns true if $value is an bool (only bool type, not equivalents).
     *
     * @param   mixed   $value  value to check for bool type.
     * @return  boolean         true if type is an bool false otherwise.
     */
    public function isValid($value)
    {
        if (!is_bool($value)) {
            $this->error(self::INVALID);
            return false;
        }
        return true;
    }
}
