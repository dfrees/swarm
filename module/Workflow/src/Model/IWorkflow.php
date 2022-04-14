<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Model;

use Application\Checker;
use P4\Exception;

interface IWorkflow
{
    // Define the ID and name for the global workflow
    const GLOBAL_WORKFLOW_ID   = 0;
    const GLOBAL_WORKFLOW_NAME = 'Global Workflow';
    // General workflow
    const WORKFLOW         = 'workflow';
    const WORKFLOWS        = 'workflows';
    const WORKFLOW_ENABLED = 'enabled';
    // Checker name
    const WORKFLOW_CHECKER        = [Checker::NAME => self::WORKFLOW];
    const WORKFLOW_CHECKER_RETURN =
        [Checker::NAME => self::WORKFLOW, Checker::OPTIONS => [Checker::RETURN_VALUE => true]];

    // Rule definition
    const WORKFLOW_RULES      = 'workflow_rules';
    const ON_SUBMIT           = 'on_submit';
    const WITH_REVIEW         = 'with_review';
    const WITHOUT_REVIEW      = 'without_review';
    const WITH_REVIEW_RULE    = 'with_review_rule';
    const WITHOUT_REVIEW_RULE = 'without_review_rule';
    const RULE                = 'rule';
    const MODE                = 'mode';
    const END_RULES           = 'end_rules';
    const UPDATE              = 'update';
    const AUTO_APPROVE        = 'auto_approve';
    const COUNTED_VOTES       = 'counted_votes';
    const GROUP_EXCLUSIONS    = 'group_exclusions';
    const USER_EXCLUSIONS     = 'user_exclusions';

    // Value definition
    const NO_CHECKING = 'no_checking';
    const REJECT      = 'reject';
    const APPROVED    = 'approved';
    const AUTO_CREATE = 'auto_create';
    const STRICT      = 'strict';
    const NO_REVISION = 'no_revision';
    const NEVER       = 'never';
    const VOTES       = 'votes';
    const ANYONE      = 'anyone';
    const MEMBERS     = 'members';
    // MODE prefix for these as DEFAULT is a keyword in PHP and cannot be used for a const
    const MODE_POLICY  = 'policy';
    const MODE_DEFAULT = 'default';
    const MODE_INHERIT = 'inherit';
    // For tests
    const TESTS           = 'tests';
    const EVENT           = 'event';
    const BLOCKS          = 'blocks';
    const TEST_ID         = 'id';
    const EVENT_ON_SUBMIT = 'onSubmit';
    const EVENT_ON_UPDATE = 'onUpdate';
    const EVENT_ON_DEMAND = 'onDemand';
    const NOTHING         = 'nothing';

    // Fields
    const NAME        = 'name';
    const ID          = 'id';
    const DESCRIPTION = 'description';
    const OWNERS      = 'owners';
    const SHARED      = 'shared';
    const UPGRADE     = 'upgrade';

    // Values
    const NO_WORKFLOW_ID = 'no-workflow';

    /**
     * Gets the data that defines workflow rules for on_submit, For example
     *      "on_submit": {
     *          "with_review": {
     *              "rule": "no_checking"
     *          },
     *          "without_review": {
     *              "rule": "no_checking"
     *          }
     *      }
     * @return array 'on_submit' data
     */
    public function getOnSubmit();

    /**
     * Sets the data that defines workflow rules for on_submit, For example
     *      "on_submit": {
     *          "with_review": {
     *              "rule": "no_checking"
     *          },
     *          "without_review": {
     *              "rule": "no_checking"
     *          }
     *      }
     * @param array     $onSubmit   data that defines workflow rules for on_submit
     * @return IWorkflow
     */
    public function setOnSubmit($onSubmit);

    /**
     * Sets the data that defines workflow rules for end_rules, For example
     *      "end_rules": {
     *          "update": {
     *              "rule": "no_checking"
     *          }
     *      }
     * @param array     $endRules   data that defines workflow rules for end_rules
     * @return mixed
     */
    public function setEndRules($endRules);

    /**
     * Gets the data that defines workflow rules for end_rules, For example
     *      "end_rules": {
     *          "update": {
     *              "rule": "no_checking"
     *          }
     *      }
     * @return array 'end_rules' data
     */
    public function getEndRules();

    /**
     * Gets the data that defines workflow rules for auto_approve, For example
     *      "auto_approve": {
     *          "rule": "votes|never"
     *      }
     * @return array 'auto_approve' data
     */
    public function getAutoApprove();

