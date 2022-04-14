<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\InputFilter;

use Laminas\InputFilter\Input;

/**
 * Custom Input for Swarm
 * @package Application\InputFilter
 */
class DirectInput extends Input
{
    /**
     * Overrides validation to pass the responsibility directly to the
     * validator chain.
     * @param array|null $context   other fields
     * @return bool true if valid, otherwise false
     */
    public function isValid($context = null)
    {
        if (is_array($this->errorMessage)) {
            $this->errorMessage = null;
        }
        $value     = $this->getValue();
        $validator = $this->getValidatorChain();
        $result    = $validator->isValid($value, $context);
        if (!$result && $this->hasFallback()) {
            $this->setValue($this->getFallbackValue());
            $result = true;
        }
        return $result;
    }
}
