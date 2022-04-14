<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Laminas\Validator\GreaterThan;

/**
 * Class GreaterThanInt
 * @package Application\Validator
 */
class GreaterThanInt extends GreaterThan
{
    // Allow a blank value to be provided
    protected $nullable         = false;
    protected $messageTemplates = [
        self::NOT_GREATER => "The input is not an integer greater than '%min%'",
        self::NOT_GREATER_INCLUSIVE => "The input is not an integer greater than or equal to '%min%'"
    ];

    public function __construct($options = null)
    {
        $this->nullable = isset($options['nullable']) ? $options['nullable'] : false;
        parent::__construct($options);
    }
    /**
     * @inheritDoc
     * Additionally, validates that $value is an integer or string representation of an integer
     */
    public function isValid($value)
    {
        if ($this->nullable && ($value==="" || $value === null)) {
            return true;
        }
        $valid = parent::isValid($value);
        // Only allow integers and string representations of integers
        // We first need to test that the $value is numeric, since the parent doesn't validate that.
        // Then we know that '$value + 0' will evaluate to either an int or a float or possibly some other numeric type.
        // So, finally, we just need to ensure that '$value + 0' evaluates to an int.
        if ($valid && !(is_numeric($value) && is_int($value + 0))) {
            $valid = false;
            $this->error($this->getInclusive() ? self::NOT_GREATER_INCLUSIVE : self::NOT_GREATER);
        }
        return $valid;
    }
}
