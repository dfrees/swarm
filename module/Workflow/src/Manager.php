<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Service\P4Command;
use Exception;
use Groups\Model\Group;
use InvalidArgumentException;
use P4\Exception as P4Exception;
use P4\Log\Logger;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Reviews\Model\Review;
use TestIntegration\Filter\StatusValidator;
use TagProcessor\Filter\ITagFilter;
use Workflow\Model\IWorkflow;
use Workflow\Model\Workflow;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Interop\Container\ContainerInterface;
use TestIntegration\Model\TestRun;

/**
 * Class to manage workflow decisions.
 * @package Workflow
 */
class Manager implements InvokableService
{
    const LOG_PREFIX                = Manager::class;
    const KEY_STATUS                = 'status';
    const KEY_MESSAGES              = 'messages';
    const STATUS_OK                 = 'OK';
    const STATUS_NO_REVIEW          = 'NO_REVIEW';
    const STATUS_NO_APPROVED_REVIEW = 'NO_APPROVED_REVIEW';
    const STATUS_BAD_CHANGE         = 'BAD_CHANGE';
    const STATUS_CREATED_REVIEW     = 'CREATED_REVIEW';
    const STATUS_LINKED_REVIEW      = 'LINKED_REVIEW';
    const STATUS_NO_REVISION        = 'NO_REVISION';
    const STATUS_NOT_SAME_CONTENT   = 'NOT_SAME_CONTENT';
    const BAD_CHANGE_ID             = 'Must supply a valid id to fetch.';

    const STATUS_WORK_IN_PROGRESS_CHANGE = 'WORK_IN_PROGRESS_CHANGE';

