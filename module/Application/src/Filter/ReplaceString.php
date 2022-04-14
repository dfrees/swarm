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
 * Filter that can replace a string value with another working on a single string or array of strings
 */
class ReplaceString extends AbstractFilter
{
    private $search;
    private $with;

    /**
     * Construct the filter
     * @param string $search    search for this in the filter value to replace
     * @param string $with      if 'search' is found in the filter value replace it with this, defaults to ''
     */
    public function __construct(string $search, string $with = "")
    {
        $this->search = $search;
        $this->with   = $with;
    }

    /**
     * Filter to search for a string in 'value' and replace it with another specified string. Value can be a string
     * or array of strings. The matching is performed case sensitively.
     * @param mixed $value  a string or array of strings
     * @return array|mixed|string|string[] string or array of strings with replacements carried out. If there are no
     * replacements the value is returned as is
     */
    public function filter($value)
    {
        $retVal = $value;
        if (is_array($value)) {
            $retVal = [];
            foreach ($value as $arrayValue) {
                if (is_string($arrayValue)) {
                    $retVal[] = $this->replace($arrayValue);
                } else {
                    $retVal[] = $arrayValue;
                }
            }
        } elseif (is_string($value)) {
            $retVal = $this->replace($value);
        }
        return $retVal;
    }

    /**
     * Replace the value only if it is the first occurrence (faster than preg_replace)
     * @param mixed $value    value to replace
     * @return array|string|string[]
     */
    private function replace($value)
    {
        $pos = mb_strpos($value, $this->search);
        if ($pos !== false) {
            return mb_substr($value, 0, $pos) . $this->with . mb_substr($value, $pos + mb_strlen($this->search));
        }
        return $value;
    }
}
