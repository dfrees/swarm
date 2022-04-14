<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Helper;

/**
 * Class BooleanHelper, helps with some common boolean functions.
 * @package Application\Helper
 */

class BooleanHelper
{
    /**
     * Use this method when you wish to verify that a value should be taken to represent the boolean true
     * Specifically, the following values will be interpreted as true:
     * - boolean true
     * - string  'true' (case-insensitive)
     * - string  '1'
     * - integer 1
     * Thus we distinguish between a value obviously meant to be take as true and a value that just happens to be truthy
     *
     * @param $value
     * @return bool
     */
    public static function isTrue($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1']);
        }

        if (is_numeric($value)) {
            return $value == 1;
        }

        return false;
    }

    /**
     * Use this method when you wish to verify that a value should be taken to represent the boolean false
     * Specifically, the following values will be interpreted as false:
     * - boolean false
     * - string  'false' (case-insensitive)
     * - string  '0'
     * - integer 0
     * Thus we distinguish between a value obviously meant to be take as false and a value that just happens to be falsy
     *
     * @param $value
     * @return bool
     */
    public static function isFalse($value)
    {
        if (is_bool($value)) {
            return !$value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['false', '0']);
        }

        if (is_numeric($value)) {
            return $value == 0;
        }

        return false;
    }
}
