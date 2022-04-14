<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Validator;

use P4\Spec\Group;
use Laminas\Validator\ValidatorInterface;

/**
 * Custom 'Users' validator or use with Group/Project validation
 * @package Groups\Filter
 */
class Users implements ValidatorInterface
{
    private $translator;

    /**
     * UsersValidator constructor.
     * @param mixed $translator     translator for messages
     */
    public function __construct($translator)
    {
        $this->translator = $translator;
    }

    /**
     * Tests the validity of the field. Users are valid if they are supplied or if they are not
     * supplied and at we have either Owners or Subgroups instead.
     * @param mixed         $value      value of Users
     * @param array|null    $context    other fields
     * @return bool
     */
    public function isValid($value, $context = null)
    {
        $context += [Group::FIELD_OWNERS => [], Group::FIELD_USERS => [], Group::FIELD_SUBGROUPS => []];
        return $context[Group::FIELD_OWNERS] || $context[Group::FIELD_USERS] || $context[Group::FIELD_SUBGROUPS];
    }

    /**
     * Get messages on failure of validation.
     * @return array
     */
    public function getMessages()
    {
        return [Users::class => $this->translator->t('Group must have at least one owner, user or subgroup.')];
    }
}
