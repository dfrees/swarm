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
 * Class IsString. Simple validation to ensure a value is a string. Use StringLength or Regex etc for more complex
 * validation.
 * @package Application\Validator
 */
class IsString extends AbstractValidator
{
    const INVALID = 'invalid';

    protected $messageTemplates = [
        self::INVALID => "Invalid type given. String required.",
    ];

    /**
     * Returns true if $value is an string (including an empty string).
     *
     * @param   mixed   $value  value to check for string type.
     * @return  boolean         true if type is an string; false otherwise.
     */
    public function isValid($value)
    {
        if (!is_string($value)) {
            $this->error(self::INVALID);
            return false;
        }
        return true;
    }
}
