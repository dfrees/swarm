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
 * Class ArraySliceFilter.
 * @package Application\Filter
 */
class ArraySliceFilter extends AbstractFilter
{
    private $length;
    private $offset;

    /**
     * ArraySliceFilter constructor.
     * @param int $length number of array elements to restrict to, defaults to -1 (no restriction)
     * @param int $offset offset defaults to 0 (start of the array)
     */
    public function __construct(int $length = -1, int $offset = 0)
    {
        $this->length = $length;
        $this->offset = $offset;
    }

    /**
     * Slice to limit to array size
     * @param mixed $value  value to limit
     * @return array|mixed limited array, or the value unchanged if it is not an array
     */
    public function filter($value)
    {
        $retVal = $value;
        // Leave unchanged if not an array
        if (is_array($value)) {
            if ($this->length > 0) {
                $slicedValues = array_slice($value, $this->offset, $this->length);
            } else {
                $slicedValues = $value;
            }
            $retVal = $slicedValues;
        }
        return $retVal;
    }
}
