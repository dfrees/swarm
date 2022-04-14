<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Filter;

use Application\I18n\TranslatorFactory;
use Application\InputFilter\InputFilter;
use Workflow\Model\IWorkflow;
use Workflow\Model\Workflow as WorkflowModel;
use P4\Connection\ConnectionInterface as Connection;
use Users\Validator\Users as UsersValidator;
use Groups\Validator\Groups as GroupsValidator;
use Groups\Model\Group;
use Application\Filter\StringToId;

/**
 * Filter to handle workflow
 * @package Api\Filter
 */
class WorkflowV9 extends InputFilter
{
    // Should be a const but older PHP versions do not support const arrays
    public static $validWithReviewRules    = [IWorkflow::NO_CHECKING, IWorkflow::APPROVED, IWorkflow::STRICT];
    public static $validWithoutReviewRules = [IWorkflow::NO_CHECKING, IWorkflow::AUTO_CREATE, IWorkflow::REJECT];
    public static $validEndRuleUpdateRules = [IWorkflow::NO_CHECKING, IWorkflow::NO_REVISION];
    public static $validAutoApproveRules   = [IWorkflow::NEVER, IWorkflow::VOTES];
    public static $validCountedVotesRules  = [IWorkflow::ANYONE, IWorkflow::MEMBERS];
    const VALID_STANDARD_MODES             = [IWorkflow::MODE_INHERIT];
    const VALID_GLOBAL_MODES               = [IWorkflow::MODE_POLICY, IWorkflow::MODE_DEFAULT];

    const INVALID_OPTION_MESSAGE = 'Invalid option. Please select a value from ';

    private $translator;
    private $connection;
    private $isGlobal;

    /**
     * Workflow filter constructor.
     * @param Connection    $p4                 P4 connection to use
     * @param bool          $isGlobal           whether data is for the global workflow
     * @param IWorkflow     $existingWorkflow   A workflow to do a required field comparison against (optional). For
     *                                          example if we are patching a name it is useful to provide the record
     *                                          we are updating that was found by id to see if we are really updating
     *                                          a name or are trying to give an the record the same name as one we
     *                                          already have with a different id
     */
    public function __construct(Connection $p4, bool $isGlobal, IWorkflow $existingWorkflow = null)
    {
        $this->translator = $p4->getService(TranslatorFactory::SERVICE);
        $this->connection = $p4;
        $this->isGlobal   = $isGlobal;

        $this->addNameFilter($existingWorkflow);
        $this->addDescriptionFilter();
        $this->addOnSubmitFilter();
        $this->addEndRulesFilter();
        $this->addAutoApproveFilter();
        $this->addCountedVotesFilter();
        $this->addBooleanValidator(IWorkflow::SHARED, $this->translator);
        $this->addOwnerFilter($existingWorkflow);
        $this->addGroupExclusionsFilter();
        $this->addUserExclusionsFilter();
    }