    /**
     * Sets the data that defines workflow rules for auto_approve, For example
     *      "auto_approve": {
     *          "rule": "votes|never"
     *      }
     * @param array     $autoApprove   data that defines workflow rules for auto_approve
     * @return mixed
     */
    public function setAutoApprove($autoApprove);

    /**
     * Sets the data that defines workflow rules for counted_votes, For example
     *      "counted_votes": {
     *          "rule": "anyone"
     *      }
     * @param array 'counted_votes' data that defines which votes get counted
     * @return mixed
     */
    public function setCountedVotes($countedVoteRules);

    /**
     * Gets the data that defines workflow rules for counted_votes, For example
     *      "counted_votes": {
     *          "rule": "anyone"
     *      }
     * @return array 'counted_votes' data that defines which votes get counted
     */
    public function getCountedVotes();

    /**
     * Gets the data that defines workflow rules for group_exclusions, For example
     *      "group_exclusions": {
     *          "rule": []
     *      }
     * @return array 'group_exclusions' data that defines which groups are excluded
     */
    public function getGroupExclusions();

    /**
     * Sets the data that defines workflow rules for group_exclusions, For example
     *      "group_exclusions": {
     *          "rule": []
     *      }
     * @param array 'group_exclusions' data that defines which groups are excluded
     * @return mixed
     */
    public function setGroupExclusions($groupExclusions);

    /**
     * Gets the data that defines workflow rules for user_exclusions, For example
     *      "user_exclusions": {
     *          "rule": []
     *      }
     * @return array 'user_exclusions' data that defines which users are excluded
     */
    public function getUserExclusions();

    /**
     * Sets the data that defines workflow rules for user_exclusions, For example
     *      "user_exclusions": {
     *          "rule": []
     *      }
     * @param array 'user_exclusions' data that defines which users are excluded
     * @return mixed
     */
    public function setUserExclusions($userExclusions);

    /**
     * Gets the workflow name
     * @return string
     */
    public function getName();

    /**
     * Sets the workflow name
     * @param $name
     * @return IWorkflow
     */
    public function setName($name);

    /**
     * Gets the workflow description
     * @return string
     */
    public function getDescription();

    /**
     * Sets the workflow description
     * @param $description
     * @return IWorkflow
     */
    public function setDescription($description);

    /**
     * Boolean value indicating whether this workflow is shared.
     * A non-shared workflow is only visible by the owners
     * @return boolean
     */
    public function isShared();

    /**
     * Set whether this workflow is shared.
     * A non-shared workflow is only visible by the owners
     * @param $shared
     * @return IWorkflow
     */
    public function setShared($shared);

    /**
     * Returns an array of owner ids associated with this workflow. Owners can
     * be users or groups.
     * @param   bool    $flip       if true array keys are the owner ids (default is false)
     * @return  array   ids of all owners for this workflow
     */
    public function getOwners($flip = false);

    /**
     * Set owners for this workflow. Owners can be users or groups.
     * @param $owners
     * @return IWorkflow
     */
    public function setOwners($owners);

    /**
     * Determines if the user is an owner of the workflow by checking individual and group ownership
     * @param string        $userId     user id to test for ownership
     * @return bool true if the user is an individual owner or member of a group that is an owner
     * @throws Exception
     */
    public function isOwner($userId);

    /**
     * Determine if the user can edit the workflow. Workflows owned by the user/group are editable. A super user
     * can edit any workflow
     * @param mixed         $userIdentifier     can be a P4 connection or a string user id. If a connection is provided
     *                                          the workflow is editable if the connection has super privilege or the
     *                                          user id for the connection is a workflow owner. If a string user id is
     *                                          provided the workflow is editable if the user id is an owner. If this
     *                                          value is not a connection or a string or not provided then user id for
     *                                          the connection associated with the model is assessed
     * @return bool true if the workflow is shared or if the user is an individual owner or member
     * of a group that is an owner or if the authenticated user has super permission
     */
    public function canEdit($userIdentifier = null);

    /**
     * Gets test details associated with this workflow. Defaults to an empty array if not set.
     * If set each array element will reference a test definition id and some associated metadata
     * @return mixed
     */
    public function getTests();

    /**
     * Set tests for the workflow. Should be in the format
     *  "tests": [
     *      {
     *          "id": "17",
     *          "event": "onUpdate",
     *          "blocks": ""
     *      },
     *      {
     *          "id": "24",
     *          "event": "onSubmit",
     *          "blocks": ""
     *      }
     *  ]
     * @param mixed     $tests      tests to set
     * @return mixed
     */
    public function setTests($tests);
}
