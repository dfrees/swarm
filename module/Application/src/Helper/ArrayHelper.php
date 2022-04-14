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
 * Class ArrayHelper, helps with some common array functions.
 * @package Application\Helper
 */
class ArrayHelper
{
    /**
     * This is primarily to help with arrays when we have integers values (or values
     * that look like integers) as key values pointing to some other structure. PHPs
     * array_merge does not cope with these very well.
     * Looks through the target value to find the source key and if found replaces that
     * key with the provided source value. If the source key is not found it will be
     * added to the target.
     * @param array $target         the target array
     * @param mixed $sourceKey      the source key
     * @param mixed $sourceValue    the source value
     * @return array the merged array.
     */
    public static function mergeSingle(array $target, $sourceKey, $sourceValue)
    {
        if ($sourceKey) {
            $stringKey = (string)$sourceKey;
            array_map(
                function ($targetKey, $value) use ($stringKey, $sourceValue, &$target) {
                    if ($targetKey === $stringKey) {
                        $target[$targetKey] = $sourceValue;
                    }
                },
                array_map('strval', array_keys($target)),
                $target
            );
            if (!isset($target[$stringKey])) {
                $target = $target + [$sourceKey => $sourceValue];
            }
        }
        return $target;
    }

    /**
     * @see ArrayHelper::mergeSingle()
     * Iterates every key in the source to replace/add that key into the target.
     * @param array $target the target array
     * @param array $source the target array
     * @return array the merged array.
     */
    public static function merge(array $target, array $source)
    {
        array_map(
            function ($sourceKey, $sourceValue) use (&$target) {
                $target = ArrayHelper::mergeSingle($target, $sourceKey, $sourceValue);
            },
            array_map('strval', array_keys($source)),
            $source
        );
        return $target;
    }

    /**
     * Finds the position of a key in the array
     * @param array     $source     source array
     * @param string    $key        key to find
     * @return bool|int position if found, otherwise false
     */
    public static function getKeyIndex(array $source, $key)
    {
        $position = 0;
        foreach ($source as $elementKey => $elementValue) {
            if ($elementKey === $key) {
                return $position;
            }
            $position++;
        }
        return false;
    }

    /**
     * Matches an array of strings against an array of patterns.
     *
     * @param array $patterns      The patterns we want to match with.
     * @param array $strings       The strings we want to check if patterns match.
     * @param bool  $caseSensitive Do we want the patterns to be case sensitive.
     * @return array  The list matches
     */
    public static function findMatchingStrings(array $patterns, array $strings, $caseSensitive = false)
    {
        $matches   = [];
        $checkCase = $caseSensitive ? '' : 'i';
        foreach ($strings as $line) {
            foreach ($patterns as $pattern) {
                if (!empty($pattern) && preg_match("/^".$pattern."$/$checkCase", $line, $results)) {
                    $matches = array_merge(array_values($matches), array_values($results));
                    break 1;
                }
            }
        }
        return array_unique($matches);
    }

    /**
     * Checks to see if an array has non-integer keys.
     *     [1, true, 'a' [1, 2, 'jim' => 'bob'], '9'] has no non-default keys  - returns false
     *     ['5' => 1, '0' => 2] has int keys but is out of sequence            - returns false
     *     [false => 7] has a non-integer key of false but PHP
     *         treats true and false array keys as 1 and 0, respectively       - returns false
     *     ['jim' => 'bob'] has a non-integer key of 'jim'                     - returns true
     *
     * @param array $array
     *
     * @return bool
     */
    public static function isAssociative(array $array)
    {
        if ($array === []) {
            return false;
        }

        $keys = array_keys($array);
        return $keys !== array_filter($keys, 'is_int');
    }

    /**
     * Lower case using mb_strtolower or strtolower if not available
     * @param mixed     $value      string or array of strings to convert
     * @return array converted string or array
     */
    public static function lowerCase($value)
    {
        $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        if (is_array($value)) {
            return array_map($lower, $value);
        } else {
            return $lower($value);
        }
    }

    /**
     * Searches an array to see if a value exists as a key in the array taking case sensitivity into account.
     * @param string    $toFind             value to search for
     * @param array     $array              array to search
     * @param bool      $caseSensitive      case sensitivity
     * @return bool true if found as a key
     */
    public static function keyExists(string $toFind, array $array, $caseSensitive = true)
    {
        return self::exists($toFind, $array, true, $caseSensitive);
    }

    /**
     * Searches an array to see if a value exists as a value in the array taking case sensitivity into account.
     * @param string    $toFind             value to search for
     * @param array     $array              array to search
     * @param bool      $caseSensitive      case sensitivity
     * @return bool true if found as a value
     */
    public static function valueExists(string $toFind, array $array, $caseSensitive = true)
    {
        return self::exists($toFind, $array, false, $caseSensitive);
    }

    /**
     * Searches an array to see if a value exists as a value or key in the array taking case sensitivity into account.
     * @param string    $toFind             value to search for
     * @param array     $array              array to search
     * @param false     $isKey              true to look in array keys, default to false for values
     * @param bool      $caseSensitive      case sensitivity
     * @return bool true if found
     */
    private static function exists(string $toFind, array $array, $isKey = false, $caseSensitive = true)
    {
        if ($caseSensitive) {
            return $isKey ? array_key_exists($toFind, $array) : in_array($toFind, $array);
        } else {
            $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
            foreach ($array as $key => $value) {
                $compareTo = $isKey ? $key : $value;
                if ($lower($compareTo) == $lower($toFind)) {
                    return true;
                }
            }
        }
        return false;
    }
}
