<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application;

/**
 * Defines values for commonly keys used as options.
 * @package Application\Config
 */
class Option
{
    // Used for the p4 admin connection
    const P4_ADMIN = 'p4Admin';
    // Used for application configuration
    const CONFIG = 'config';
    // Used for services calls
    const SERVICES = 'services';
    // Used for the current user id
    const USER_ID = 'userId';
    // Used as an indicator for permissions
    const IS_SUPER = 'isSuper';
    const IS_ADMIN = 'isAdmin';

    /**
     * Validates the options against defaults if provided. Defaults are combined with options with
     * an exception being thrown if there is a key/value in options not provided in defaults.
     * @param array $options
     * @param array $defaults
     * @return array
     */
    public static function validate(array &$options, $defaults = [])
    {
        $defaults = (array)$defaults;
        // throw if user passed option(s) we don't support
        $unsupported = array_diff(array_keys($options), array_keys($defaults));
        if (count($unsupported)) {
            throw new \InvalidArgumentException(
                'Following option(s) are not valid: ' . implode(', ', $unsupported) . '.'
            );
        }
        return $options += $defaults;
    }
}
