<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Validator;

use Projects\Model\Project as ProjectModel;
use Laminas\Validator\ValidatorInterface;

class Members implements ValidatorInterface
{
    private $translator;

    /**
     * MembersValidator constructor.
     * @param mixed $translator     translator for messages
     */
    public function __construct($translator)
    {
        $this->translator = $translator;
    }

    /**
     * Tests the validity of the field. Members are valid if they are supplied or if they are not
     * supplied and we have Subgroups instead.
     * @param mixed         $value      value of members
     * @param array|null    $context    other fields
     * @return bool
     */
    public function isValid($value, $context = null)
    {
        return $value || $context[ProjectModel::FIELD_SUBGROUPS];
    }

    /**
     * Get messages on failure of validation.
     * @return array
     */
    public function getMessages()
    {
        return [Members::class => $this->translator->t('Project must have at least one member or subgroup.')];
    }
}
