<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Validator;

use Application\Validator\ConnectedAbstractValidator;
use Users\Validator\Users as UserValidator;
use Groups\Validator\Groups as GroupValidator;
use Groups\Model\Group;
use Reviews\UpdateService;
use Reviews\Model\Review as ReviewModel;

/**
 * Validator for the psuedo secret fields 'reviewers' and 'requiredReviewers' that handles
 * users and groups. Uses the existing Users and Groups validators.
 * @package Reviews\Validator
 */
class Reviewers extends ConnectedAbstractValidator
{
    private $connectionOption;
    private $projects;
    private $reviewAuthor;
    private $retain_reviewer_error;
    private $retain_reviewer;
    private $isNewV10API;
    private $validateIds;
    public $translator;

    public function __construct($p4, $reviewProjects, $reviewAuthor = null, $isNewV10API = false, $validateIds = true)
    {
        $this->connectionOption      = ['connection' => $p4];
        $this->projects              = $reviewProjects;
        $this->reviewAuthor          = $reviewAuthor;
        $this->translator            = $p4->getService('translator');
        $this->retain_reviewer_error = false;
        $this->retain_reviewer       = [];
        $this->isNewV10API           = $isNewV10API;
        $this->validateIds           = $validateIds;
        parent::__construct();
    }

    /**
     * Returns true if $value is an id for an existing user/group or if it contains a list of ids
     * representing existing users/groups in Perforce.
     *
     * @param   string|array    $value  id or list of ids to check
     * @return  boolean         true if value is id or list of ids of existing users/groups, false otherwise
     * @throws \Record\Exception\NotFoundException
     */
    public function isValid($value)
    {
        $retentionValid                    = true;
        $groupsValid                       = true;
        $usersValid                        = true;
        $quorumValid                       = true;
        $value                             = (array) $value;
        $ids                               = [];
        $this->abstractOptions['messages'] = [];

        // combined reviewers with the possibility of a quorum value being set for groups. Individuals will
        // have been in a requiredReviewers pseudo field implying true, we have built the combined field so
        // only need to validate that against retention policy
        foreach ($value as $key => $item) {
            $ids[] = $key;
            if (isset($item['required']) && !is_bool($item['required'])) {
                if (!is_numeric($item['required']) || (int) $item['required'] !== 1) {
                    array_push($this->abstractOptions['messages'], $this->translator->t("Quorum value must be 1"));

                    $quorumValid = false;
                }
            }
        }

        $users = array_filter(
            array_map(
                function ($id) {
                    return Group::isGroupName($id) === false ? $id : null;
                },
                $ids
            )
        );
        $groups = array_map(
            function ($id) {
                return Group::getGroupName($id);
            },
            array_diff($ids, $users)
        );

        if (count($groups)) {
            $groupsValid = !$this->validateIds
                || $this->doValidate(new GroupValidator($this->connectionOption), $groups);
        }

        if (count($users)) {
            $usersValid = !$this->validateIds || $this->doValidate(new UserValidator($this->connectionOption), $users);
        }

        // This check is added for new v10 api participant crud, where we don't want invalid
        // groups and user message should be clubbed with retain reviewer message. If validation
        // for group or user is failed then it should return back from here.
        if ($this->isNewV10API) {
            if (!$usersValid || !$groupsValid) {
                return false;
            }
        }

        $defaultRetainedReviewers = [];
        if ($this->projects !== null) {
            // everything seems valid so far - we need to check the reviewer retention policy on
            // associated projects and branches
            $defaultRetainedReviewers = UpdateService::mergeDefaultReviewersForProjects(
                $this->projects,
                $defaultRetainedReviewers,
                $this->connectionOption['connection'],
                [UpdateService::ALWAYS_ADD_DEFAULT => false, UpdateService::FORCE_REQUIREMENT => false]
            );
        }

        // Exclude the author if they are default retained
        if ($this->reviewAuthor) {
            unset($defaultRetainedReviewers[$this->reviewAuthor]);
        }

        array_map(
            function ($key, $retention) use ($value, &$retentionValid) {
                if (isset($value[$key])) {
                    $minimum = $retention[ReviewModel::FIELD_MINIMUM_REQUIRED];
                    if ($minimum === true || $minimum === '1') {
                        $requiredValue = isset($value[$key]['required']) ? $value[$key]['required'] : null;
                        if ($requiredValue === null || ($requiredValue === '1' && $minimum === true)) {
                            $isGroup        = Group::isGroupName($key);
                            $requireMessage = $this->translator->t('required');
                            $messageKey     = $key;
                            if ($isGroup) {
                                $messageKey     = Group::getGroupName($key);
                                $requireMessage = $minimum === true
                                    ? $this->translator->t('require all') : $this->translator->t('require 1');
                            }
                            $this->addMessage(
                                $this->translator->t(
                                    "'%s' must be a reviewer with a minimum requirement of '%s'",
                                    [$messageKey, $requireMessage]
                                )
                            );
                            $retentionValid              = false;
                            $this->retain_reviewer_error = true;
                            $this->retain_reviewer[]     = $key;
                        }
                    }
                } else {
                    $this->addMessage(
                        $this->translator->t(
                            "'%s' must be a reviewer",
                            [Group::isGroupName($key) ? Group::getGroupName($key) : $key]
                        )
                    );
                    $this->retain_reviewer_error = true;
                    $this->retain_reviewer[]     = $key;
                    $retentionValid              = false;
                }
            },
            array_keys($defaultRetainedReviewers),
            $defaultRetainedReviewers
        );
        return $groupsValid && $usersValid && $quorumValid && $retentionValid;
    }

    /**
     * This will return true if retained reviewer error is raised else false
     * @return bool
     */
    public function hasRetainedReviewersError()
    {
        return $this->retain_reviewer_error;
    }

    /**
     * This will store retain reviewer participant id, Failed during retention check
     * @return array
     */
    public function hasRetainedReviewers()
    {
        return $this->retain_reviewer;
    }

    /**
     * Runs the validation
     * @param $validator mixed validator
     * @param $value array value(s)
     * @return boolean true if value is valid
     */
    private function doValidate($validator, $value)
    {
        $valid = $validator->isValid($value);
        if (!$valid) {
            $this->abstractOptions['messages'] =
                array_merge($this->abstractOptions['messages'], $validator->getMessages());
        }
        return $valid;
    }
}
