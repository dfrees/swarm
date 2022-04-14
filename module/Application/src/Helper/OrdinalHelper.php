<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Helper;

use InvalidArgumentException;

/**
 * Class OrdinalHelper, helps with some common ordinal functions.
 *
 * NOTE: This class is currently designed to handle english only. If we want to do a true internationalization we would
 * need to add the ext-intl package to composer and then do something like:
 *
 * public static function strOrdinal($int, $locale = 'en_US')
 * {
 *     $nf = new NumberFormatter($locale, NumberFormatter::ORDINAL);
 *     return $nf->format($int);
 * }
 *
 * @package Application\Helper
 */

class OrdinalHelper
{
    const SUFFIXES = ['th','st','nd','rd','th','th','th','th','th','th'];

    /**
     * Use this method when you wish to convert an integer or integer string into an ordinal string:
     *
     * @param integer $value
     * @param bool  $strict     option to enforce strict integer typing
     *
     * @return string
     *
     * throws InvalidArgumentException
     */
    public static function strOrdinal($value, $strict = false)
    {
        $int = self::validateNonNegativeInt($value, $strict);

        if ($int === false) {
            throw new InvalidArgumentException("The value parameter must be a non-negative integer but got [{$value}]");
        }

        $suffix = in_array($int % 100, [11, 12, 13]) ? self::SUFFIXES[0] : self::SUFFIXES[$int % 10];

        return number_format($int) . $suffix;
    }

    /**
     * Validates that a value can be interpreted as a non-negative integer (I.e. an ordinal value)
     *
     * @param mixed $value
     * @param bool  $strict     option to enforce strict integer typing
     *
     * @return integer|false
     */
    public static function validateNonNegativeInt($value, $strict = false)
    {
        if ($strict && !is_int($value)) {
            return false;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        if (false === $filtered || $filtered < 0) {
            return false;
        }

        return $filtered;
    }

    /**
     * Validates that a value can be interpreted as a positive integer
     *
     * @param mixed $value
     * @param bool  $strict     option to enforce strict integer typing
     *
     * @return integer|false
     */
    public static function validatePositiveInt($value, $strict = false)
    {
        $filtered = self::validateNonNegativeInt($value, $strict);

        return $filtered !== 0 ? $filtered : false;
    }
}
