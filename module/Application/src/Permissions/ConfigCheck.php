<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Checker;
use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Application\Helper\ArrayHelper;
use Application\Config\ConfigException;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;

/**
 * A class to call checkers to handle specific application checks.
 * @package Application\Permissions
 */
class ConfigCheck implements InvokableService
{
    const INVALID_CHECK =
        'The specified check is not valid, should be [Checker::NAME => check] ' .
        'as a minimum configured to link to a checker service';
    protected $services = null;

    /**
     * @inheritdoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Ensures that the provided check passes
     *
     * Configuration will contain
     *
     * Checker::CHECKERS => [
     *     <check name> => class name extending Checker
     * ]
     *
     * defining a specific invokeable service that extends Checker and can be used to check <check name>. If a check
     * name is found as a key the invokeable service is used to perform the check.
     *
     * @param array     $check         Check to perform. Should be an array with a Checker::NAME key/value pair as a
     *                                 minimum. May additionally have a Checker::OPTIONS key with a value of array
     *                                 options to pass to the check
     * @throws ConfigException
     * @return mixed the value from the checker if applicable
     */
    public function check(array $check)
    {
        $checkers = ConfigManager::getValue($this->services->get(ConfigManager::CONFIG), Checker::CHECKERS);
        if (isset($check[Checker::NAME]) && isset($checkers[$check[Checker::NAME]])) {
            return $this->services->get($checkers[$check[Checker::NAME]])
                ->check(
                    $check[Checker::NAME],
                    isset($check[Checker::OPTIONS]) ? $check[Checker::OPTIONS] : null
                );
        } else {
            throw new InvalidArgumentException(self::INVALID_CHECK);
        }
    }

    /**
     * Ensures that all the provided checks pass
     * @param array         $checks     checks to perform
     * @throws ConfigException
     * @see check
     */
    public function checkAll(array $checks)
    {
        foreach ($checks as $check) {
            $this->check($check);
        }
    }

    /**
     * Deprecated and kept in place for now for backwards compatibility. Enforcement should be migrated to using the
     * Checkers pattern. This function will eventually be removed
     * @param mixed $checks
     * @deprecated use the check or checkAll function instead
     * @throws ConfigException
     */
    public function enforce($checks)
    {
        foreach ((array)$checks as $check) {
            try {
                $this->check([Checker::NAME => $check]);
            } catch (InvalidArgumentException $e) {
                $this->services->get(Permissions::PERMISSIONS)->enforce($check);
            }
        }
    }

    /**
     * Determines whether a string matches any of the strings in a blacklist.
     * A direct regex version of each of the blacklist strings will be used for comparison.
     * NOTE: The only exception to this is when the blacklist string only contains characters
     *       in the set of alphanumeric characters plus '_' and '@'. In this case, the
     *       regex will be set to an exact match. If you want an exact match on any special
     *       characters, you will need to escape them.
     *
     * Examples:
     *       Exact :      $excludeList = ['jim']          - matches 'jim' but does not match 'bigjim' or 'bigjimbob'
     *       Contains:    $excludeList = ['.*jim.*']      - matches 'jim' as well as 'bigjim' and 'bigjimbob'
     *       Contains:    $excludeList = ['.?jim.?']      - matches 'jim' as well as 'ajim' and 'ajimb'
     *       Starts with: $excludeList = ['jim.*']        - matches 'jim' and 'jimbob' but not 'bigjim' or 'bigjimbob'
     *       Ends with:   $excludeList = ['.*bob']        - matches 'jimbob' and 'bigjimbob' but not 'jim'
     *       With escape: $excludeList = ['jim\-bob\.x]   - matches 'jim-bob.x', only
     *
     * @param  string      $id                 The string that is to be checked for blacklisting
     * @param  array       $excludeList        A list of terms to compare the $id against
     * @param  bool        $caseSensitive      Whether the regex should be case sensitive
     *
     * @return bool
     */
    public static function isExcluded($id, $excludeList, $caseSensitive = false)
    {
        if (!empty($excludeList) && ArrayHelper::findMatchingStrings($excludeList, [$id], $caseSensitive)) {
            return true;
        }

        return false;
    }
}
