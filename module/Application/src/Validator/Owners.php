<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Validator;

use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Groups\Model\Group;
use Groups\Validator\Groups;
use Laminas\Validator\AbstractValidator;
use Interop\Container\ContainerInterface;
use Users\Validator\Users;

/**
 * Class Owners. Validates owners
 * @package Application\Validator
 */
class Owners extends AbstractValidator implements InvokableService
{
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        parent::__construct($options);
    }

    /**
     * Tests for validity of owners that can either be user names or group names in the form swarm-group-xxx
     * @param mixed $value  array of users/groups. Delegates to specific user and group validators to aggregate
     * messages.
     * @return bool
     */
    public function isValid($value)
    {
        $p4Admin         = $this->services->get(ConnectionFactory::P4_ADMIN);
        $usersValidator  = new Users(['connection' => $p4Admin]);
        $groupsValidator = new Groups(['connection' => $p4Admin]);
        $groups          = [];
        $users           = [];

        foreach ($value as $id) {
            if (Group::isGroupName($id)) {
                $groups[] = Group::getGroupName($id);
            } else {
                $users[] = $id;
            }
        }
        $usersValid = $usersValidator->isValid($users);
        if ($users && !$usersValid) {
            $this->abstractOptions['messages']['users']
                = implode(' ', $usersValidator->getMessages());
        }
        $groupsValid = $groupsValidator->isValid($groups);
        if ($groups && !$groupsValid) {
            $this->abstractOptions['messages']['groups']
                = implode(' ', $groupsValidator->getMessages());
        }

        return $groupsValid && $usersValid;
    }
}
