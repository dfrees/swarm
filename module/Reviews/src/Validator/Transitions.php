<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Validator;

use Application\Config\ConfigManager;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\ServicesModelTrait;
use Application\Option;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Permissions;
use Application\Validator\ValidatorException;
use Comments\Model\Comment;
use Events\Listener\ListenerFactory;
use Interop\Container\ContainerInterface;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\ConflictException;
use Queue\Manager;
use Record\Lock\Lock;
use Reviews\Filter\Vote;
use Reviews\ITransition;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Users\Validator\Users;
use Application\Config\ConfigException;
use Exception;
use Workflow\Model\IWorkflow;
use Laminas\Validator\AbstractValidator;

class Transitions extends AbstractValidator implements InvokableService, ITransition
{
    const OPT_UP_VOTES = 'includeExtraUpVotes';
    const REVIEW       = 'review';
    private $review;
    private $options;
    private $p4Admin;
    private $transitions;
    private $services;
    const INVALID_STATE = 'invalidState';

    /**
     * Transitions constructor.
     * @param ContainerInterface    $services   application services
     * @param array|null            $options    Supported values:
     *                                          OPT_UP_VOTES     - Implicit up votes to include
     *                                          Option::USER_ID  - the current authenticated user ID
     *                                          REVIEW           - review being acted on
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $defaults = [
            self::OPT_UP_VOTES => null,
            self::REVIEW       => null,
            Option::USER_ID    => null
        ];

        Option::validate($options, $defaults);
        $this->services    = $services;
        $this->review      = $options[self::REVIEW];
        $this->options     = $options;
        $this->p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
        $this->transitions = $this->review->getTransitions();
    }

    /**
     * Follows the validator pattern to test if the value is a valid state to transition to. Different error messages
     * are raised for an unknown state as opposed to a known state that is not a valid transition
     * @param mixed $value the new transition state
     * @return bool true if valid
     * @throws ConfigException
     */
    public function isValid($value)
    {
        $valid = true;
        if (in_array($value, self::ALL_VALID_TRANSITIONS)) {
            $allowed = $this->getAllowedTransitions();
            if (!in_array($value, array_keys($allowed))) {
                $translator = $this->services->get(TranslatorFactory::SERVICE);

                $this->abstractOptions['messages'][self::INVALID_STATE] =
                    $translator->t(
                        "Invalid review state [%s], must be one of [%s]",
                        [$value, implode(', ', array_keys($allowed))]
                    );

                $valid = false;
            }
        } else {
            $translator = $this->services->get(TranslatorFactory::SERVICE);

            $this->abstractOptions['messages'][self::INVALID_STATE] =
                $translator->t(
                    "Unknown state [%s], must be one of [%s]",
                    [$value, implode(', ', self::ALL_VALID_TRANSITIONS)]
                );

            $valid = false;
        }
        return $valid;
    }

    /**
     * Merge the up-voters with the author
     * @return array Return a list of valid user account.
     */
    private function populateUpVotes()
    {
        $includeExtraUpVotes =
            [
                $this->review->isValidAuthor()
                ? $this->review->getAuthorObject()->getId()
                : $this->review->get('author')
            ];

        $upVoters = $this->options[self::OPT_UP_VOTES];
        // Take a list of user name or group names and put them into a array
        if ($upVoters && !empty($upVoters)) {
            $userValidator = new Users(['connection' => $this->p4Admin]);
            if ($userValidator->isValid($upVoters)) {
                $includeExtraUpVotes = array_merge($includeExtraUpVotes, $upVoters);
            } else {
                $messages = $userValidator->getMessages();
                throw new \InvalidArgumentException($messages[Users::UNKNOWN_IDS]);
            }
        }
        return $includeExtraUpVotes;
    }

