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
 * Class ArrayOfStringFilter.
 * @package Application\Filter
 */
class ArrayOfStringFilter extends AbstractFilter
{
    private $messageLength;

    /**
     * ArrayOfStringFilter constructor.
     * @param int $stringLength individual entry string length limit, defaults to -1 (no restriction)
     */
    public function __construct(int $stringLength = -1)
    {
        $this->messageLength = $stringLength;
    }

    /**
     * Filter the messages to limit  string length
     * @param mixed $arrayValue  value to limit
     * @return array|mixed limited array, or the value unchanged if it is not an array
     */
    public function filter($arrayValue)
    {
        // Leave unchanged if not an array
        if (is_array($arrayValue)) {
            if ($this->messageLength > 0) {
                $index = 0;
                foreach ($arrayValue as $value) {
                    if (is_string($value)) {
                        $arrayValue[$index] = substr($value, 0, $this->messageLength);
                    }
                    $index++;
                }
            }
        }
        $retVal = $arrayValue;
        return $retVal;
    }
}