    private $user;
    private $workflowDao;
    private $messages;
    private $logger;
    private $globalWorkflow               = null;
    private $services                     = null;
    private $vanilla                      = [
        IWorkflow::WORKFLOW_RULES => [
            IWorkflow::ON_SUBMIT => [
                IWorkflow::WITH_REVIEW    => [IWorkflow::RULE => IWorkflow::NO_CHECKING],
                IWorkflow::WITHOUT_REVIEW => [IWorkflow::RULE => IWorkflow::NO_CHECKING],
            ],
            IWorkflow::END_RULES => [
                IWorkflow::UPDATE => [IWorkflow::RULE => IWorkflow::NO_CHECKING]
            ],
            IWorkflow::AUTO_APPROVE => [IWorkflow::RULE => IWorkflow::NEVER],
            IWorkflow::COUNTED_VOTES => [IWorkflow::RULE => IWorkflow::ANYONE],
            IWorkflow::GROUP_EXCLUSIONS => [IWorkflow::RULE => []],
            IWorkflow::USER_EXCLUSIONS => [IWorkflow::RULE => []]
        ]];
    private static $workflowRulesTemplate = [
        IWorkflow::ON_SUBMIT => [
            IWorkflow::WITH_REVIEW    => [IWorkflow::RULE => null],
            IWorkflow::WITHOUT_REVIEW => [IWorkflow::RULE => null],
        ],
        IWorkflow::END_RULES => [
            IWorkflow::UPDATE => [IWorkflow::RULE => null]
        ],
        IWorkflow::AUTO_APPROVE => [IWorkflow::RULE => null],
        IWorkflow::COUNTED_VOTES => [IWorkflow::RULE => null],
        IWorkflow::GROUP_EXCLUSIONS => [IWorkflow::RULE => null],
        IWorkflow::USER_EXCLUSIONS => [IWorkflow::RULE => null]
    ];

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services    = $services;
        $this->workflowDao = $services->get(IModelDAO::WORKFLOW_DAO);
        $translator        = $services->get(TranslatorFactory::SERVICE);
        $this->logger      = $services->get(SwarmLogger::SERVICE);
        $this->messages    = [
            self::STATUS_NO_REVIEW          => $translator->t('Change [%s] must be associated with a review'),
            self::STATUS_NO_APPROVED_REVIEW => $translator->t('Review [%s] must be approved'),
            self::STATUS_CREATED_REVIEW     => $translator->t('Review [%s] created for change [%s]'),
            self::STATUS_LINKED_REVIEW      => $translator->t('Review [%s] linked to change [%s]'),
            self::STATUS_NO_REVISION        => $translator->t('Review [%s] is in state [%s] and cannot be updated'),
            self::STATUS_NOT_SAME_CONTENT   => $translator->t('Change [%s] content is different from Review [%s]'),
            self::STATUS_WORK_IN_PROGRESS_CHANGE => $translator->t(
                'Change [%s] contains the Work in Progress tag, review will not be created'
            )
        ];
    }

    /**
     * Gets a message for the key.
     * @param string    $key    key for the message
     * @return mixed|null
     */
    private function getMessage($key)
    {
        return isset($this->messages[$key]) ? $this->messages[$key] : '';
    }

    /**
     * Carries out strict checks on the change if enabled
     * @param string $changeId  the change id.
     * @param string $user      the user, used to determine any exclusions
     * @return array Containing a status and an array (possibly empty) of messages
     * @throws ConfigException
     * @throws Exception
     */
    public function checkStrict($changeId, $user)
    {
        $enforce    = $this->checkWorkflow();
        $this->user = $user;
        return $enforce ? $enforce : $this->processEnforcedOrStrict($changeId, true);
    }

    /**
     * Carries out enforced checks on the change if enabled
     * @param string $changeId  the change id.
     * @param string $user      the user, used to determine any exclusions
     * @return array
     * @throws ConfigException
     * @throws Exception
     */
    public function checkEnforced($changeId, $user)
    {
        $enforce    = $this->checkWorkflow();
        $this->user = $user;
        return $enforce ? $enforce : $this->processEnforcedOrStrict($changeId);
    }

    /**
     * Carries out shelve checks on the change if enabled (shelve-submit)
     * @param string $changeId  the change id.
     * @param string $user      the user, used to determine any exclusions
     * @return array
     * @throws ConfigException
     * @throws Exception
     */
    public function checkShelve($changeId, $user)
    {
        $enforce    = $this->checkWorkflow();
        $this->user = $user;
        return $enforce ? $enforce : $this->processShelve($changeId);
    }

    /**
     * Checks whether workflows are enabled and just returns an 'OK' result or throws an exception if they are not.
     * @param bool      $consumeException   whether to return an 'OK' result or rethrow any exception. Default is
     *                                      to return 'OK'. This allows the check to be done in one place
     * @return array|null
     * @throws ForbiddenException if workflow check throws an exception and it has been specified that it should not
     *                            be consumed
     */
    private function checkWorkflow($consumeException = true)
    {
        try {
            $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER);
            if (!$this->globalWorkflow) {
                $this->globalWorkflow = $this->workflowDao->fetchById(
                    IWorkflow::GLOBAL_WORKFLOW_ID,
                    $this->services->get(ConnectionFactory::P4_ADMIN)
                );
            }
        } catch (ForbiddenException $fe) {
            $this->logger->info($fe->getMessage());
            if ($consumeException) {
                return $this->buildResult(self::STATUS_OK, $fe->getMessage());
            } else {
                throw $fe;
            }
        }
        return null;
    }

    /**
     * Get the rule value associated with the given id based upon the workflows associated with the projects/branches
     * provided, merged with any defaults and policies defined in the swarm config.
     *
     * If the project definition cannot be found in the optional list of projects it will be retrieved using the
     * given connection.
     *
     * @param string                $ruleId             The unique name of the IWorkflow::RULE values to be merged
     * @param array                 $projectBranches    The project=>branch ids that should be merged
     * @param null                  $projects           An optional pre-populated list of project models, missing data
     *                                                  will be fetched
     *
     * @return mixed|null   The net IWorkflow::RULE value, or null if there is no relevant value
     * @throws P4Exception
     * @throws Exception
     */
    public function getBranchRule($ruleId, $projectBranches, $projects = null)
    {
        $services  = $this->services;
        $logger    = Logger::getLogger();
        $workflows = [];
        $rule      = null;

        $connection = $services->get(ConnectionFactory::P4_ADMIN);
        $config     = $services->get(ConfigManager::CONFIG);
        $projectDAO = $services->get(IModelDAO::PROJECT_DAO);
        try {
            $this->checkWorkflow(false);
            $logger->info("Workflow/Manager: Getting '$ruleId' rule for ['" . var_export($projectBranches, true) . "]");
            $projectArray = (array) $projects;
            foreach ($projectBranches as $projectId => $branches) {
                try {
                    if (!isset($projectArray[ $projectId ])) {
                        $projectArray[ $projectId ] = $projectDAO->fetch($projectId, $connection);
                    }
                    $populatedBranches = $projectArray[ $projectId ]->getBranches();
                    $workflows         = array_merge(
                        $workflows,
                        array_map(
                            function ($branch) use ($projectArray, $projectId, $populatedBranches) {
                                return $projectArray[ $projectId ]->getWorkflow($branch, $populatedBranches);
                            },
                            $branches
                        )
                    );
                } catch (RecordNotFoundException $nfe) {
                    $logger->notice("Workflow/Manager: Project [$projectId] is not found, falling back to defaults.");
                }
            }
            // Merge the list of workflows
            foreach ($services->get(IModelDAO::WORKFLOW_DAO)->fetchAll(
                [Workflow::FETCH_BY_IDS => array_unique($workflows)],
                $connection
            ) as $workflow) {
                $logger->debug("Workflow/Manager: Processing " . $workflow->getId());
                $rule = $this->mergeRule($ruleId, $rule, $workflow);
            }
            // Now apply any global override
            $rule = $this->mergeGlobalRule($ruleId, $rule, $config);
        } catch (ForbiddenException $fe) {
            // Workflow is disabled
            $logger->debug('Workflow/Manager: getBranchRule disregarding project workflow ' . $fe->getMessage());
        }
        return $rule;
    }

    /**
     * Merge the current value of a rule with that from the workflow provided.
     *
     * @param $ruleId       - The unique name of the IWorkflow::RULE value to be merged
     * @param $currentValue - The current value for that rule
     * @param $workflow     - The workflow with a new potential value
     * @return mixed        - The result of the merging, typically a string
     * @throws Exception
     */
    private function mergeRule($ruleId, $currentValue, $workflow)
    {
        $mergeTo = [IWorkflow::WORKFLOW_RULES=>static::$workflowRulesTemplate];
        switch ($ruleId) {
            case IWorkflow::AUTO_APPROVE:
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::AUTO_APPROVE][IWorkflow::RULE] = $currentValue;
                $this->mergeAutoApproveRule($workflow, $mergeTo);
                $mergedValue = $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::AUTO_APPROVE][IWorkflow::RULE];
                break;
            case IWorkflow::COUNTED_VOTES:
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::COUNTED_VOTES][IWorkflow::RULE] = $currentValue;
                $this->mergeCountedVotesRule($workflow, $mergeTo);
                $mergedValue = $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::COUNTED_VOTES][IWorkflow::RULE];
                break;
            case IWorkflow::UPDATE:
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES][IWorkflow::UPDATE]
                    [IWorkflow::RULE] = $currentValue;
                $this->mergeUpdateRule($workflow, $mergeTo);
                $mergedValue = $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES][IWorkflow::UPDATE]
                    [IWorkflow::RULE];
                break;
            case IWorkflow::WITH_REVIEW:
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW]
                    [IWorkflow::RULE] = $currentValue;
                $this->mergeWithReviewRule($workflow, $mergeTo);
                $mergedValue = $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW]
                    [IWorkflow::RULE];
                break;
            case IWorkflow::WITHOUT_REVIEW:
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW]
                    [IWorkflow::RULE] = $currentValue;
                $this->mergeWithoutReviewRule($workflow, $mergeTo);
                $mergedValue = $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW]
                    [IWorkflow::RULE];
                break;
            default:
                throw new Exception("Manager/mergeRule: Unsupported rule id[$ruleId]");
                break;
        }
        return $mergedValue;
    }

    /**
     * Merge the current value of a rule with those in the swarm config, taking account of defaults and policy
     * settings. A policy will always be merged, a default will apply if there is no current value.
     *
     * @param $ruleId       - The unique name of the IWorkflow::RULE value to be merged
     * @param $currentValue - The current value for that rule
     * @param $config       - The configuration object, containing workflow default/policy values
     * @return mixed        - The result of the merging, typically a string
     * @throws Exception
     */
    private function mergeGlobalRule($ruleId, $currentValue, $config)
    {
        $mergedValue = $currentValue;
        $workflow    = new Workflow();
        $workflow->set(static::$workflowRulesTemplate);
        switch ($ruleId) {
            case IWorkflow::AUTO_APPROVE:
                $ruleDefaults = $this->globalWorkflow->getAutoApprove();
                if ($ruleDefaults[IWorkflow::MODE] === IWorkflow::MODE_POLICY) {
                    $workflow->setAutoApprove($ruleDefaults);
                    $mergedValue = static::mergeRule($ruleId, $currentValue, $workflow);
                }
                break;
            case IWorkflow::COUNTED_VOTES:
                $ruleDefaults = $this->globalWorkflow->getCountedVotes();
                if ($ruleDefaults[IWorkflow::MODE] === IWorkflow::MODE_POLICY) {
                    $workflow->setCountedVotes($ruleDefaults);
                    $mergedValue = static::mergeRule($ruleId, $currentValue, $workflow);
                }
                break;
            case IWorkflow::UPDATE:
                $defaultEndRules = $this->globalWorkflow->getEndRules();
                $ruleDefaults    = $defaultEndRules[IWorkflow::UPDATE];
                if ($defaultEndRules[IWorkflow::UPDATE][IWorkflow::MODE] === IWorkflow::MODE_POLICY) {
                    $workflow->setEndRules($defaultEndRules);
                    $mergedValue = static::mergeRule($ruleId, $currentValue, $workflow);
                }
                break;
            case IWorkflow::WITH_REVIEW:
                $defaultOnSubmit = $this->globalWorkflow->getOnSubmit();
                $ruleDefaults    = $defaultOnSubmit[IWorkflow::WITH_REVIEW];
                if ($defaultOnSubmit[IWorkflow::WITH_REVIEW][IWorkflow::MODE] === IWorkflow::MODE_POLICY) {
                    $workflow->setOnSubmit($defaultOnSubmit);
                    $mergedValue = static::mergeRule($ruleId, $currentValue, $workflow);
                }
                break;
            case IWorkflow::WITHOUT_REVIEW:
                $defaultOnSubmit = $this->globalWorkflow->getOnSubmit();
                $ruleDefaults    = $defaultOnSubmit[IWorkflow::WITHOUT_REVIEW];
                if ($defaultOnSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE] === IWorkflow::MODE_POLICY) {
                    $workflow->setOnSubmit($defaultOnSubmit);
                    $mergedValue = static::mergeRule($ruleId, $currentValue, $workflow);
                }
                break;
            default:
                throw new Exception("Manager/mergeRule: Unsupported rule id[$ruleId]");
                break;
        }
        if ($mergedValue === null) {
            return $ruleDefaults[IWorkflow::RULE];
        }
        return $mergedValue;
    }

    /**
     * Checks on shelve-submit to only permit if there is either no review or there is a review and we are
     * allowing a change based on its state (end_states)
     * @param string $changeId string the change id.
     * @return array
     * @throws ConfigException
     * @throws Exception
     */
    private function processShelve($changeId)
    {
        // Validate the change id early. We don't want to continue and query for reviews if the
        // change is not known
        $this->logger->trace(
            self::LOG_PREFIX . ": Process workflow for change (shelve) " . var_export($changeId, true)
        );
        $result = $this->validateChangeId($changeId);
        if ($result[self::KEY_STATUS] === self::STATUS_OK) {
            $this->logger->trace(self::LOG_PREFIX . ": Change $changeId is valid");
            $review = $this->getReview($changeId);
            $this->logger->trace(
                self::LOG_PREFIX . ": Review for change $changeId is " . ($review ? $review->getId() : 'not found')
            );
            // It may not yet be linked to a review but # processing for a description may be going to link it later so
            // we need to find that review if it is the case and see what state that is in
            if ($review === null) {
                $review = $this->getLinkedReview($result[$changeId]);
            }
            if ($review !== null) {
                // For a shelf with a review we are only interested in workflows with IWorkflow::REJECT
                $workflow = $this->getMergedWorkflow(
                    $result[$changeId],
                    $review->getProjects()
                );
                $this->logger->trace(
                    self::LOG_PREFIX . ": Merged workflow in process shelve " . var_export($workflow, true)
                );
                if (!$this->isExcluded($workflow, $this->user)) {
                    $result = $this->checkEndRules($review, $workflow);
                }
            }
        }
        unset($result[$changeId]);
        return $result;
    }

    /**
     * Checks whether the review obeys the end rules criteria
     * @param Review    $review     the current review
     * @param IWorkflow $workflow   current merged workflow, if not provided it will be determined from the
     *                              projects on the review
     * @return array|null
     * @throws ConfigException
     * @throws Exception
     */
    public function checkEndRules($review, $workflow = null)
    {
        $result = null;

        $workflow = $workflow ? $workflow : $this->getMergedWorkflow(null, $review->getProjects());
        $rule     = $workflow[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES]
                             [IWorkflow::UPDATE][IWorkflow::RULE];
        if ($rule === IWorkflow::NO_REVISION) {
            $endStates = ConfigManager::getValue(
                $this->services->get('config'),
                ConfigManager::REVIEWS_END_STATES
            );
            $this->logger->trace(
                self::LOG_PREFIX .
                sprintf(
                    " Check end rules, review state [%s], commit count [%s]",
                    $review->getState(),
                    count($review->getCommits())
                )
            );
            $invalid = in_array($review->getState(), $endStates);
            // When the review state is in the endStates
            if ($invalid || in_array(
                $review->getState(),
                array_map(
                    function ($entry) {
                        return strpos($entry, ":") !== false ? strstr($entry, ":", true) : $entry;
                    },
                    $endStates
                )
            )) {
                // Specific case for 'approved' end state and performing an 'approve and commit' from the UI.
                // The state is changed first and we need to avoid erroring on the state of 'approved' not
                // being able to be updated
                if (Review::STATE_APPROVED === $review->getState()
                    && $review->getCommitStatus('status') === 'Committing') {
                    return $this->buildResult(self::STATUS_OK);
                }
                // Allow for approved:commit being in the end states
                if ($invalid || !in_array(Review::STATE_APPROVED_COMMIT, $endStates) ||
                    !Review::STATE_APPROVED === $review->getState() ||
                    count($review->getCommits()) !== 0) {
                    $result = $this->buildResult(
                        self::STATUS_NO_REVISION,
                        sprintf($this->getMessage(self::STATUS_NO_REVISION), $review->getId(), $review->getState())
                    );
                }
            }
        }
        return $result ? $result : $this->buildResult(self::STATUS_OK);
    }

    /**
     * Test the user id to see if it is subject to user or group exclusions
     * @param mixed         $workflow   the workflow
     * @param string|null   $userId     the user id
     * @return bool true if excluded
     */
    public function isExcluded($workflow, $userId)
    {
        $excluded = false;
        // Quick exit if user not provided
        if ($userId) {
            $userExclusions = $workflow[IWorkflow::WORKFLOW_RULES][IWorkflow::USER_EXCLUSIONS][IWorkflow::RULE] ?: [];
            $p4Admin        = $this->services->get(ConnectionFactory::P4_ADMIN);
            $excluded       = $p4Admin->stringMatches($userId, $userExclusions);
            if (!$excluded) {
                $groupExclusions =
                    $workflow[IWorkflow::WORKFLOW_RULES][IWorkflow::GROUP_EXCLUSIONS][IWorkflow::RULE] ?: [];
                if ($groupExclusions) {
                    $groupDao = $this->services->get(IModelDAO::GROUP_DAO);
                    foreach ($groupExclusions as $groupExclusion) {
                        try {
                            if ($groupDao->isMember($userId, Group::getGroupName($groupExclusion), true, $p4Admin)) {
                                $excluded = true;
                                break;
                            }
                        } catch (NotFoundException $e) {
                            // Not a valid group, log but still carry on
                            $this->logger->warn(
                                self::LOG_PREFIX .
                                sprintf(": Invalid group [%s] in workflow exclusions", $groupExclusion)
                            );
                        }
                    }
                }
            }
        }
        $this->logger->trace(
            self::LOG_PREFIX . sprintf(": Workflow [%b]", ($excluded ? 'excluded' : 'not excluded'))
        );
        return $excluded;
    }

    /**
     * Processes the rule
     * @param string        $changeId     the change id
     * @param bool          $checkContent If we want to check the content of the changelist.
     * @return array
     * @throws ConfigException
     * @throws Exception
     */
    private function processEnforcedOrStrict($changeId, $checkContent = false)
    {
        // Validate the change id early. We don't want to continue and query for reviews if the
        // change is not known
        $this->logger->trace(
            self::LOG_PREFIX . ": Process workflow for change (enforced) " . var_export($changeId, true)
        );
        $result = $this->validateChangeId($changeId);
        if ($result[self::KEY_STATUS] === self::STATUS_OK) {
            $this->logger->trace(self::LOG_PREFIX . ": Change $changeId is valid");
            $review = $this->getReview($changeId);
            if ($review === null) {
                // It may not yet be linked to a review but # processing for a description may be going to link it later
                // so we need to find that review if it is the case and see what state that is in
                $review = $this->getLinkedReview($result[$changeId]);
            }
            $this->logger->trace(
                self::LOG_PREFIX . ": Review for change $changeId is " . ($review ? $review->getId() : 'not found')
            );
            $workflow = $this->getMergedWorkflow(
                $result[$changeId],
                $review === null ? null : $review->getProjects()
            );
            if (!$this->isExcluded($workflow, $this->user)) {
                $this->logger->trace(
                    self::LOG_PREFIX . ": Merged workflow in process rule " . var_export($workflow, true)
                );
                if ($review === null) {
                    $rule = $workflow[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                    [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE];
                } else {
                    $rule = $workflow[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                    [IWorkflow::WITH_REVIEW][IWorkflow::RULE];
                }
                $this->logger->trace(self::LOG_PREFIX . ": Rule is $rule");
                if ($review !== null) {
                    switch ($rule) {
                        case IWorkflow::APPROVED:
                        case IWorkflow::STRICT:
                            // On submit any # processing will have happened so the change will already be linked
                            // if the description specified so we do not need to look at the change description in
                            // this case
                            $result = $this->checkEndRules($review, $workflow);
                            if ($result[self::KEY_STATUS] === self::STATUS_OK) {
                                // If checkContent is true we shouldn't need to re check required Approved
                                if ($checkContent) {
                                    $result = $this->checkContent($review, $changeId);
                                } else {
                                    $result = $this->requireApproved($review);
                                }
                            }
                            break;
                        case IWorkflow::NO_CHECKING:
                            // Even if we aren't checking for approved we still need to make
                            // sure we are not violating the end rule/end state configuration
                            $result = $this->checkEndRules($review, $workflow);
                            break;
                    }
                } else {
                    switch ($rule) {
                        case IWorkflow::NO_CHECKING:
                            break;
                        case IWorkflow::AUTO_CREATE:
                            $result = $this->autoCreateReview($result[$changeId]);
                            break;
                        case IWorkflow::REJECT:
                            $result = $this->requireReview($changeId);
                            break;
                    }
                }
            }
            unset($result[$changeId]);
        }
        return $result;
    }

    /**
     * Gets a review if it exists
     * @param string        $changeId   the change id
     * @return mixed
     * @throws Exception
     */
    private function getReview($changeId)
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $review  = null;
        $reviews = Review::fetchAll([Review::FETCH_BY_CHANGE => $changeId], $p4Admin);
        $this->logger->trace(self::LOG_PREFIX . ": Found " . $reviews->count() . " review(s) for change $changeId");
        if ($reviews->count() !== 0) {
            $review = $reviews->first();
        }
        return $review;
    }

    /**
     * Check that the users changelist and the head changelist of the review content is the same.
     * We can let the P4D server do the checking for us as diff2 check the content without expanding
     * the keywords
     *
     * @param Review $review
     * @param int    $changeId
     *
     * @return array result
     */
    private function checkContent($review, $changeId)
    {
        // Get the admin connection and then use the change service to check the content diff.
        $p4            = $this->services->get(ConnectionFactory::P4_ADMIN);
        $changeService = $this->services->get(Services::CHANGE_SERVICE);
        return $changeService->hasContentChanged($p4, $review->getHeadChange(), $changeId)
            ? $this->buildResult(
                self::STATUS_NOT_SAME_CONTENT,
                sprintf($this->getMessage(self::STATUS_NOT_SAME_CONTENT), $changeId, $review->getId())
            )
            : $this->buildResult(self::STATUS_OK);
    }

    /**
     * Enforces that the review is approved
     * @param Review $review the review.
     * @return array result
     */
    private function requireApproved($review)
    {
        $this->logger->trace(self::LOG_PREFIX . ": In requireApproved, review state is " . $review->getState());
        if ($review->getState() === Review::STATE_APPROVED) {
            return $this->buildResult(self::STATUS_OK);
        } else {
            return $this->buildResult(
                self::STATUS_NO_APPROVED_REVIEW,
                sprintf($this->getMessage(self::STATUS_NO_APPROVED_REVIEW), $review->getId())
            );
        }
    }

    /**
     * Enforces auto create by creating a review if it does not already exist.
     * @param string $change Change the change.
     * @return array result of the action
     * @throws P4Exception
     * @throws \Record\Exception\Exception
     */
    private function autoCreateReview($change)
    {
        $changeId   = $change->getId();
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $review     = $this->getLinkedReview($change);
        $messageKey = self::STATUS_CREATED_REVIEW;
        // We only want to auto-create a review if the change wasn't linked to an existing
        // review by #review #append etc
        if ($review) {
            $messageKey = self::STATUS_LINKED_REVIEW;
        } else {
            // Check if the Work in progress tag in in play here.
            $wipKeyword = $this->services->get(ITagFilter::WIP_KEYWORD);
            $wipMatches = $wipKeyword->hasMatches($change->getDescription());
            if ($wipMatches) {
                // We have found a match to the work in progress tag reject commit and creation of review.
                $message = sprintf($this->getMessage(self::STATUS_WORK_IN_PROGRESS_CHANGE), $changeId);
                return $this->buildResult(self::STATUS_WORK_IN_PROGRESS_CHANGE, $message);
            }
            $review = Review::createFromChange($changeId, $p4Admin)->save();
        }
        $message = sprintf($this->getMessage($messageKey), $review->getId(), $changeId);
        return $this->buildResult(self::STATUS_OK, $message);
    }

    /**
     * Checks the description of the change to see if it will be linked to a review and returns that
     * review if found.
     * @param $change
     * @return null|AbstractKey
     */
    private function getLinkedReview($change)
    {
        $review      = null;
        $p4Admin     = $this->services->get(ConnectionFactory::P4_ADMIN);
        $keywords    = $this->services->get('review_keywords');
        $description = $change->getDescription();
        $this->logger->trace(self::LOG_PREFIX . " Description in getLinkedReview " . $description);
        $matches = $keywords->getMatches($description);
        if ($matches && isset($matches['id']) && !empty($matches['id'])) {
            $this->logger->trace(self::LOG_PREFIX . ": Matches from change description " . var_export($matches, true));
            try {
                $review = Review::fetch($matches['id'], $p4Admin);
            } catch (RecordNotFoundException $e) {
                // This is OK #review-x has been provided but x does not relate to a review
                $this->logger->trace(self::LOG_PREFIX . ": Review from matches was not found");
            }
        }
        return $review;
    }

    /**
     * Enforces that the provided change has a review
     * @param string $changeId string the change id.
     * @return array
     */
    private function requireReview($changeId)
    {
        return $this->buildResult(
            self::STATUS_NO_REVIEW,
            sprintf($this->getMessage(self::STATUS_NO_REVIEW), $changeId)
        );
    }

    /**
     * Looks at the impacted projects and branches and returns merged workflow settings with the most restrictive
     * setting winning. If there are no workflows defined for any of the impacted projects or branches null is returned.
     * @param array     $impacted       impacted projects
     * @return array|null
     * @throws Exception
     */
    // Avoids a potentially expensive call to ProjectModel::getAffectedProjects for when projects are known.
    public function getMergedAffectedWorkflowRules($impacted)
    {
        $mergeTo           = $this->vanilla;
        $hasBranchWorkflow = false;
        $p4Admin           = $this->services->get(ConnectionFactory::P4_ADMIN);
        $projectDAO        = $this->services->get(IModelDAO::PROJECT_DAO);
        $projectWf         = null;
        $mergedIds         = [];
        $this->logger->trace(self::LOG_PREFIX . ": Impacted projects for change " . var_export($impacted, true));
        foreach ($impacted as $projectId => $branches) {
            $project    = $projectDAO->fetch($projectId, $p4Admin);
            $workflowId = $project->getWorkflow();
            if ($workflowId) {
                $this->logger->trace(self::LOG_PREFIX . ": Processing workflow $workflowId for project $projectId");
                try {
                    $projectWf = $this->workflowDao->fetch($workflowId, $p4Admin);
                } catch (RecordNotFoundException $e) {
                    $this->logger->warn(self::LOG_PREFIX . ": Workflow $workflowId for project $projectId not found");
                }
            }
            $populatedBranches = $project->getBranches();
            // We need to assess all the branches, as we could for example have
            // - b1 has a workflow different from the project
            // - b2 does not have it's own workflow
            // In this case b1 may have specified a rule that should be replaced by the project when considering b2.
            // If we were to not process b2 because it returns to the project workflow then we wouldn't pick up on this.
            // We keep track of workflows we have merged already so that if there are many branches defaulting to the
            // project workflow we only merge in the project the first time
            foreach ($branches as $branch) {
                $workflowId = $project->getWorkflow($branch, $populatedBranches);
                // It will be the project level workflow returned if the branch is invalid or does
                // not have its own workflow. We only need to merge if we have not encountered the
                // workflow before
                if ($workflowId && !in_array(intval($workflowId), $mergedIds)) {
                    $this->logger->trace(
                        self::LOG_PREFIX . ": Processing workflow $workflowId for project $projectId branch $branch"
                    );
                    try {
                        $this->mergeWorkflow($this->workflowDao->fetch($workflowId, $p4Admin), $mergeTo);
                        $hasBranchWorkflow = true;
                    } catch (RecordNotFoundException $e) {
                        $this->logger->warn(
                            self::LOG_PREFIX . ": Workflow $workflowId for branch $branch not found"
                        );
                    }
                    $mergedIds[] = intval($workflowId);
                } else {
                    if ($workflowId) {
                        $this->logger->trace(
                            sprintf(
                                "%s: Skipping workflow [%s] for project [%s] branch [%s], already merged",
                                self::LOG_PREFIX,
                                $workflowId,
                                $projectId,
                                $branch
                            )
                        );
                    }
                }
            }
        }
        return $hasBranchWorkflow || $projectWf != null ? $mergeTo : null;
    }

    /**
     * Looks at the affected projects and branches for the change and returns merged workflow
     * settings with the most restrictive setting winning. If there are no workflows defined for any
     * of the impacted projects or branches null is returned.
     * @param Change    $change     the change spec
     * @return array|null
     * @throws Exception
     */
    private function getMergedChangeWorkflowRules($change)
    {
        $p4Admin      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $findAffected = $this->services->get(Services::AFFECTED_PROJECTS);
        // First try to get impacted projects from a shelved (default p4 describe with '-sS')
        $impacted = $findAffected->findByChange($p4Admin, $change);
        if (empty($impacted)) {
            // If there are no impacted projects try again with p4 describe '-s' as this may
            // have been a straight submit without shelved files
            $impacted = $findAffected->findByChange(
                $p4Admin,
                $change,
                [P4Command::COMMAND_FLAGS => ['-s'], P4Command::TAGGED => false]
            );
        }
        return $this->getMergedAffectedWorkflowRules($impacted);
    }

    /**
     * Merges the workflow into the rolling array representation with the strictest rule winning
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeWorkflow(IWorkflow $workflow, &$mergeTo)
    {
        $this->mergeWithReviewRule($workflow, $mergeTo);
        $this->mergeWithoutReviewRule($workflow, $mergeTo);
        $this->mergeUpdateRule($workflow, $mergeTo);
        $this->mergeAutoApproveRule($workflow, $mergeTo);
        $this->mergeCountedVotesRule($workflow, $mergeTo);
        $this->mergeArrayRule($workflow->getUserExclusions(), IWorkflow::USER_EXCLUSIONS, $mergeTo);
        $this->mergeArrayRule($workflow->getGroupExclusions(), IWorkflow::GROUP_EXCLUSIONS, $mergeTo);
        $this->logger->trace(self::LOG_PREFIX . ": Merged workflow " . var_export($mergeTo, true));
    }

    /**
     * Merges the workflow 'with_review' into the rolling array representation with the strictest rule winning
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeWithReviewRule(IWorkflow $workflow, &$mergeTo)
    {
        $wfOnSubmit = $workflow->getOnSubmit();
        if (isset($wfOnSubmit[IWorkflow::WITH_REVIEW])) {
            $withReviewRule = $wfOnSubmit[IWorkflow::WITH_REVIEW][IWorkflow::RULE];
            switch ($withReviewRule) {
                case IWorkflow::APPROVED:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITH_REVIEW][IWorkflow::RULE] === IWorkflow::NO_CHECKING) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITH_REVIEW][IWorkflow::RULE] = $withReviewRule;
                    }
                    break;
                case IWorkflow::STRICT:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITH_REVIEW][IWorkflow::RULE] === IWorkflow::NO_CHECKING ||
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITH_REVIEW][IWorkflow::RULE] === IWorkflow::APPROVED) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITH_REVIEW][IWorkflow::RULE] = $withReviewRule;
                    }
                    break;
                default:
                    break;
            }
            // There is no value, use whatever is passed in
            if (!isset(
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::RULE]
            )) {
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW]
                [IWorkflow::RULE] = $withReviewRule;
            }
        }
    }

    /**
     * Merges the workflow 'auto_approve' into the rolling array representation with the strictest rule winning.
     * In this case 'NEVER' is more strict
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeAutoApproveRule(IWorkflow $workflow, &$mergeTo)
    {
        $wfAutoApprove = $workflow->getAutoApprove();
        if (isset($wfAutoApprove[IWorkflow::RULE])) {
            $rule = $wfAutoApprove[IWorkflow::RULE];
            switch ($rule) {
                case IWorkflow::NEVER:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES]
                        [IWorkflow::AUTO_APPROVE][IWorkflow::RULE] === IWorkflow::VOTES) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES]
                        [IWorkflow::AUTO_APPROVE][IWorkflow::RULE] = $rule;
                    }
                    break;
                default:
                    break;
            }
            // There is no value, use whatever is passed in
            if (!isset($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::AUTO_APPROVE][IWorkflow::RULE])) {
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::AUTO_APPROVE][IWorkflow::RULE] = $rule;
            }
        }
    }

    /**
     * Merge rules that are arrays of values.
     * @param array     $wfArray    values from workflow
     * @param string    $ruleName   rule name under workflow_rules
     * @param array     $mergeTo    workflow to merge to
     */
    private function mergeArrayRule($wfArray, $ruleName, &$mergeTo)
    {
        if (isset($wfArray[IWorkflow::RULE])) {
            $rule = $wfArray[IWorkflow::RULE];
            if (isset($mergeTo[IWorkflow::WORKFLOW_RULES][$ruleName][IWorkflow::RULE])) {
                $mergeTo[IWorkflow::WORKFLOW_RULES][$ruleName][IWorkflow::RULE] =
                    array_unique(
                        array_keys(
                            array_flip(
                                array_merge(
                                    $mergeTo[IWorkflow::WORKFLOW_RULES][$ruleName][IWorkflow::RULE],
                                    $rule
                                )
                            )
                        )
                    );
            } else {
                $mergeTo[IWorkflow::WORKFLOW_RULES][$ruleName][IWorkflow::RULE] = $rule;
            }
        }
    }

    /**
     * Merges the workflow 'update' into the rolling array representation with the strictest rule winning
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeUpdateRule(IWorkflow $workflow, &$mergeTo)
    {
        $wfEndRules = $workflow->getEndRules();
        if (isset($wfEndRules[IWorkflow::UPDATE])) {
            $rule = $wfEndRules[IWorkflow::UPDATE][IWorkflow::RULE];
            switch ($rule) {
                case IWorkflow::NO_REVISION:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES]
                        [IWorkflow::UPDATE][IWorkflow::RULE] === IWorkflow::NO_CHECKING) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES]
                        [IWorkflow::UPDATE][IWorkflow::RULE] = $rule;
                    }
                    break;
                default:
                    break;
            }
            // There is no value, use whatever is passed in
            if (!isset(
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::RULE]
            )) {
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::END_RULES][IWorkflow::UPDATE]
                [IWorkflow::RULE] = $rule;
            }
        }
    }

    /**
     * Merges the workflow 'without_review' into the rolling array representation with the strictest rule winning
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeWithoutReviewRule(IWorkflow $workflow, &$mergeTo)
    {
        $wfOnSubmit = $workflow->getOnSubmit();
        if (isset($wfOnSubmit[IWorkflow::WITHOUT_REVIEW])) {
            $withoutReviewRule = $wfOnSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE];
            switch ($withoutReviewRule) {
                case IWorkflow::AUTO_CREATE:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] === IWorkflow::NO_CHECKING) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] = $withoutReviewRule;
                    }
                    break;
                case IWorkflow::REJECT:
                    if ($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] === IWorkflow::NO_CHECKING ||
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] === IWorkflow::AUTO_CREATE) {
                        $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT]
                        [IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] = $withoutReviewRule;
                    }
                    break;
                default:
                    break;
            }
            // There is no value, use whatever is passed in
            if (!isset(
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE]
            )) {
                $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW]
                [IWorkflow::RULE] = $withoutReviewRule;
            }
        }
    }

    /**
     * Merges the workflow 'counted_votes' into the rolling array representation with the strictest rule winning. The
     * counted votes rule is very simple members takes precedence.
     * @param IWorkflow     $workflow   the workflow
     * @param array         $mergeTo    the rolling merged workflow
     */
    private function mergeCountedVotesRule(IWorkflow $workflow, &$mergeTo)
    {
        $countedVotes = $workflow->getCountedVotes();
        if (isset($countedVotes[IWorkflow::RULE]) &&
            (   $countedVotes[IWorkflow::RULE] === IWorkflow::MEMBERS ||
                !isset($mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::COUNTED_VOTES][IWorkflow::RULE]))
        ) {
            $mergeTo[IWorkflow::WORKFLOW_RULES][IWorkflow::COUNTED_VOTES][IWorkflow::RULE] =
                $countedVotes[IWorkflow::RULE];
        }
    }

    /**
     * Builds a merged workflow based on the global settings and any workflows defined on the projects and branches
     * that the change impacts
     * @param Change        $change             the change spec used to find affected projects
     * @param array         $affectedProjects   if provided these projects are used in preference rather than retrieving
     *                                          based on the change
     * @return array|null
     * @throws Exception
     */
    public function getMergedWorkflow($change, $affectedProjects = null)
    {
        $this->checkWorkflow(false);
        $globalWorkflow      = $this->globalWorkflow;
        $groupExclusions     = $globalWorkflow->getGroupExclusions();
        $userExclusions      = $globalWorkflow->getUserExclusions();
        $onSubmit            = $globalWorkflow->getOnSubmit();
        $endRules            = $globalWorkflow->getEndRules();
        $autoApprove         = $globalWorkflow->getAutoApprove();
        $countedVotes        = $globalWorkflow->getCountedVotes();
        $withReviewMode      = $onSubmit[IWorkflow::WITH_REVIEW][IWorkflow::MODE];
        $withoutReviewMode   = $onSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE];
        $endRulesMode        = $endRules[IWorkflow::UPDATE][IWorkflow::MODE];
        $autoApproveMode     = $autoApprove[IWorkflow::MODE];
        $countedVotesMode    = $countedVotes[IWorkflow::MODE];
        $groupExclusionsMode = $groupExclusions[IWorkflow::MODE];
        $userExclusionsMode  = $userExclusions[IWorkflow::MODE];

        $this->logger->trace(
            self::LOG_PREFIX . ": Global end_rules " . var_export($globalWorkflow->getEndRules(), true)
        );
        $this->logger->trace(
            self::LOG_PREFIX . ": Global on_submit " . var_export($globalWorkflow->getOnSubmit(), true)
        );

        $specificWorkflow = $affectedProjects !== null
            ? $this->getMergedAffectedWorkflowRules($affectedProjects)
            : $this->getMergedChangeWorkflowRules($change);
        if ($specificWorkflow) {
            if ($withReviewMode === IWorkflow::MODE_POLICY || $withoutReviewMode === IWorkflow::MODE_POLICY) {
                // If policy then merge global rule in if stricter
                if ($withReviewMode === IWorkflow::MODE_POLICY) {
                    $this->mergeWithReviewRule($globalWorkflow, $specificWorkflow);
                }
                if ($withoutReviewMode === IWorkflow::MODE_POLICY) {
                    $this->mergeWithoutReviewRule($globalWorkflow, $specificWorkflow);
                }
            }
            if ($endRulesMode === IWorkflow::MODE_POLICY) {
                $this->mergeUpdateRule($globalWorkflow, $specificWorkflow);
            }
            if ($autoApproveMode === IWorkflow::MODE_POLICY) {
                $this->mergeAutoApproveRule($globalWorkflow, $specificWorkflow);
            }
            if ($countedVotesMode === IWorkflow::MODE_POLICY) {
                $this->mergeCountedVotesRule($globalWorkflow, $specificWorkflow);
            }
            if ($groupExclusionsMode === IWorkflow::MODE_POLICY) {
                $specificWorkflow[IWorkflow::WORKFLOW_RULES][IWorkflow::GROUP_EXCLUSIONS][IWorkflow::RULE]
                    = $groupExclusions[IWorkflow::RULE];
            } else {
                $this->mergeArrayRule($groupExclusions, IWorkflow::GROUP_EXCLUSIONS, $specificWorkflow);
            }
            if ($userExclusionsMode === IWorkflow::MODE_POLICY) {
                $specificWorkflow[IWorkflow::WORKFLOW_RULES][IWorkflow::USER_EXCLUSIONS][IWorkflow::RULE]
                    = $userExclusions[IWorkflow::RULE];
            } else {
                $this->mergeArrayRule($userExclusions, IWorkflow::USER_EXCLUSIONS, $specificWorkflow);
            }
        }
        unset($onSubmit[IWorkflow::WITH_REVIEW][IWorkflow::MODE]);
        unset($onSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE]);
        unset($endRules[IWorkflow::UPDATE][IWorkflow::MODE]);
        unset($autoApprove[IWorkflow::MODE]);
        unset($countedVotes[IWorkflow::MODE]);
        unset($groupExclusions[IWorkflow::MODE]);
        unset($userExclusions[IWorkflow::MODE]);
        return $specificWorkflow
            ? $specificWorkflow
            : [IWorkflow::WORKFLOW_RULES =>
                [
                    IWorkflow::ON_SUBMIT     => $onSubmit,
                    IWorkflow::END_RULES     => $endRules,
                    IWorkflow::AUTO_APPROVE  => $autoApprove,
                    IWorkflow::COUNTED_VOTES => $countedVotes,
                    IWorkflow::GROUP_EXCLUSIONS => $groupExclusions,
                    IWorkflow::USER_EXCLUSIONS  => $userExclusions
                ]
            ];
    }

    /**
     * Makes sure the change id is the correct format and that it exists
     * @param string $changeId string the change id.
     * @return array
     * @throws P4Exception
     */
    private function validateChangeId($changeId)
    {
        $error  = null;
        $result = $this->buildResult(self::STATUS_OK);
        try {
            $change = Change::fetchById($changeId, $this->services->get(ConnectionFactory::P4_ADMIN));
            // So we do not have to fetch the change again we will put it in the result
            $result[$changeId] = $change;
        } catch (NotFoundException $e) {
            $error = $e;
        } catch (InvalidArgumentException $e) {
            $error = $e;
        }
        if ($error) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            $result     = $this->buildResult(self::STATUS_BAD_CHANGE, $translator->t($error->getMessage()));
        }
        return $result;
    }

    /**
     * Helper to build a result to return
     * @param $status
     * @param null $message
     * @return array
     */
    private function buildResult($status, $message = null)
    {
        if ($message) {
            $messages = [$message];
        } else {
            $messages = [];
        }
        return [self::KEY_STATUS => $status, self::KEY_MESSAGES => $messages];
    }

    /**
     * Gets the global workflow
     * @return mixed the global workflow
     * @throws ForbiddenException if workflows are not enabled
     */
    public function getGlobalWorkflow()
    {
        $this->checkWorkflow(false);
        return $this->globalWorkflow;
    }

    /**
     * Assesses if any of the tests associated with workflows for the affected projects should
     * block the state provided.
     * @param mixed         $review             the review
     * @param mixed         $affectedProjects   the calculated affected projects
     * @param mixed         $state              the state to query blocking for
     * @return bool If tests are defined that block the state and the review has
     * test runs that have not passed linked to those test then the state is seen as blocked
     */
    public function isBlockedByTests($review, $affectedProjects, $state) : bool
    {
        return (bool)$this->getBlockingTests($review, $affectedProjects, $state);
    }

    /**
     * Gets test definition ids for any tests linked to workflows for the affected projects that block
     * the state provided.
     * @param mixed         $review             the review
     * @param mixed         $affectedProjects   the calculated affected projects
     * @param mixed         $state              the state to query blocking for
     * @return array an array of test definition ids that block the current state for which there are test runs on the
     * review that have not passed. An empty array is returned if no blocked tests are found
     */
    public function getBlockingTests($review, $affectedProjects, $state) : array
    {
        $blockingTests = [];
        $p4Admin       = $this->services->get(ConnectionFactory::P4_ADMIN);
        $projectDao    = $this->services->get(IDao::PROJECT_DAO);
        $workflows     = $projectDao->getWorkflowsForAffectedProjects($affectedProjects);
        $workflows[]   = IWorkflow::GLOBAL_WORKFLOW_ID;
        $workflowDao   = $this->services->get(IDao::WORKFLOW_DAO);
        $tests         = $workflowDao->getBlockingTests($workflows, [], $p4Admin);
        $testIds       = [];
        $reviewId      = $review->getId();
        $this->logger->debug(
            sprintf(
                "%s: Checking for tests blocking state [%s] for review id [%s]",
                self::LOG_PREFIX,
                $state,
                $reviewId
            )
        );
        if (isset($tests[$state])) {
            foreach ($tests[$state] as $id) {
                $testIds[] = (string)$id;
            }
        }
        if ($testIds) {
            $this->logger->debug(
                sprintf(
                    "%s: Tests with ids [%s] block state [%s] for review id [%s]",
                    self::LOG_PREFIX,
                    implode(' ,', $testIds),
                    $state,
                    $reviewId
                )
            );
            $testRunDao = $this->services->get(IDao::TEST_RUN_DAO);
            $testRuns   = $testRunDao->fetchAll([TestRun::FETCH_BY_IDS => $review->getTestRuns()], $p4Admin);
            foreach ($testRuns as $testRun) {
                $status    = $testRun->getStatus();
                $testId    = $testRun->getTest();
                $testRunId = $testRun->getId();
                $this->logger->debug(
                    sprintf(
                        "%s: Check to see if test run with id [%s] and status [%s] is blocking",
                        self::LOG_PREFIX,
                        $testRunId,
                        $status
                    )
                );
                if ($status !== StatusValidator::STATUS_PASS) {
                    if (in_array($testId, $testIds)) {
                        $this->logger->debug(
                            sprintf(
                                "%s: Test run with id [%s], test [%s], and status [%s] is blocking",
                                self::LOG_PREFIX,
                                $testRunId,
                                $testId,
                                $status
                            )
                        );
                        $blockingTests[] = $testId;
                    }
                }
            }
        } else {
            $this->logger->debug(
                sprintf(
                    "%s: No tests found blocking state [%s] for review id [%s]",
                    self::LOG_PREFIX,
                    $state,
                    $reviewId
                )
            );
        }
        return array_unique($blockingTests);
    }
}
