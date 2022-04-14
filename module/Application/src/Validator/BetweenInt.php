<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Laminas\Validator\Between;

/**
 * Class BetweenInt
 * @package Application\Validator
 */
class BetweenInt extends Between
{
    protected $messageTemplates = [
        self::NOT_BETWEEN        => "The input is not an integer between '%min%' and '%max%', inclusively",
        self::NOT_BETWEEN_STRICT => "The input is not an integer strictly between '%min%' and '%max%'",
        self::VALUE_NOT_NUMERIC  => "The input is not an integer",
    ];

    /**
     * @inheritDoc
     * Additionally, validates that $value is an integer or string representation of an integer
     */
    public function isValid($value)
    {
        $valid = parent::isValid($value);
        // Only allow integers and string representations of integers.
        // We already know that the $value is numeric, since the parent validates that.
        // Then we know that '$value + 0' will evaluate to either an int or a float or possibly some other numeric type.
        // So, finally, we just need to ensure that '$value + 0' evaluates to an int.
        if ($valid && !is_int($value + 0)) {
            $valid = false;
            $this->error($this->getInclusive() ? self::NOT_BETWEEN : self::NOT_BETWEEN_STRICT);
        }
        return $valid;
    }
}
