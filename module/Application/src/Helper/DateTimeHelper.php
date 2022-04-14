<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Helper;

use Application\Config\ConfigManager;
use DateTime;
use Exception;
use DateInterval;
use InvalidArgumentException;

/**
 * Class DateHelper, utility functions to help processing of dates
 * @package Application\Helper
 */
class DateTimeHelper
{

    /**
     * Calculates the number of seconds either from $from date or now based on a time given in 24HR format. If the time
     * of $from date or now is already after/equal to the time then the value in seconds until that time the next day is
     * returned.
     * For example:
     *      1.  time() or $from date is 01/01/2019 12:00
     *          $time is 13:00
     *          Result is 3600 (3600 seconds between 12:00 and 13:00)
     *
     *      2.  time() or $from date is 01/01/2019 12:00
     *          $time is 12:00
     *          Result is 86400 (86400 to 12:00 the next day as the times are equal, 86400 is 24 hours)
     *
     *      3.  time() or $from date is 01/01/2019 13:00
     *          $time is 12:00
     *          Result is 82800 (82800 to 12:00 the next day as the times is past 12:00, 82800 is 23 hours)
     *
     * @param string        $time   validated to be in 24 hour format with colon separator, for example 23:30
     * @param DateTime|null $from   a from date to use, default to now if not provided
     * @return int number of seconds calculate
     * @throws Exception
     * @throws InvalidArgumentException if time is not in the correct format
     */
    public static function secondsUntilTime(string $time, DateTime $from = null) : int
    {
        ConfigManager::validate24HourTime($time);
        $dt   = new DateTime($time);
        $dtT  = $dt->getTimestamp();
        $now  = $from ? $from : new DateTime();
        $nowT = $now->getTimestamp();

        if ($dtT <= $nowT) {
            // + 1 day
            $dt->add(new DateInterval('P1D'));
        }
        return $dt->getTimestamp() - $nowT;
    }

    /**
     * Convenience function to return the an interval number of seconds. If $timeOrSeconds is a positive or zero integer
     * value it is simply returned otherwise it is assumed to be a 24HR string time and the number of seconds until that
     * time is returned (according to the rules described in DateHelper::secondsUntilTime)
     * @param mixed         $timeOrSeconds  time in 24HR format or a number of seconds
     * @param DateTime|null $from           a from date to use, default to now if not provided
     * @return int
     * @throws Exception
     * @see DateTimeHelper::secondsUntilTime()
     */
    public static function getIntervalInSeconds($timeOrSeconds, DateTime $from = null) : int
    {
        if (ctype_digit(strval($timeOrSeconds))) {
            return intval($timeOrSeconds);
        } else {
            return self::secondsUntilTime($timeOrSeconds, $from);
        }
    }

    /**
     * Gets the time in seconds, to microseconds accuracy as a float.
     * @return float time in seconds, as a float.
     */
    public static function getTime()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * Gets the timestamp for today in seconds.
     * @return float timestamp in seconds.
     */
    public static function today()
    {
        $now = time();
        return $now-($now%86400);
    }
}
