<?php
/**
 * Validates string for suitability as a Perforce user name.
 * Extends key-name validator to provide a place to customize
 * validation.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\Validate;

class UserName extends KeyName
{
    // Defines the numeric users ztag output attribute name
    const ATTR_NUMERIC_USERS = 'numericUsers';
    // Defines the numeric users ztag output value when set
    const NUMERIC_USERS_ENABLED   = 'enabled';
    protected $allowSlashes       = true; // SLASH
    protected $allowRelative      = true; // REL
    protected $allowPurelyNumeric = true; // AllowNumeric
}
