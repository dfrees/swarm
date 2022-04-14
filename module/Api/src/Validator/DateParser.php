<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Api\Validator;

use DateTime;

/**
 * Class to handle date functions needed when accepting date
 * values through the API.
 */
class DateParser
{
    /**
     * Validates that the date agrees with the specified format.
     * @param $date the date to validate.
     * @param string $format the format to use. Defaults to Perforce server
     * format yyyy-mm-dd (the php equivalent of Y-m-d).
     * @return the valid date or false if it is not valid.
     */
    public static function validateDate($date, $format = 'Y-m-d')
    {
        $dateFromFormat = DateTime::createFromFormat($format, $date);
        return $dateFromFormat && $dateFromFormat->format($format) == $date ? $date : false;
    }
}
