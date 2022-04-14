<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Filter;

use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Filter\ArrayValues;
use Application\Filter\FormBoolean;
use Application\Filter\StringToId;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Model\IModelDAO;
use Application\Validator\FlatArray as FlatArrayValidator;
use Application\Validator\IsArray as ArrayValidator;
use Groups\Model\Config;
use Groups\Model\Group as GroupModel;
use Groups\Validator\Users;
use Groups\View\Helper\NotificationSettings;
use Interop\Container\ContainerInterface;
use P4\Validate\GroupName as GroupNameValidator;
use Laminas\Validator\EmailAddress;
use P4\Spec\Group as P4Group;
use Application\Config\ConfigException;

/**
 * Filter to validate groups
 * @package Groups\Filter
 */
class Group extends InputFilter implements InvokableService
{
    const RESERVED = ['add', 'edit', 'delete'];

    protected $verifyNameAsId = false;
    private $services         = null;
    private $p4               = null;
    private $translator       = null;
    private $useMailingList   = false;

    /**
     * Group filter constructor.
     * @param ContainerInterface    $services   application services
     * @param array|null            $options    options to use with the filter
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services       = $services;
        $this->p4             = $options[ConnectionFactory::P4] ?? $services->get(ConnectionFactory::P4_ADMIN);
        $this->useMailingList = $options[Config::FIELD_USE_MAILING_LIST] ?? false;
        $this->translator     = $services->get(TranslatorFactory::SERVICE);
        $this->addGroup();
        $this->addName();
        $this->addUsers();
        $this->addSubgroups();
        $this->addOwners();
        $this->addDescription();
        $this->addUseMailingList();
        $this->hiddenMailingList();
        $this->emailAddress();
        $this->emailFlags();
        $this->notificationSettings();
    }

    /**
     * Add validation for notification settings
     */
    private function notificationSettings()
    {
        // ensure notification settings is an array containing keys for the flags we want to set
        $this->add(
            [
                'name'     => Config::GROUP_NOTIFICATION_SETTINGS,
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }
                                // Convert from the flat array to a nested one based on the view helper
                                return NotificationSettings::buildFromFlatArray($value);
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $arrayValidator = new ArrayValidator;
                                return $arrayValidator->isValid($value)
                                    ?: $this->translator->t("Notification settings must be an array.");
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for email flags
     */
    private function emailFlags()
    {
        // ensure emailFlags is an array containing keys for the flags we want to set
        $this->add(
            [
                'name'     => Config::FIELD_EMAIL_FLAGS,
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return [
                                    'reviews' => isset($value['reviews']) ? $value['reviews'] : false,
                                    'commits' => isset($value['commits']) ? $value['commits'] : false,
                                ];
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                return $flatArrayValidator->isValid($value)
                                    ?: $this->translator->t(
                                        "Email flags must be an associative array of scalar values."
                                    );
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for email address
     * @throws ConfigException
     */
    private function emailAddress()
    {
        $options = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::MAIL_VALIDATOR_OPTIONS,
            []
        );
        $this->add(
            [
                'name'     => Config::FIELD_EMAIL_ADDRESS,
                'required' => (
                    $this->useMailingList === "on" || $this->useMailingList === "true" || $this->useMailingList === true
                ),
                'continue_if_empty' => true,
                'validators'  => [
                    // Override the default NotEmpty output with custom message.
                    [
                        'name'                   => 'NotEmpty',
                        // If this validator proves that the value is invalid do not carry on with
                        // any future chained reviews
                        'break_chain_on_failure' => true,
                        'options'                => [
                            'message' => $this->translator->t("Email is required when sending to a mailing list.")
                        ]
                    ],
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) use ($options) {
                                // invalid values need to be returned directly to the validator
                                $validator = new EmailAddress($options);
                                return $validator->isValid($value) ?: implode(". ", $validator->getMessages());
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for hidden mailing list
     */
    private function hiddenMailingList()
    {
        // A hidden field to allow use to keep track of an email address (so we can detect when the field
        // is cleared but they have chosen to not use an email address)
        $this->add(
            [
                'name'     => 'hiddenEmailAddress',
                'required' => false,
            ]
        );
    }

    /**
     * Add validation for use of mailing list
     */
    private function addUseMailingList()
    {
        // ensure use mailing list is true/false
        $this->add(
            [
                'name'       => Config::FIELD_USE_MAILING_LIST,
                'required'   => false,
                'continue_if_empty' => true,
                'filters'    => [['name' => '\Application\Filter\FormBoolean']],
                'validators' => [
                    [
                        'name'     => '\Laminas\Validator\Callback',
                        'options'  => [
                            'callback' => function ($value) {
                                $fb = new FormBoolean();
                                return is_bool($fb->filter($value));
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for description
     */
    private function addDescription()
    {
        $this->add(
            [
                'name'       => Config::FIELD_DESCRIPTION,
                'required'   => false,
                'filters'    => [['name' => 'StringTrim']],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                return is_string($value) ?: $this->translator->t("Description must be a string.");
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for owners
     */
    private function addOwners()
    {
        // add owners field
        $this->add(
            [
                'name'       => GroupModel::FIELD_OWNERS,
                'required'   => false,
                'filters'    => [new ArrayValues],
                'validators' => [new FlatArrayValidator]
            ]
        );
    }

    /**
     * Add validation for subgroups
     */
    private function addSubgroups()
    {
        // add subgroups field
        $this->add(
            [
                'name'       => GroupModel::FIELD_SUBGROUPS,
                'required'   => false,
                'filters'    => [new ArrayValues],
                'validators' => [new FlatArrayValidator]
            ]
        );
    }

    /**
     * Add validation for users
     */
    private function addUsers()
    {
        // add users field
        $usersInput = new DirectInput(P4Group::FIELD_USERS);
        $usersInput->getFilterChain()->attach(new ArrayValues);
        $usersInput->getValidatorChain()
            ->attach(new FlatArrayValidator(), true)
            ->attach(new Users($this->translator));
        $this->add($usersInput);
    }

    /**
     * Add validation for name
     */
    private function addName()
    {
        // if id comes from name, then we need to ensure name produces a usable/unique id
        $this->add(
            [
                'name'       => GroupModel::FIELD_NAME,
                'required'   => false,
                'filters'    => ['trim'],
                'validators' => [
                    [
                        'name'    => 'NotEmpty',
                        'options' => [
                            'message' => $this->translator->t("Name is required and can't be empty.")
                        ]
                    ],
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // nothing to do if name does not inform the id
                                if (!$this->verifyNameAsId()) {
                                    return true;
                                }

                                $id = $this->nameToId($value);
                                if (!$id) {
                                    return $this->translator->t('Name must contain at least one letter or number.');
                                }

                                // check if the group id is valid
                                $validator = new GroupNameValidator;
                                $validator->setAllowMaxValue(GroupModel::MAX_VALUE);
                                if (!$validator->isValid($id)) {
                                    return implode(' ', $validator->getMessages());
                                }

                                // when adding, check if the group already exists
                                if ($this->isAdd() && (in_array($id, self::RESERVED)
                                        || $this->services->get(IModelDAO::GROUP_DAO)->exists($id, $this->p4))) {
                                    return $this->translator->t('This name is taken. Please pick a different name.');
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Add validation for Group
     */
    private function addGroup()
    {
        // validate id for uniqueness on add, unless id comes from name
        // in that case the name field does all the validation for us
        $this->add(
            [
                'name'       => GroupModel::ID_FIELD,
                'required'   => true,
                'filters'    => ['trim'],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // if adding and name does not inform id, check if the group already exists
                                if ($this->isAdd() && !$this->verifyNameAsId()
                                    && (in_array($value, self::RESERVED) ||
                                        $this->services->get(IModelDAO::GROUP_DAO)->exists($value, $this->p4))
                                ) {
                                    return $this->translator->t(
                                        'This Group ID is taken. Please pick a different Group ID.'
                                    );
                                }

                                // check if the group name is valid, check if numeric values are permitted
                                $info               = $this->p4->run('info');
                                $allowPurelyNumeric =
                                    $info->getData(0, GroupNameValidator::ATTR_NUMERIC_USERS) ===
                                    GroupNameValidator::NUMERIC_USERS_ENABLED;
                                $validator          = new GroupNameValidator;
                                $validator->allowPurelyNumeric($allowPurelyNumeric);
                                $validator->setAllowMaxValue(GroupModel::MAX_VALUE);
                                if ($this->isAdd() && !$validator->isValid($value)) {
                                    $messages = $validator->getMessages();
                                    return array_shift($messages);
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Enable/disable behavior where the name must produce a valid id.
     *
     * @param   bool    $verifyNameAsId     optional - pass true to verify the name makes a good id
     * @return  Group|bool   provides fluent interface
     */
    public function verifyNameAsId($verifyNameAsId = null)
    {
        // doubles as an accessor
        if (func_num_args() === 0) {
            return $this->verifyNameAsId;
        }

        $this->verifyNameAsId = (bool) $verifyNameAsId;

        // if id comes from the name, then name is required and id is not
        $this->get(GroupModel::ID_FIELD)->setRequired(!$this->verifyNameAsId);
        $this->get(GroupModel::FIELD_NAME)->setRequired($this->verifyNameAsId);

        return $this;
    }

    /**
     * Generate an id from the given name.
     *
     * @param   string  $name   the name to turn into an id
     * @return  string  the resulting id
     */
    public function nameToId($name)
    {
        $toId = new StringToId;
        return $toId($name);
    }
}
