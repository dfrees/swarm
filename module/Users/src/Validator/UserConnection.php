<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Validator;

use Application\Validator\IdenticalCaseSensitive;

/**
 * Messages for comparing a provided user with the user on the connection.
 */
class UserConnection extends IdenticalCaseSensitive
{
    const NOT_SAME_MESSAGE = 'The user provided is not the same as the user on the P4D connection';
    const MISSING_MESSAGE  = 'No user was provided to match against';
    /**
     * Error messages
     * @var array
     */
    protected $messageTemplates = [
        self::NOT_SAME      => self::NOT_SAME_MESSAGE,
        self::MISSING_TOKEN => self::MISSING_MESSAGE,
    ];
}
