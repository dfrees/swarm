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
 * Helper functions for dealing with strings
 * @package Application\Helper
 */
class StringHelper
{
    /**
     * Make sure that a string is bookended with double quotes
     * @param  string      $str         The string to quote
     * @return string the quoted string or the original string if already quoted
     */
    public static function quoteString(string $str) : string
    {
        return ($str[0] != '"' || substr($str, -1) != '"') ? $str = '"' . $str . '"' : $str;
    }

    /**
     * Quote all the strings in the array and return the strings
     * @param array         $array          array of strings
     * @param string|null   $key            optional key to use within the array
     * @param bool|null     $caseSensitive  if true the string will be quoted with its case untouched. If false it will
     *                                      be converted to lower case for easy case-insensitive comparison
     * @return array
     */
    public static function quoteStrings(array $array, string $key = null, bool $caseSensitive = true) : array
    {
        return array_map(
            function ($string) use ($caseSensitive) {
                $value = StringHelper::quoteString($string);
                return $caseSensitive ? $value : mb_strtolower($value);
            },
            $key ? $array[$key] : $array
        );
    }

    /**
     * Base64 a string and make it file/url safe (RFC 4648)
     * '=' is padding for base64 encoding to ensure multiples of 4 bytes so we can replace '=' with ''
     * to remove the padding. PHP 7+ automatically handles padding to the correct size in base64_decode
     * @param string    $string     string to encode
     * @return string|string[]
     */
    public static function base64EncodeUrl($string)
    {
        // '=' is padding for base64 encoding to ensure multiples of 4 bytes so we can replace '=' with an empty string
        // to remove the padded
        // PHP 7+ automatically handles
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
    }

    /**
     * Decode a string that has been encoded with base64EncodeUrl($string)
     * PHP 7+ automatically handles padding to the correct size in base64_decode so we do not need to check
     * size and pad
     * @param string    $string     string to decode
     * @return false|string
     */
    public static function base64DecodeUrl($string)
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $string));
    }
}