    /**
     * Get transitions for review model and filter response.
     * If the user isn't a candidate to transition this review, false is returned. It is recommended
     * the transition UI be disabled in that case.
     * If the user is a candidate, an array will be returned though it may be empty. Even an empty
     * array indicates transitioning is viable and the UI may opt to stay enable and show items such
     * as 'add a commit' in this case.
     *
     * @return mixed array of available transitions (may be empty) or false
     * @throws ConfigException
     * @throws Exception
     */
    public function getAllowedTransitions()
    {
        $userId = $this->options[Option::USER_ID];
        try {
            $this->services->get(Services::CONFIG_CHECK)->enforce(IWorkflow::WORKFLOW);
            $manager = $this->services->get(Services::WORKFLOW_MANAGER);
            $wf      = $manager->getMergedWorkflow(null, $this->review->getProjects());
            if ($manager->isExcluded($wf, $userId)) {
                // Exit early with all transitions if workflows are enabled and user is part of
                // workflow exclusions
                return $this->transitions;
            }
        } catch (ForbiddenException $e) {
            // Workflows not enabled, we will carry on with normal processing
        }

        $p4Admin             = $this->p4Admin;
        $includeExtraUpVotes = $this->populateUpVotes();

        $members    = [];
        $moderators = [];
        $impacted   = $this->review->getProjects();
        $this->populateImpactedMembersAndModerators($moderators, $members, $impacted);
        // use the stringMatches helper as it accounts for the server's case sensitivity
        $isAuthor = $p4Admin->stringMatches($userId, $this->review->get('author'));
        $this->checkDisableCommits();
        $this->checkAuthorAllowedToCommit($isAuthor);
        $this->checkCanApprove($includeExtraUpVotes, $impacted);
        $this->checkOutstandingTasks();
        $this->checkRoles(
            $moderators,
            $members,
            $isAuthor,
            $impacted
        );
        return $this->transitions;
    }

    /**
     * Check to see if the current allowed transitions include 'approved' or 'approved:commit'.
     * @return bool true if the current allowed transitions include 'approved' or 'approved:commit', otherwise false
     */
    private function transitionsIncludeApproval() : bool
    {
        return isset($this->transitions[IReview::STATE_APPROVED]) ||
               isset($this->transitions[IReview::STATE_APPROVED_COMMIT]);
    }

    /**
     * Get a list of moderators and members that will impact the transition of this review.
     *
     * @param array       $impacted     This is a list of project this review impacts.
     * @param array       $moderators   This is the list of moderators for the review.
     * @param array       $members      This is the members of the impacted projects
     *
     * @throws Exception
     */
    private function populateImpactedMembersAndModerators(&$moderators, &$members, $impacted)
    {
        // prepare list of members/moderators of review-related projects/project-branches
        if ($impacted) {
            $projectDAO = ServicesModelTrait::getProjectDao();
            $projects   = $projectDAO->fetchAll(['ids' => array_keys($impacted)], $this->p4Admin);
            foreach ($projects as $project) {
                $branches = $impacted[$project->getId()];
                foreach ($branches as $branch) {
                    foreach ($project->getModerators([$branch]) as $branchModerator) {
                        $moderators = array_merge_recursive(
                            $moderators,
                            [$project->getId() => [$branch => [$branchModerator]]]
                        );
                    }
                }
                $members = array_merge($members, $project->getAllMembers());
            }
        }
    }

