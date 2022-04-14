<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Validator;

use Application\Validator\ConnectedAbstractValidator;
use Users\Model\User;

/**
 * Check if the given list of ids represents existing Perforce users.
 */
class Users extends ConnectedAbstractValidator
{
    const INVALID_TYPE = 'invalidType';
    const UNKNOWN_IDS  = 'unknownUserIds';

    protected $messageTemplates = [
        self::INVALID_TYPE => "User ids must be strings",
        self::UNKNOWN_IDS  => "Unknown user id(s): %ids%"
    ];

    protected $messageVariables = [
        'ids' => 'unknownIds'
    ];

    protected $unknownIds;

    /**
     * Returns true if $value is an id for an existing user or if it contains a list of ids
     * representing existing users in Perforce.
     *
     * @param   string|array    $value  id or list of ids to check
     * @return  boolean         true if value is id or list of ids of existing users, false otherwise
     */
    public function isValid($value)
    {
        $userDAO    = $this->getUserDAO();
        $p4         = $this->getConnection();
        $value      = (array) $value;
        $unknownIds = array_diff($value, $userDAO->existIds($value, $p4));
        if (count($unknownIds)) {
            $this->unknownIds = implode(', ', $unknownIds);
            $this->error(self::UNKNOWN_IDS);
            return false;
        }

        return true;
    }
}