    /**
     * Filter for checking 'group_exclusions' data
     */
    private function addGroupExclusionsFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::GROUP_EXCLUSIONS,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    $valid = $this->checkRuleValue($value);
                                    if ($valid === true) {
                                        $valid = $this->checkModeValue($value, $this->getValidModes());
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("Group exclusions data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Filter for checking 'user_exclusions' data
     */
    private function addUserExclusionsFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::USER_EXCLUSIONS,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    $valid = $this->checkRuleValue($value);
                                    if ($valid === true) {
                                        $valid = $this->checkModeValue($value, $this->getValidModes());
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("User exclusions data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * This is not ideal. In order for values to be picked up for .po generation strings
     * must be specifically mentioned with a call to a function that returns a string
     * (even though it will actually never be called).
     * We are forced to repeat the values for constants above.
     */
    private static function msgIds()
    {
        WorkflowV9::t('Invalid option. Please select a value from ');
    }

    /**
     * Dummy translation.
     * @param $value
     * @return mixed
     */
    private static function t($value)
    {
        return $value;
    }

    /**
     * Owners are optional but if provided the field should be an array containing
     * valid user and/or group ids (groups ids with the format swarm-group-xxx).
     * @param IWorkflow     $existingWorkflow   A workflow to do a required field comparison against (optional)
     */
    private function addOwnerFilter($existingWorkflow)
    {
        $this->add(
            [
                'name'              => IWorkflow::OWNERS,
                'required'          => true,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'                   => 'NotEmpty',
                        'break_chain_on_failure' => true,
                        'options'                => [
                            'message' => $this->translator->t("Owner is required, you must select a valid owner.")
                        ]
                    ],
                    [
                        'name'                   => '\Application\Validator\Callback',
                        'break_chain_on_failure' => true,
                        'options'                => [
                            'callback' => function ($value) use ($existingWorkflow) {
                                if (!is_null($value)) {
                                    // Verify defaults
                                    if (!is_array($value)) {
                                        return $this->translator->t(
                                            "Owners must be an array but is '%s'.",
                                            [$value]
                                        );
                                    }
                                    $usersValidator  = new UsersValidator(['connection' => $this->connection]);
                                    $groupsValidator = new GroupsValidator(['connection' => $this->connection]);
                                    // Ensure that the users / groups are valid.
                                    foreach ($value as $id) {
                                        if (Group::isGroupName($id)) {
                                            if (!$groupsValidator->isValid(Group::getGroupName($id))) {
                                                return implode(' ', $groupsValidator->getMessages());
                                            }
                                        } elseif (!$usersValidator->isValid($id)) {
                                            return implode(' ', $usersValidator->getMessages());
                                        }
                                    }
                                }
                                if ($value) {
                                    return $value;
                                } elseif ($existingWorkflow) {
                                    return $existingWorkflow->getOwners();
                                } else {
                                    return $this->translator->t(
                                        'A workflow must have at least one owner. The owner can be a user or a group.'
                                    );
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Filter for checking 'counted_votes' data
     */
    private function addCountedVotesFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::COUNTED_VOTES,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    $valid = $this->checkRuleValue(
                                        $value,
                                        WorkflowV9::$validCountedVotesRules
                                    );
                                    if ($valid === true) {
                                        $valid = $this->checkModeValue(
                                            $value,
                                            $this->getValidModes()
                                        );
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("Counted votes data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Gets valid modes
     * @return array
     */
    private function getValidModes()
    {
        return $this->isGlobal ? self::VALID_GLOBAL_MODES : self::VALID_STANDARD_MODES;
    }

    /**
     * Filter for checking 'end_rules' data
     */
    private function addEndRulesFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::END_RULES,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    if (!isset($value[IWorkflow::UPDATE])) {
                                        return $this->translator->t(
                                            "End rules must contain %s", [IWorkflow::UPDATE]
                                        );
                                    }
                                    $valid = $this->checkRule(
                                        $value,
                                        IWorkflow::UPDATE,
                                        WorkflowV9::$validEndRuleUpdateRules
                                    );
                                    if ($valid === true) {
                                        $valid = $this->checkMode(
                                            $value,
                                            IWorkflow::UPDATE,
                                            $this->getValidModes()
                                        );
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("End rules data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Filter for checking 'auto_approve' data
     */
    private function addAutoApproveFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::AUTO_APPROVE,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    $valid = $this->checkRuleValue(
                                        $value,
                                        WorkflowV9::$validAutoApproveRules
                                    );
                                    if ($valid === true) {
                                        $valid = $this->checkModeValue(
                                            $value,
                                            $this->getValidModes()
                                        );
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("Auto approve data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Filter for checking 'on_submit' data
     */
    private function addOnSubmitFilter()
    {
        $this->add(
            [
                'name'              => IWorkflow::ON_SUBMIT,
                'required'          => false,
                'continue_if_empty' => true,
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (is_array($value)) {
                                    if (empty($value)) {
                                        return true;
                                    }
                                    if (!isset($value[IWorkflow::WITHOUT_REVIEW]) &&
                                        !isset($value[IWorkflow::WITH_REVIEW])) {
                                        return $this->translator->t(
                                            "On submit must contain at least one of %s",
                                            [implode(', ', [IWorkflow::WITH_REVIEW, IWorkflow::WITHOUT_REVIEW])]
                                        );
                                    }
                                    $valid = $this->checkRule(
                                        $value,
                                        IWorkflow::WITH_REVIEW,
                                        WorkflowV9::$validWithReviewRules
                                    );
                                    if ($valid === true) {
                                        $valid = $this->checkRule(
                                            $value,
                                            IWorkflow::WITHOUT_REVIEW,
                                            WorkflowV9::$validWithoutReviewRules
                                        );
                                    }
                                    if ($valid === true) {
                                        $valid = $this->checkMode(
                                            $value,
                                            IWorkflow::WITH_REVIEW,
                                            $this->getValidModes()
                                        );
                                    }
                                    if ($valid === true) {
                                        $valid = $this->checkMode(
                                            $value,
                                            IWorkflow::WITHOUT_REVIEW,
                                            $this->getValidModes()
                                        );
                                    }
                                    return $valid;
                                } else {
                                    return $this->translator->t("On submit data must be an array.");
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Utility to check a rule
     * @param mixed         $value      value to check
     * @param string        $subPath    sub path where the rule is located
     * @param array         $valid      valid values
     * @return bool true if valid
     */
    private function checkRule($value, $subPath, $valid)
    {
        if (isset($value[$subPath])) {
            return $this->checkRuleValue($value[$subPath], $valid);
        }
        return true;
    }

    /**
     * Utility to check the value of a workflow rule setting.
     * @param $value
     * @param $valid
     * @return bool
     */
    private function checkRuleValue($value, $valid = null)
    {
        if (!isset($value[IWorkflow::RULE])) {
            return $this->translator->t('Rule must be provided');
        } elseif (sizeof($value) > 2) {
            unset($value[IWorkflow::RULE]);
            unset($value[IWorkflow::MODE]);
            return $this->translator->t("Invalid value(s) [%s]", [implode(',', array_values($value))]);
        } elseif ($valid && !in_array($value[IWorkflow::RULE], $valid)) {
            return $this->translator->t("Invalid option. Please select a value from %s", [implode(', ', $valid)]);
        }
        return true;
    }

    /**
     * Utility to check a mode
     * @param mixed         $value      value to check
     * @param string        $subPath    sub path where the rule is located
     * @param array         $valid      valid values
     * @return bool true if valid
     */
    private function checkMode($value, $subPath, $valid)
    {
        if (isset($value[$subPath])) {
            return $this->checkModeValue($value[$subPath], $valid);
        }
        return true;
    }

    /**
     * Utility to check the value of a workflow mode setting.
     * @param $value
     * @param $valid
     * @return bool
     */
    private function checkModeValue($value, $valid = null)
    {
        if (!isset($value[IWorkflow::MODE])) {
            return $this->translator->t('Mode must be provided');
        } elseif (sizeof($value) > 2) {
            unset($value[IWorkflow::MODE]);
            unset($value[IWorkflow::RULE]);
            return $this->translator->t("Invalid value(s) [%s]", [implode(',', array_values($value))]);
        } elseif ($valid && !in_array($value[IWorkflow::MODE], $valid)) {
            return $this->translator->t("Invalid option. Please select a value from %s", [implode(', ', $valid)]);
        }
        return true;
    }

    /**
     * Description is optional but if provided it must be a string. The string will be
     * trimmed.
     */
    private function addDescriptionFilter()
    {
        // StringTrim is used here as it will not error if description is
        // not provided (using trim will cause an error if not provided)
        $this->add(
            [
                'name'              => IWorkflow::DESCRIPTION,
                'required'          => false,
                'continue_if_empty' => true,
                'filters'           => [['name' => 'StringTrim']],
                'validators'        => [
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
     * Name is required, must contain at least one letter or number and must not be in use
     * already by another workflow.
     * @param IWorkflow     $existingWorkflow   A workflow to do a required field comparison against (optional)
     */
    private function addNameFilter($existingWorkflow)
    {
        $this->add(
            [
                'name'          => IWorkflow::NAME,
                'filters'       => ['trim'],
                'validators'    => [
                    [
                        'name'      => 'NotEmpty',
                        'options'   => [
                            'message' => $this->translator->t("Name is required and cannot be empty.")
                        ]
                    ],
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback' => function ($value) use ($existingWorkflow) {
                                $stringToId = new StringToId;
                                $id         = $stringToId($value);
                                if (!$id) {
                                    return $this->translator->t('Name must contain at least one letter or number.');
                                }
                                $matchingWorkflows = WorkflowModel::fetchAll(
                                    [
                                        WorkflowModel::FETCH_BY_KEYWORDS     => $value,
                                        WorkflowModel::SPLIT_KEYWORDS        => false,
                                        WorkflowModel::FETCH_KEYWORDS_FIELDS => [IWorkflow::NAME]
                                    ],
                                    $this->connection
                                );
                                $count             = $matchingWorkflows->count();
                                // If there is no workflow found with the same name this is valid
                                // If there is 1 workflow with the same name but its id is the same as the existing
                                // workflow provided for comparison then this is valid (for example the name is being
                                // patched so we expect to find a record)
                                $valid = $count === 0
                                    || ($count === 1
                                        && $existingWorkflow
                                        && $existingWorkflow->getId() === $matchingWorkflows->first()->getId());
                                return $valid
                                    ? true
                                    : $this->translator->t('A workflow with this name exists already.');
                            }
                        ]
                    ]
                ]
            ]
        );
    }
}