    /**
     * Check if the disable commits is enabled.
     * @throws ConfigException
     */
    private function checkDisableCommits()
    {
        // remove option to commit a review if disabled in route params or config
        $disableCommitConfig = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::REVIEWS_DISABLE_COMMIT,
            false
        );
        if ($disableCommitConfig) {
            unset($this->transitions[Review::STATE_APPROVED_COMMIT]);
        }
    }

    /**
     * Check if we allow the Author to commit the review or not.
     *
     * @param boolean       $isAuthor      Check to see if current user is the author of the review.
     *
     * @throws ConfigException
     */
    private function checkAuthorAllowedToCommit($isAuthor)
    {
        // We only need to do the work if the 'approved' and 'approved:commit' transitions have not already been removed
        // by another check
        if ($this->transitionsIncludeApproval()) {
            $disableSelfApprove = ConfigManager::getValue(
                $this->services->get(ConfigManager::CONFIG),
                ConfigManager::REVIEWS_DISABLE_SELF_APPROVE,
                false
            );
            // deny authors approving their own reviews if self-approval is disabled in config
            // note this will take place even if the author is also a moderator
            if ($isAuthor && $this->review->get('state') !== Review::STATE_APPROVED && $disableSelfApprove == true) {
                unset($this->transitions[Review::STATE_APPROVED]);
                unset($this->transitions[Review::STATE_APPROVED_COMMIT]);
            }
        }
    }

    /**
     * Check if we can approve/commit the review. This defers the check to the review
     * @see Review::canApprove()
     * @param mixed        $includeExtraUpVotes  A list of user that if had voted could change outcome of transitions
     * @param mixed        $impacted             A list of project with branches.
     */
    private function checkCanApprove($includeExtraUpVotes, $impacted)
    {
        // We only need to do the work if the 'approved' and 'approved:commit' transitions have not already been removed
        // by another check
        if ($this->transitionsIncludeApproval()) {
            // Do this after the more simple checks as there is no need to check all the votes
            // if approval is already not permitted.
            // if we have required reviewers, and they haven't all
            // up voted don't allow anyone to approve or commit unless
            // the current user is the last required reviewer to vote, then we
            // will let them approve, and take their approval as an up-vote
            // This call passing in the $userId tests for outstanding votes
            // assuming $userId votes up also
            // It is possible from P4V etc for the author to be added as a required
            // reviewer (we filter these out in the UI). We disregard the author vote
            // by assuming a vote up as we do not allow authors to vote on their
            // own reviews. Without assuming their vote other users will not be
            // able to approve and commit if the authors vote is required
            $workflowsEnabled =
                $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER_RETURN);
            $branchRules      = [];
            if ((isset($this->transitions[Review::STATE_APPROVED]) ||
                    isset($this->transitions[Review::STATE_APPROVED_COMMIT])) &&
                !$this->review->canApprove(
                    $includeExtraUpVotes,
                    $impacted,
                    $workflowsEnabled,
                    $branchRules
                )
            ) {
                unset($this->transitions[Review::STATE_APPROVED]);
                unset($this->transitions[Review::STATE_APPROVED_COMMIT]);
            }
        }
    }

    /**
     * Check to see if we are gating the review based on outstanding comments marked as
     * tasks that need to be addressed.
     */
    private function checkOutstandingTasks()
    {
        // We only need to do the work if the 'approved' and 'approved:commit' transitions have not already been removed
        // by another check
        if ($this->transitionsIncludeApproval()) {
            try {
                $outstandingTasksConfig = ConfigManager::getValue(
                    $this->services->get(ConfigManager::CONFIG),
                    ConfigManager::REVIEWS_DISABLE_APPROVE_WHEN_TASKS_OPEN,
                    false
                );
            } catch (Exception $e) {
                // Ignore config errors and allow the remaining transition checks to process.
                $outstandingTasksConfig = false;
            }
            // Check to see if open tasks should prevent approval
            if ((isset($this->transitions[Review::STATE_APPROVED]) ||
                    isset($this->transitions[Review::STATE_APPROVED_COMMIT]))
                && $outstandingTasksConfig) {
                try {
                    $comments = Comment::fetchAll(
                        [
                            Comment::FETCH_BY_TOPIC => ['reviews/' . $this->review->getId()],
                            Comment::FETCH_BY_TASK_STATE => [Comment::TASK_OPEN],
                            Comment::FETCH_IGNORE_ARCHIVED => true
                        ],
                        $this->p4Admin
                    );
                } catch (Exception $e) {
                    // We failed to fetch comments.
                    $comments = false;
                }
                if ($comments) {
                    $commentsArray = $comments->toArray(true);
                    if (!empty($commentsArray)) {
                        unset($this->transitions[Review::STATE_APPROVED]);
                        unset($this->transitions[Review::STATE_APPROVED_COMMIT]);
                    }
                }
            }
        }
    }

    /**
     * Tests the populated moderators to see if the current user is mentioned
     * @param array $moderators
     * @return bool true if the current user is found
     */
    private function isModerator($moderators)
    {
        $p4Admin = $this->p4Admin;
        $userId  = $this->options[Option::USER_ID];
        foreach ((array)$moderators as $project => $branches) {
            foreach ($branches as $branch => $mods) {
                if ($p4Admin->stringMatches($userId, $mods)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Filter the transitions base on the users role as part of the review.
     * @param array     $moderators     This is the list of moderators for the review, and the relevant branches
     * @param array     $members        Project members across the review
     * @param boolean   $isAuthor       Check to see if current user is the author of the review.
     * @param array     $impacted       This is a list of project this review impacts.
     *
     * @throws ConfigException
     * @throws Exception
     */
    private function checkRoles(
        $moderators,
        $members,
        $isAuthor,
        $impacted
    ) {
        $permissions = $this->services->get(Permissions::PERMISSIONS);
        $isSuper     = $permissions->is(Permissions::SUPER);
        $p4Admin     = $this->p4Admin;
        $userId      = $this->options[Option::USER_ID];
        $isMember    = $p4Admin->stringMatches($userId, $members);
        $isModerator = $this->isModerator($moderators);
        $isApprover  = in_array($this->review->getHeadVersion(), $this->review->getApprovals($userId));
        // if review touches project(s) then
        //  - only members and the review author can change state or attach commits
        //
        // if review touches moderated branch(es) then
        //  - only moderators can approve or reject the review
        //  - authors who are not moderators can move between needs-review/needs-revision/archived and
        //    can attach commits; they cannot approve or reject review
        //  - members can move between needs-review/needs-revision and can attach
        //    commits; they cannot approve/reject or archive
        //  - users that are not project members, moderators or the author cannot perform any transitions
        if (count($moderators)) {
            // For super anything goes from the transitions that have already been established so we
            // don't have to work through the moderator/author allowed states
            if (!$isSuper) {
                $currentState = $this->review->get('state');
                $authorStates = [Review::STATE_NEEDS_REVIEW, Review::STATE_NEEDS_REVISION, Review::STATE_ARCHIVED];
                $memberStates = [Review::STATE_NEEDS_REVIEW, Review::STATE_NEEDS_REVISION];
                if ($currentState === Review::STATE_APPROVED) {
                    $authorStates[] = Review::STATE_APPROVED;
                    $authorStates[] = Review::STATE_APPROVED_COMMIT;
                    $memberStates[] = Review::STATE_APPROVED;
                    $memberStates[] = Review::STATE_APPROVED_COMMIT;
                }

                // if the author is also a moderator, treat them as moderator
                if ($isAuthor && !$isModerator) {
                    $this->transitions = in_array($currentState, $authorStates)
                        ? array_intersect_key($this->transitions, array_flip($authorStates))
                        : [];
                } elseif ($isMember && !$isAuthor && !$isModerator) {
                    $this->transitions = in_array($currentState, $memberStates)
                        ? array_intersect_key($this->transitions, array_flip($memberStates))
                        : [];
                } elseif (!$isModerator) {
                    $this->transitions = false;
                }
                // If this is the world of discrete moderation, then more states need to be filtered
                $moderatorMode =
                    ConfigManager::getValue(
                        $this->services->get(ConfigManager::CONFIG),
                        ConfigManager::REVIEWS_MODERATOR_APPROVAL
                    );
                if (is_array($this->transitions) && ConfigManager::VALUE_EACH === $moderatorMode) {
                    // Moderators from each branch need to approve
                    $canCommit = $this->canCommit($this->review, $moderators);
                    // Allow anything that is not approved, or when approval is allowed
                    $this->transitions = array_flip(
                        array_filter(
                            array_flip($this->transitions),
                            function ($state) use ($isApprover, $canCommit) {
                                $allowed = true;
                                switch ($state) {
                                    case Review::STATE_APPROVED:
                                        $allowed = !$isApprover;
                                        break;

                                    case Review::STATE_APPROVED_COMMIT:
                                        $allowed = $canCommit;
                                        break;
                                    default:
                                        break;
                                }
                                return $allowed;
                            }
                        )
                    );
                }
            }
        } elseif (count($impacted) && !$isMember && !$isAuthor && !$isSuper) {
            $this->transitions = false;
        }
    }

    /**
     * Determine if it is possible to commit the review (assumes that each of the moderators must approve rather
     * than any single moderator having the authority). For commit to be possible
     * Either
     *  - there must be no moderators
     * or
     *  - if there are moderators each of the moderators must have approved the latest revision.
     * The current users approval is presumed when working out if commit is possible. For example if there are no
     * approvals at all but the current users approval would make it possible to commit then the approve/commit
     * option needs to be shown. This is similar to how we handle implicit up votes when checking if a review has
     * votes outstanding.
     * @param Review        $review     the review
     * @param array         $moderators representation of moderators
     * @return bool - true/false => approval states [are/are not] allowed
     */
    private function canCommit(Review $review, $moderators)
    {
        $approvals = $this->getReviewApprovals($review);
        // If there are no approvals, but there are moderators approval
        $canCommit = $moderators === null || count($moderators) === 0;
        if (!$canCommit) {
            // There must be approvals from each branch with moderators
            foreach ((array)$moderators as $project => $branches) {
                foreach ($branches as $branch => $branchModerators) {
                    foreach ($approvals as $approver => $versions) {
                        foreach ($branchModerators as $moderator) {
                            if ($moderator === $approver && in_array($review->getHeadVersion(), $versions)) {
                                unset($moderators[$project][$branch]);
                                if (isset($moderators[$project]) && count($moderators[$project]) === 0) {
                                    unset($moderators[$project]);
                                }
                            }
                        }
                    }
                }
            }
            // approved[:committed] is allowed if there are no more moderators needed
            $canCommit = count($moderators) === 0;
        }
        return $canCommit;
    }

    /**
     * Get approvals for the given review and add in an implicit approval from the current
     * user to allow them to see approve and commit if they are the first to ever approve that
     * would mean commit is possible
     * @param Review $review    the current review
     * @return array the approvals
     */
    private function getReviewApprovals(Review $review)
    {
        $approvals = $review->getApprovals();
        // If there are no approvals for the current user or if they have not approved
        // the latest version pretend that they have to work out if approve:commit should
        // be allowed
        if (isset($approvals[$this->options[Option::USER_ID]])) {
            $versions = $approvals[$this->options[Option::USER_ID]];
            if (!in_array($review->getHeadVersion(), $versions)) {
                $versions[]                                 = $review->getHeadVersion();
                $approvals[$this->options[Option::USER_ID]] = $versions;
            }
        } else {
            $approvals[$this->options[Option::USER_ID]] = [$review->getHeadVersion()];
        }
        return $approvals;
    }

    /**
     * Transition a review taking into account jobs/fixes/text and cleanup.
     * @param array     $data   data for transition. Must contain a minimum of a transition value. Example of full
     *                          specification
     *                          [
     *                              "transition" => "approved:commit",
     *                              "jobs"       => ["job000001"],
     *                              "fixStatus"  => "closed",
     *                              "text"       => "text for comment or description change"
     *                              "cleanup"    => true
     *                          ]
     * @return mixed the review
     * @throws CommandException
     * @throws ConfigException
     * @throws ConflictException
     * @throws ValidatorException
     */
    // This combines the functionality from Reviews->IndexController->transitionAction and
    // Reviews->IndexController->editReview to carry out the same operations without using
    // the web controller
    public function transition(array $data)
    {
        $user      = $this->services->get(ConnectionFactory::USER);
        $logger    = $this->services->get(SwarmLogger::SERVICE);
        $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
        $reviewDao = $this->services->get(IDao::REVIEW_DAO);
        $config    = $this->services->get(ConfigManager::CONFIG);
        $jobs      = isset($data[self::JOBS]) ? $data[self::JOBS] : null;
        $fixStatus = isset($data[self::FIX_STATUS]) ? $data[self::FIX_STATUS] : null;
        // Most times on transition change we treat the comment as a comment, except in the case of
        // Review::STATE_APPROVED_COMMIT which means a description update
        $text           = isset($data[self::TEXT]) ? trim($data[self::TEXT]) : null;
        $cleanUpMode    = ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP_MODE);
        $cleanUpDefault = ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP_DEFAULT);
        $cleanUp        =
            $cleanUpMode === ConfigManager::USER
                ? isset($data[self::CLEAN_UP]) && true === $data[self::CLEAN_UP]
                : true === $cleanUpDefault;
        $transition     = $data[self::TRANSITION];
        if ($this->isValid($transition)) {
            $hasText  = strlen($text);
            $isCommit = $transition == Review::STATE_APPROVED_COMMIT;
            // Get the original state in case we have to revert it
            $originalState = $this->review->getOriginalState();
            $this->review->setCommitStatus(null);
            // If the user has not supplied any text and its an approve/commit borrow the review description
            if ($isCommit && !$hasText) {
                $text = $this->review->getDescription();
            }
            // If a user has changed the state then they become a participant on the review
            $this->review = $this->review->addParticipant($user->getId());
            // approve/reject states will also up/down vote
            if (strpos($transition, Review::STATE_APPROVED) === 0 || $transition == Review::STATE_REJECTED) {
                $vote         = ($transition === Review::STATE_REJECTED)
                    ? Vote::VOTE_DOWN : Vote::VOTE_UP;
                $this->review = $this->review->addVote($user->getId(), $vote, $this->review->getHeadVersion());
            }

            if (strpos($transition, Review::STATE_APPROVED) === 0) {
                $this->review->approve($user->getId(), $this->review->getHeadVersion());
                // State change can only be allowed if all necessary approvals have been made
                if ($this->review->isStateAllowed(
                    $transition,
                    [
                        ConfigManager::MODERATOR_APPROVAL => ConfigManager::getValue(
                            $config,
                            ConfigManager::REVIEWS_MODERATOR_APPROVAL
                        )
                    ]
                )) {
                    $this->review = $this->review->setState($transition);
                }
            } else {
                $this->review = $this->review->setState($transition);
            }
            // if we received text for a non-commit add a comment
            if ($hasText) {
                if ($isCommit) {
                    $this->review = $this->review->setDescription($text);
                } else {
                    $comment = new Comment($p4Admin);
                    $comment->set(
                        [
                            'topic'   => 'reviews/' . $this->review->getId(),
                            'user'    => $user->getId(),
                            'context' => ['review' => $this->review->getId()],
                            'body'    => $text
                        ]
                    )->save();
                    $queue = $this->services->get(Manager::SERVICE);
                    // push comment into queue for possible further processing
                    // note: we pass 'quiet' so that no activity is created and no mail is sent.
                    $queue->addTask(
                        ListenerFactory::COMMENT,
                        $comment->getId(),
                        ['quiet' => true, 'current' => $comment->get()]
                    );
                }
            }
            $reviewDao->updateDescription($this->review, $data, $user);
            $this->review = $reviewDao->save($this->review);
            // Carry on with commit specific tasks
            if ($isCommit) {
                // large changes can take a while to commit
                $commitTimeout = ConfigManager::getValue($config, ConfigManager::REVIEWS_COMMIT_TIMEOUT, 1800);
                ini_set('max_execution_time', $commitTimeout);
                $p4User       = $this->services->get(ConnectionFactory::P4_USER);
                $creditAuthor = ConfigManager::getValue($config, ConfigManager::REVIEWS_COMMIT_CREDIT_AUTHOR, true);
                if ($jobs === null) {
                    // jobs were not provided, check to see if any existing jobs should be carried over to the commit
                    $jobs = $p4User->run('fixes', ['-c', $this->review->getId()])->getData();
                    $jobs = array_map(
                        function ($value) {
                            return $value['Job'];
                        },
                        $jobs
                    );
                }
                $lock = new Lock(Review::LOCK_CHANGE_PREFIX . $this->review->getId(), $p4Admin);
                try {
                    $lock->lock();
                    $this->review->commit(
                        [
                            Review::COMMIT_DESCRIPTION   => $text,
                            Review::COMMIT_JOBS          => is_array($jobs) ? $jobs : null,
                            Review::COMMIT_FIX_STATUS    => $fixStatus,
                            Review::COMMIT_CREDIT_AUTHOR => $creditAuthor
                        ],
                        $p4User
                    );
                    if ($cleanUp) {
                        // Cleanup via the admin connection, might need super?
                        $logger->notice("Cleaning up the pending changelists.");
                        $this->review->cleanup(
                            ['reopen' => ConfigManager::getValue(
                                $config,
                                ConfigManager::REVIEWS_CLEANUP_REOPEN_FILES,
                                false
                            )],
                            $p4User
                        );
                    }
                } catch (ConflictException $e) {
                    $this->review = $reviewDao->revertState($this->review, $originalState);
                    throw $e;
                } catch (CommandException $e) {
                    $this->review  = $reviewDao->revertState($this->review, $originalState);
                    $message       = $e->getMessage();
                    $noJobPattern  = "/(Job '[^']*' doesn't exist.)/s";
                    $jobFixPattern = "/(Job fix status must be one of [^\.]*\.)/s";
                    if (preg_match($noJobPattern, $e->getMessage(), $matches) ||
                        preg_match($jobFixPattern, $e->getMessage(), $matches)) {
                        $message = $matches[1];
                    }
                    throw new CommandException($message, $e->getCode(), $e->getPrevious());
                } finally {
                    $lock->unlock();
                }
            }
        } else {
            throw new ValidatorException($this);
        }
        return $this->review;
    }
}
