<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Model;

use Api\IRequest;
use Application\Config\IDao;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Config\ConfigException;
use Application\Connection\ConnectionFactory;
use Application\Filter\FilterException;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\AbstractDAO;
use Application\Model\IModelDAO;
use Application\Option;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Permissions;
use Application\Permissions\PrivateProjects;
use Application\Permissions\RestrictedChanges;
use Application\Permissions\Reviews;
use Events\Listener\ListenerFactory;
use Files\View\Helper\DecodeSpec;
use Groups\Model\Group;
use InvalidArgumentException;
use Groups\Model\Config as GroupConfig;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Exception as P4Exception;
use P4\Model\Fielded\Iterator;
use P4\Model\Connected\Iterator as ConnectedIterator;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Projects\Model\Project as ProjectModel;
use Queue\Manager;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Record\Lock\Lock;
use Reviews\Filter\GitInfo;
use Reviews\Filter\IAppendReplaceChange;
use Reviews\Filter\IProjectsForUser;
use Reviews\Filter\Keywords;
use Reviews\Listener\IReviewTask;
use Reviews\Model\Review as ReviewModel;
use Reviews\Filter\IParticipants;
use Reviews\UpdateService;
use TagProcessor\Service\IWip;
use Users\Filter\User as UserFilter;
use Users\Validator\Users;
use Reviews\ITransition;
use Reviews\Validator\Transitions;
use Workflow\Model\IWorkflow;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\ValidatorChain;
use Queue\Manager as QueueManager;
use Laminas\Http\Request as HttpRequest;
use Exception;

class ReviewDAO extends AbstractDAO implements ITransition, IReviewTask
{
    // The Perforce class that handles review
    const MODEL = ReviewModel::class;
    // Version is used in the DAO but is not actually a field on the model
    const VERSION           = 'version';
    const FETCH_MAX_DEFAULT = 50;

    /**
     * @inheritDoc
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     */
    public function fetch($id, ConnectionInterface $connection = null)
    {
        $review = ReviewModel::fetch($id, $this->getConnection($connection));
        // checkAccess will result in a ForbiddenException if no access any projects on the review is detected.
        // This must be done before filterProjects so that it has projects to check
        $this->checkAccess($review);
        return $this->filterProjects($review);
    }

    /**
     * @inheritDoc
     * It filters restricted reviews for current user and also filters out
     * private projects which current user doesn't have access to
     * @throws Exception
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        $options[AbstractKey::FETCH_TOTAL_COUNT] = true;
        $options[IReview::FETCH_MAX]             = $this->getFetchMaxOption($options);
        // hasVoted/myComments filters are privileged so you cannot see them when not logged in.
        // However bookmarks or manual URL typing may still have the parameter so we
        // check and ignore if not logged in.
        $options = $this->prepareOptionsByUser($options, $connection);
        $options = $this->prepareFilterMaxOptions($options);
        $options = $this->prepareOptionFetchKeywordsFields($options);

        // eliminate blank values if any to avoid potential side effects
        $options =  array_filter(
            $options,
            function ($value) {
                return is_array($value) ? count($value) : strlen($value);
            }
        );
        $reviews = ReviewModel::fetchAll($options, $this->getConnection($connection));
        if ($options[IRequest::RESULT_ORDER]??IReview::FIELD_CREATED === IReview::FIELD_UPDATED) {
            $reviews = $this->handleLastUpdatedSorting($reviews, $options);
        }
        // Grab the size before we remove any for restrictions
        $originalModelsSize = sizeof($reviews);

        // remove reviews that are restricted for the current user
        // we filter based on access to the most recent change
        $reviews = $this->services->get(RestrictedChanges::class)->filter($reviews, 'getHeadChange');
        // filter out private projects the current user doesn't have access to
        $reviews = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($reviews, 'projects');

        // We may have removed some for restrictions due to changes/projects, get the new size
        $restrictedModelsSize = sizeof($reviews);

        // Reviews may have been removed because of access restrictions - we do not want to
        // show a count that indicates there were some reviews that have been removed from the
        // results.
        $totalCount = $reviews->getProperty(AbstractKey::FETCH_TOTAL_COUNT);
        $sizeDiff   = $originalModelsSize - $restrictedModelsSize;
        $reviews->setProperty(AbstractKey::FETCH_TOTAL_COUNT, $totalCount - $sizeDiff);

        // set lastSeen property to zero if does not exist.
        $lastSeen = $reviews->hasProperty(AbstractKey::LAST_SEEN)
                        ? $reviews->getProperty(AbstractKey::LAST_SEEN)
                        : 0;
        $reviews->setProperty(AbstractKey::LAST_SEEN, $lastSeen);

        return $reviews;
    }

    /**
     * Handle sorting by last updated date. This function is heavily based on the original in the
     * IndexController->indexAction, but takes account of the new query parameter set and replaces
     * the sorting within reviews.js
     *
     * Firstly a dataset is acquired for sorting:
     *   - if there is less data than requested, no further queries are needed
     *   - where there is more data than requested get the data for a single day
     *
     * Secondly, the dataset is sorted into date order and limited to max_filter reviews if necessary,
     * the id of the last reviews seen and the oldest updated date are also returned
     *
     * @param mixed             $models         review models
     * @param array             $options        fetch options
     * @return Iterator over models
     * @throws Exception
     */
    protected function handleLastUpdatedSorting($models, array $options)
    {
        $logger      = $this->services->get(SwarmLogger::SERVICE);
        $p4Admin     = $this->services->get(ConnectionFactory::P4_ADMIN);
        $lastUpdated = null;
        $lastSorted  = null;
        $step        = 86400;

        // If filter-max was reached, take a closer look at the data
        $filterMax = $options[Review::FETCH_MAX];
        if (count($models) >= $options[Review::FETCH_MAX]) {
            $lastUpdated = $options[Review::FETCH_AFTER_UPDATED]?? time();
            $lastSorted  = $options[Review::FETCH_AFTER_SORTED]?? null;
            // Limit to a single day, rather than using maximums
            unset($options[Review::FETCH_MAX]);
            unset($options[Review::FETCH_MAXIMUM]);
            unset($options[Review::FETCH_AFTER]);
            // The search index is per day(86400 seconds), so discard part days
            $options[Review::ORDER_BY_UPDATED] = "".($lastUpdated-($lastUpdated%$step));
            // Remember the original query result fields, to keep totalCount
            $originalProperties = $models->getProperties();

            $models     = Review::fetchAll($options, $p4Admin);
            $modelCount = count($models);
            $logger->debug("Found " . $modelCount . " for $lastUpdated"."(".date('Y-m-d', $lastUpdated).")");
            if (0 === $modelCount) {
                // There was no data for the requested day, go back one day next time
                $lastUpdated                                     = $lastUpdated - $step;
                $originalProperties[Review::FETCH_AFTER_UPDATED] = $lastUpdated;
                $originalProperties[Review::LAST_SEEN]           = $lastSorted;
            }
            $models->setProperties($originalProperties);
        }

        // Sort the data by last updated, limiting to filter-max±lastUpdated
        $modelCount = count($models);
        if ($modelCount > 0) {
            $models->sortBy(["updated", "id"], [Iterator::SORT_DESCENDING, Iterator::SORT_NUMERIC]);
            // Limit the data returned
            if ($modelCount > $filterMax) {
                // There were more than max, take up to filter-max newer than the `after` review
                $resultArray = $models->getArrayCopy();
                $spaceLeft   = $filterMax;
                $properties  = $models->getProperties();
                $models      = new Iterator();
                $models->setProperties($properties);
                // Rebuild the result set, making sure that all for a given day are included
                $populate = ! $lastSorted;
                foreach ($resultArray as $key => $model) {
                    if ($populate) {
                        if ($spaceLeft-- > 0) {
                            $models[$key] = $model;
                        } else {
                            break;
                        }
                    } elseif ("$key" === $lastSorted) {
                        // Processing has reached the last review in the previous response
                        $populate = true;
                    }
                }
                $lastReview = $models->last();
                // lastReview will be false when the previous request supplied the last review for a given date
                if ($lastReview) {
                    $models->setProperty(Review::LAST_SEEN, $lastReview->getId());
                    $models->setProperty(Review::FETCH_AFTER_UPDATED, $lastReview->get(IReview::FIELD_UPDATED));
                } else {
                    $models->setProperty(Review::LAST_SEEN, null);
                    $models->setProperty(Review::FETCH_AFTER_UPDATED, $lastUpdated - $step);
                }
            } else {
                $lastReview = $models->last();
                $models->setProperty(Review::LAST_SEEN, null);
                $models->setProperty(Review::FETCH_AFTER_UPDATED,  $lastReview->get(IReview::FIELD_UPDATED)-$step);
            }
        } else {
            $models->setProperty(Review::FETCH_AFTER_UPDATED,  $lastUpdated);
        }
        return $models;
    }
    /**
     * Get metadata for all the models.
     * @param mixed         $model      the review model
     * @param array|null    $options    metadata options. Supports:
     *      IReview::FIELD_COMMENTS     summary of open closed counts
     *      IReview::FIELD_UP_VOTES     up vote user ids
     *      IReview::FIELD_DOWN_VOTES   down vote user ids
     * @return array an array with a metadata element for the model according to the options provided. If options are
     * null all metadata is returned. For example
     *          'metadata' => [
     *              'comments' => [1, 1],
     *              'upVotes' => ["user1", "user2"],
     *              'downVotes' => ["user3"]
     *          ],
     *          ...
     */
    public function fetchMetadata($model, array $options = null)
    {
        $metadata = $this->fetchAllMetadata(new Iterator([$model]), $options);
        return empty($metadata) ? [] : $metadata[0];
    }

    /**
     * Fetch metadata for all the models.
     * @param mixed         $models     iterator of models
     * @param array|null    $options    metadata options. Supports:
     *      IReview::FIELD_COMMENTS     summary of open closed counts
     *      IReview::FIELD_UP_VOTES     up vote user ids
     *      IReview::FIELD_DOWN_VOTES   down vote user ids
     * @return array an array with a metadata element for each model according to the options provided. If options are
     * null all metadata is returned. For example
     *      [
     *          [
     *              'metadata' => [
     *                  'comments' => [1, 1],
     *                  'upVotes' => ["user1", "user2"],
     *                  'downVotes' => ["user3"]
     *              ],
     *          ]
     *          ...
     *      ]
     * The returned values make it easy to merge in a metadata element when converting review models for output
     */
    public function fetchAllMetadata($models, array $options = null)
    {
        if ($options === null) {
            // If options are null assume all metadata is requested
            $options = [
                IReview::FIELD_COMMENTS => true,
                IReview::FIELD_UP_VOTES => true,
                IReview::FIELD_DOWN_VOTES => true
            ];
        }
        if (isset($options[IReview::FIELD_COMMENTS])) {
            $p4Admin       = $this->services->get(ConnectionFactory::P4_ADMIN);
            $commentDao    = $this->services->get(IDao::COMMENT_DAO);
            $commentCounts = $commentDao->countByTopic($models->invoke('getTopic'), $p4Admin);
        }
        $metadata = [];
        if (!empty($options)) {
            foreach ($models as $review) {
                $modelMetadata = [];
                if (isset($options[IReview::FIELD_COMMENTS])) {
                    $topic = $review->getTopic();

                    $modelMetadata[IRequest::METADATA][IReview::FIELD_COMMENTS] =
                        isset($commentCounts[$topic]) ? $commentCounts[$topic] : [0, 0];
                }
                if (isset($options[IReview::FIELD_UP_VOTES])) {
                    $modelMetadata[IRequest::METADATA][IReview::FIELD_UP_VOTES] = array_keys($review->getUpVotes());
                }
                if (isset($options[IReview::FIELD_DOWN_VOTES])) {
                    $modelMetadata[IRequest::METADATA][IReview::FIELD_DOWN_VOTES] = array_keys($review->getDownVotes());
                }
                $metadata[] = $modelMetadata;
            }
        }
        return $metadata;
    }

    /**
     * Fetches a review base on keywords from value using the standard Swarm definition (for example #review-123) etc.
     * @param string    $value      value containing the keywords to find the review
     * @param ConnectionInterface|null $connection
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     * @return mixed the review if keywords are present and the review is found, null if there are no keywords to find a
     * review, and a RecordNotFoundException if the keywords specify a review that does not exist
     */
    public function fetchFromKeyword($value, ConnectionInterface $connection = null)
    {
        $keywords = $this->services->get(Keywords::SERVICE);
        $matches  = $keywords->getMatches($value);
        if ($matches && isset($matches['id']) && !empty($matches['id'])) {
            return $this->fetch($matches['id'], $connection);
        }
        return null;
    }

    /**
     * Filter private projects from the review if there are projects set on the review
     * @param mixed     $review     the review
     * @return mixed the review with private projects removed
     */
    public function filterProjects($review)
    {
        $projects = $review->getProjects();
        $retVal   = $review;
        if ($projects) {
            $retVal = $review->setProjects(
                $this->services->get(PrivateProjects::PROJECTS_FILTER)->filterList($projects)
            );
        }
        return $retVal;
    }

    /**
     * Wrapping the obliterate function of review model
     *
     * @param   mixed   $review            Review Object.
     * @param   bool    $removeChangelist  True, will remove the pending changelist.
     * @return  array
     * @throws  Exception
     */
    public function obliterate(ReviewModel $review, $removeChangelist = true)
    {
        return $review->obliterate($removeChangelist);
    }

    /**
     * Change the author of a review to a new value.
     * The change will only be effected if:
     *  The Swarm config allows author changes to be made
     *  The review exists
     *  The current user is authorised to update this review
     *  The new author is a valid user
     * @param $reviewId
     * @param $data
     * @return mixed
     * @throws ConfigException
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     */
    public function changeAuthor($reviewId, $data)
    {
        $services = $this->services;
        $config   = $services->get(ConfigManager::CONFIG);
        if (!ConfigManager::getValue($config, ConfigManager::REVIEWS_ALLOW_AUTHOR_CHANGE)) {
            // Allow author change is not enabled
            throw new \InvalidArgumentException(
                $services->get(TranslatorFactory::SERVICE)->t(
                    "Review author cannot be changed, please set 'allow_author_change' to be true."
                )
            );
        }
        $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
        $review  = $this->fetch($reviewId, $p4Admin);
        $this->checkAccess($review);
        $userValidator = (new ValidatorChain())->attach(new NotEmpty())->attach(new Users(['connection' => $p4Admin]));
        $newAuthor     = $data[Review::FIELD_AUTHOR];
        if ($userValidator->isValid($newAuthor)) {
            $oldValues = $review->get();
            if ($this->hasChanged(Review::FIELD_AUTHOR, [Review::FIELD_AUTHOR => $newAuthor], $oldValues)) {
                // Use a filter to get the real user id from the spec. On a case insensitive server all cases are
                // valid authors but we want the value as it is in the spec
                $filter                     = new UserFilter($this->services);
                $data[Review::FIELD_AUTHOR] = $filter->filter($newAuthor);
                $review->set(Review::FIELD_AUTHOR, $data[Review::FIELD_AUTHOR]);
                $review = $this->save($review);
                $this->queueTask($review, [self::IS_AUTHOR_CHANGE => true], $oldValues);
            }
        } else {
            $messages = $userValidator->getMessages();
            throw new \InvalidArgumentException(implode(', ', array_values($messages)));
        }
        return $data;
    }

    /**
     * @param mixed $review
     * @throws ForbiddenException
     */
    public function checkAccess($review)
    {
        if (!$this->services->get(Reviews::REVIEWS_FILTER)->canAccessChangesAndProjects($review)) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            throw new ForbiddenException(
                $translator->t('You do not have permission to access this review.')
            );
        }
    }

    /**
     * Vote on a review
     * @param mixed         $reviewId   the review id
     * @param mixed         $userId     the user id
     * @param array         $data       data, for example ['vote' => 'up']
     * @return array
     * @throws ConfigException
     * @throws FilterException
     * @throws ForbiddenException
     * @throws P4Exception
     * @throws RecordNotFoundException
     * @throws SpecNotFoundException
     */
    public function vote($reviewId, $userId, $data)
    {
        $review = $this->fetch($reviewId, $this->services->get(ConnectionFactory::P4_ADMIN));
        $this->checkAccess($review);
        $filter = $this->services->get(Services::VOTE_INPUT_FILTER);
        $filter->setData($data);
        if ($filter->isValid()) {
            $version     = isset($data[self::VERSION]) ? $data[self::VERSION] : null;
            $headVersion = $review->getHeadVersion();
            if ($version && (int)$version !== (int)$headVersion) {
                $translator = $this->services->get(TranslatorFactory::SERVICE);
                throw new InvalidArgumentException(
                    $translator->t(
                        'Version [%s] is not valid, must equal the head revision [%s].',
                        [
                            $version,
                            $headVersion
                        ]
                    )
                );
            }
            $oldValues = $review->get();
            $data      = $filter->getValues();
            $review    = $review->addParticipant($userId)
                ->addVote($userId, $data[ReviewModel::FIELD_VOTE], $headVersion);
            if ($this->canApprove($review)) {
                $review = $review->setState(ReviewModel::STATE_APPROVED);
            }
            $review = $this->save($review);
            $this->queueTask($review, [self::IS_VOTE => $data[ReviewModel::FIELD_VOTE]], $oldValues);
        } else {
            throw new FilterException($filter);
        }
        return [Review::FIELD_VOTE=>$review->getVotes()[$userId]??[]];
    }

    /**
     * Tests if a review can be approved by looking at moderators and workflow rules
     * @param ReviewModel $review the review
     * @return bool true if the review is ready to be approved
     * @throws P4Exception
     * @throws SpecNotFoundException
     * @throws RecordNotFoundException
     */
    public function canApprove(ReviewModel $review)
    {
        $canApprove = false;
        $p4admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $moderators = false;
        $affected   = $review->getProjects();
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $projects   = $projectDAO->fetchAll(
            [ProjectModel::FETCH_BY_IDS => array_keys($affected)],
            $p4admin
        );
        foreach ($projects as $project) {
            if (count($project->getModerators($affected[$project->getId()])) > 0) {
                // We have at least one moderator
                $moderators = true;
                break;
            }
        }
        if (!$moderators) {
            $workflowsEnabled =
                $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER_RETURN);
            if ($workflowsEnabled) {
                $workflowManager = $this->services->get(Services::WORKFLOW_MANAGER);
                // There are no moderators, look for a workflow with auto-approve = 'votes'
                $autoApproveRule = $workflowManager->getBranchRule(
                    IWorkflow::AUTO_APPROVE,
                    $affected,
                    $projects
                );
                $branchRules     = [];
                if ($autoApproveRule === IWorkflow::VOTES &&
                    $review->canApprove([], $affected, $workflowsEnabled, $branchRules)) {
                    $canApprove = true;
                }
            }
        }
        return $canApprove;
    }

    /**
     * Get allowed transitions for the review
     * The change will only be effected if:
     *  The review exists
     *  The user (if provided) is allowed the access the review. If the user is anonymous (permitted) this check will
     *  still prevent access if private projects on the review are encountered
     * @param mixed     $reviewId   the review id
     * @param mixed     $userId     the user id
     * @return array|false transitions keyed on transition id with a value of transition description, or false if
     * there are impacted projects but the requester is not a member/author/super
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     */
    public function getTransitions($reviewId, $userId)
    {
        $review = $this->fetch($reviewId, $this->services->get(ConnectionFactory::P4_ADMIN));
        $this->checkAccess($review);
        $options     = [
            Option::USER_ID           => $userId,
            Transitions::OPT_UP_VOTES => $userId ? [$userId] : [],
            Transitions::REVIEW       => $review
        ];
        $transitions = $this->services->build(Services::TRANSITIONS, $options);
        return $transitions->getAllowedTransitions();
    }

    /**
     * Transition the review with the requested data
     * @param mixed         $reviewId   the review id
     * @param string        $userId     the user id
     * @param array         $data       the data
     * @see Transitions::transition()
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     * @return array data with review state and state label
     */
    public function transition($reviewId, $userId, $data)
    {
        $review = $this->fetch($reviewId, $this->services->get(ConnectionFactory::P4_ADMIN));
        $this->checkAccess($review);
        $options   = [
            Option::USER_ID => $userId,
            Transitions::OPT_UP_VOTES => [$userId],
            Transitions::REVIEW => $review
        ];
        $validator = $this->services->build(Services::TRANSITIONS, $options);
        if (isset($data[self::TRANSITION])) {
            $oldValues = $review->get();
            $review    = $validator->transition($data);
            $this->queueTask($review, [self::IS_STATE_CHANGE => true], $oldValues);
        } else {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            throw new InvalidArgumentException($translator->t('No transition state provided'));
        }
        return [
            ReviewModel::FIELD_STATE => $review->getState(),
            ReviewModel::FIELD_STATE_LABEL => $review->getStateLabel()
        ];
    }

    /**
     * Function to work out if a fields have changed
     * @param string $field the review field
     * @param array $values current values
     * @param array $oldValues old values
     * @return bool true if changed
     */
    public function hasChanged(string $field, array $values, array $oldValues)
    {
        if (!array_key_exists($field, $values)) {
            return false;
        }
        $oldValues += [
            ReviewModel::FIELD_STATE         => null,
            ReviewModel::FIELD_AUTHOR        => null,
            ReviewModel::FIELD_TEST_STATUS   => null,
            ReviewModel::FIELD_DEPLOY_STATUS => null,
            ReviewModel::FIELD_DESCRIPTION   => null
        ];

        $newValue = $values[$field];
        $oldValue = isset($oldValues[$field]) ? $oldValues[$field] : null;
        // 'approved' to 'approved:commit' is not considered a state change
        if ($field === ReviewModel::FIELD_STATE && $newValue === ReviewModel::STATE_APPROVED_COMMIT) {
            $newValue = ReviewModel::STATE_APPROVED;
        }
        return $newValue != $oldValue;
    }

    /**
     * Revert a review to the values from the original state
     * @param ReviewModel       $review         the review
     * @param string            $originalState  original state
     * @return mixed
     */
    // Created from the revert handling in Reviews->IndexController->revertState and relocated
    public function revertState(ReviewModel $review, string $originalState)
    {
        try {
            $review->setState($originalState);
            $this->save($review);
        } catch (Exception $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err(sprintf('Unable to revert review %s to state %s', $review->getId(), $originalState));
        }
        return $review;
    }

    /**
     * Handle updating description on the original changelist or git info
     * @param ReviewModel $review
     * @param $data
     * @param $user
     * @return Review
     * @throws P4Exception
     * @throws SpecNotFoundException
     */
    // Created from the description handling in Reviews->IndexController->editReview
    public function updateDescription(ReviewModel $review, $data, $user)
    {
        $p4 = $review->getConnection();
        $p4->getService('clients')->grab();
        $change = Change::fetchById($review->getId(), $p4);

        // if this is a git review update the description but keep the
        // existing git info (keys/values), otherwise just use the review
        // description as-is for the update
        $description = $review->getDescription();
        if ($review->getType() === 'git') {
            $gitInfo     = new GitInfo($change->getDescription());
            $description = $gitInfo->setDescription($description)->getValue();
        }
        $changeDao = $this->services->get(IDao::CHANGE_DAO);
        $change    = $change->setDescription($description);
        $changeDao->save($change, true);
        $changeDao->updateOriginalChangelist($data, $review, $description, $user, $p4);
        $p4->getService('clients')->release();
        return $review;
    }

    /**
     * The change will only be effected if:
     *  The review exists
     *  The user is allowed the access the review.
     * @param mixed     $reviewId   the review id
     * @param array     $data       the data containing the description in $data[ReviewModel::FIELD_DESCRIPTION]
     * @throws ForbiddenException
     * @throws InvalidArgumentException
     * @throws P4Exception
     * @throws SpecNotFoundException
     * @throws RecordNotFoundException
     * @return array
     */
    public function setDescription($reviewId, $data)
    {
        $review = $this->fetch($reviewId, $this->services->get(ConnectionFactory::P4_ADMIN));
        $this->checkAccess($review);
        if (isset($data[ReviewModel::FIELD_DESCRIPTION])) {
            if ($this->hasChanged(
                ReviewModel::FIELD_DESCRIPTION,
                [ReviewModel::FIELD_DESCRIPTION => $data[ReviewModel::FIELD_DESCRIPTION]],
                [$review->getDescription()]
            )) {
                $oldValues = $review->get();
                $review    = $review->setDescription($data[ReviewModel::FIELD_DESCRIPTION]);
                $review    = $this->save($review);
                $this->updateDescription($review, $data, $this->services->get(ConnectionFactory::USER));
                $this->queueTask(
                    $review,
                    [ReviewModel::FIELD_DESCRIPTION => $review->getDescription(), self::IS_DESCRIPTION_CHANGE => true],
                    $oldValues
                );
            }
        } else {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            throw new InvalidArgumentException($translator->t('No description provided'));
        }
        return [ReviewModel::FIELD_DESCRIPTION => $review->getDescription()];
    }

    /**
     * It will take the review id check if review exist and perform validation on the participant
     * input data and update the participant data in review according to request.
     * @param mixed         $reviewId   the review id
     * @param array         $data       values from the request
     * @param array         $request    values from the request
     * @param boolean       $canEdit    if true edit permissions will be assumed, otherwise they
     *                                  will be assessed according to permissions/projects etc.
     * @return array
     * @throws ForbiddenException
     * @throws Exception
     */
    public function updateParticipants($reviewId, $data, $request, $canEdit = false) : array
    {
        $review = $this->fetch($reviewId, $this->services->get(ConnectionFactory::P4_ADMIN));
        $old    = $review->get();
        $this->checkAccess($review);
        $canEditReviewers         = $canEdit || $this->canEditReviewers($review);
        $messages                 = [];
        $defaultRetainedReviewers = [];
        $method                   = $request->getMethod();
        $projects                 = $review->getProjects();
        $p4Admin                  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $defaultRetainedReviewers = UpdateService::mergeDefaultReviewersForProjects(
            $projects,
            $defaultRetainedReviewers,
            $p4Admin,
            [UpdateService::ALWAYS_ADD_DEFAULT => false]
        );
        if (!$canEditReviewers) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            throw new ForbiddenException($translator->t('You do not have permission to edit reviewers.'));
        }
        $options                                 = [
            IParticipants::REVIEW => $review,
            IParticipants::VALIDATE_IDS => $method !== HttpRequest::METHOD_DELETE
        ];
        $filter                                  = $this->services->build(IParticipants::PARTICIPANTS, $options);
        $combinedReviewers                       = $filter->getCombinedReviewers($data, $method);
        $data[IParticipants::COMBINED_REVIEWERS] = $combinedReviewers;
        $filter->setData($data);
        // indicate that we want to validate only fields present in $data
        $filter->setValidationGroupSafe(array_keys($data));
        if ($filter->isValid()) {
            $filtered = $filter->getValues();
            if ($method === HttpRequest::METHOD_DELETE) {
                $reviewerArrayToDelete = [];
                foreach ($filtered['combinedReviewers'] as $reviewer => $option) {
                    if (!array_key_exists($reviewer, $defaultRetainedReviewers)) {
                        $reviewerArrayToDelete[$reviewer][] = $option;
                    }
                }
                $reviewerArrayToSave =  array_diff_key($review->getParticipantsData(), $reviewerArrayToDelete);
                $review->setParticipantsData($reviewerArrayToSave);
            } else {
                $review->setParticipantsData($combinedReviewers);
            }
            $this->save($review);
        } else {
            $isRetainedReviewersError =  $filter->hasRetainedReviewersError();
            $retainReviewers          = $filter->hasRetainedReviewers();
            if ($isRetainedReviewersError) {
                $messages = $filter->getMessages();
                $values   = $filter->getValues();
                if (count($messages['combinedReviewers']) != count($retainReviewers)) {
                    throw new FilterException($filter);
                } else {
                    if ($method === HttpRequest::METHOD_DELETE) {
                        $reviewerArrayToDelete = [];
                        // removed all the default retain reviewer from delete
                        foreach ($values['combinedReviewers'] as $reviewer => $option) {
                            if (!array_key_exists($reviewer, $defaultRetainedReviewers)) {
                                $reviewerArrayToDelete[$reviewer][] = $option;
                            }
                        }
                        $reviewerArrayToSave =  array_diff_key($review->getParticipantsData(), $reviewerArrayToDelete);
                        $review->setParticipantsData($reviewerArrayToSave);
                        $this->save($review);
                    } elseif ($method === HttpRequest::METHOD_PUT || $method === HttpRequest::METHOD_POST) {
                        $reviewerArrayToPut = [];
                        // add all the default retain reviewer which are present in array
                        // so that only those retained reviewer will update which are upgrading
                        foreach ($defaultRetainedReviewers as $reviewer => $option) {
                            if (in_array($reviewer, $retainReviewers)) {
                                $values['combinedReviewers'][$reviewer] = $option;
                            }
                        }
                        foreach ($values['combinedReviewers'] as $reviewer => $option) {
                            if (isset($option[IParticipants::REQUIRED]) && empty($option[IParticipants::REQUIRED])) {
                                unset($option[IParticipants::REQUIRED]);
                            }
                            $reviewerArrayToPut[$reviewer] = $option;
                        }
                        foreach ($review->getParticipantsData() as $participant => $participantData) {
                            // Before we update participants data using put we need to preserve any fields
                            // already set (for example 'vote' and 'notificationsDisabled' etc)
                            foreach ($participantData as $participantField => $fieldValue) {
                                if ($participantField !== IParticipants::REQUIRED
                                    && $participantField !== ReviewModel::FIELD_MINIMUM_REQUIRED) {
                                    $reviewerArrayToPut[$participant][$participantField] = $fieldValue;
                                }
                            }
                        }
                        $review->setParticipantsData($reviewerArrayToPut);
                        $this->save($review);
                    }
                }
            } else {
                throw new FilterException($filter);
            }
        }
        $isReviewersChange = $old['participantsData'] != $review->getParticipantsData();
        if ($isReviewersChange) {
            $this->queueTask($review, [self::IS_REVIEWERS_CHANGE => true], $old);
        }
        return [$review, $messages];
    }

    /**
     * Format the input data to the required format so that it can be validated
     * from the ReviewFilter
     * @param $data
     * @return array
     * @throws \InvalidArgumentException
     * Sample Input
     * Array
     * (
     *      [participants] => Array
     *      (
     *          [users] => Array
     *          (
     *             [testUser] => Array([required] => yes)
     *          )
     *          [groups] => Array
     *          (
     *             [mygroup] => Array([required] => all)
     *          )
     *       )
     * )
     * Sample Output
     * Array
     * (
     *      [reviewers] => Array
     *          (
     *              [0] => testUser
     *              [1] => swarm-group-mygroup
     *          )
     *      [requiredReviewers] => Array
     *          (
     *              [0] => testUser
     *              [1] => swarm-group-mygroup
     *          )
     *      [reviewerQuorum] => Array
     *          ()
     * )
     */
    public function convertParticipantData($data)
    {
        $formatArray = [
            Review::REVIEWERS => [],
            Review::REQUIRED_REVIEWERS => [],
            Review::REVIEWER_QUORUMS => [],
        ];
        if (isset($data[IParticipants::PARTICIPANTS][IParticipants::USERS])) {
            $users = $data[IParticipants::PARTICIPANTS][IParticipants::USERS];
            foreach ($users as $key => $value) {
                $required = isset($value[IParticipants::REQUIRED]) ?
                    $value[IParticipants::REQUIRED] : IParticipants::NO;
                if ($required === IParticipants::YES) {
                    array_push($formatArray[Review::REVIEWERS], $key);
                    array_push($formatArray[Review::REQUIRED_REVIEWERS], $key);
                } elseif ($required === IParticipants::NO) {
                    array_push($formatArray[Review::REVIEWERS], $key);
                } else {
                    $translator = $this->services->get(TranslatorFactory::SERVICE);
                    throw new InvalidArgumentException(
                        $translator->t(
                            "'%s' must have a required value '%s' or '%s'",
                            [$key, IParticipants::YES, IParticipants::NO]
                        )
                    );
                }
            }
        }
        if (isset($data[IParticipants::PARTICIPANTS][IParticipants::GROUPS])) {
            $groups = $data[IParticipants::PARTICIPANTS][IParticipants::GROUPS];
            foreach ($groups as $key => $value) {
                    $required  = isset($value[IParticipants::REQUIRED]) ?
                        $value[IParticipants::REQUIRED] : IParticipants::NONE;
                    $groupName = GroupConfig::KEY_PREFIX .$key;
                if ($required === IParticipants::ONE) {
                    array_push($formatArray[Review::REVIEWERS], $groupName);
                    array_push($formatArray[Review::REQUIRED_REVIEWERS], $groupName);
                    $formatArray[Review::REVIEWER_QUORUMS][$groupName] = "1";
                } elseif ($required === IParticipants::ALL) {
                    array_push($formatArray[Review::REVIEWERS], $groupName);
                    array_push($formatArray[Review::REQUIRED_REVIEWERS], $groupName);
                } elseif ($required === IParticipants::NONE) {
                    array_push($formatArray[Review::REVIEWERS], $groupName);
                } else {
                    $translator = $this->services->get(TranslatorFactory::SERVICE);
                    throw new InvalidArgumentException(
                        $translator->t(
                            "'%s' must have a required value '%s' or '%s' or '%s'",
                            [$key, IParticipants::ALL, IParticipants::ONE, IParticipants::NONE,]
                        )
                    );
                }
            }
        }
        return $formatArray;
    }

    /**
     * Queue a review task with a type of 'review', an id from from the review and the data from the data parameter.
     * @param mixed         $review     the review
     * @param array|null    $data       data for the task. Defaults for the data are provided, callers can provide their
     * own to supplement/override. Defaults if not provided:
     * [
     *      'user'                => $this->services->get(ConnectionFactory::USER)->getId(),
     *      'isStateChange'       => $this->hasChanged(ReviewModel::FIELD_STATE, $values, $oldValues),
     *      'isAuthorChange'      => $this->hasChanged(ReviewModel::FIELD_AUTHOR, $values, $oldValues),
     *      'isVote'              => false,
     *      'isReviewersChange'   => false,
     *      'isDescriptionChange' => $this->hasChanged(ReviewModel::FIELD_DESCRIPTION, $values, $oldValues),
     *      'testStatus'          => null
     *      'deployStatus'        => null,
     *      'description'         => null,
     *      'previous'            => $oldValues,
     *      'quiet'               => false
     * ]
     * @param array|null    $oldValues  old values from the review
     * @return mixed|void
     */
    // Create to provide similar behaviour to the task queued in Reviews->IndexController->editReview for use when
    // v10 APIs result in data changing
    public function queueTask($review, $data = null, $oldValues = null)
    {
        $dataKeys  = array_keys($data);
        $oldValues = $oldValues ? $oldValues : [];
        $values    = $review->toArray();
        $data     += [
            ConnectionFactory::USER          =>
                in_array(ConnectionFactory::USER, $dataKeys)
                    ? $data[ConnectionFactory::USER]
                    : $this->services->get(ConnectionFactory::USER)->getId(),
            self::IS_STATE_CHANGE            =>
                in_array(self::IS_STATE_CHANGE, $dataKeys)
                    ? $data[self::IS_STATE_CHANGE]
                    : $this->hasChanged(ReviewModel::FIELD_STATE, $values, $oldValues),
            self::IS_AUTHOR_CHANGE           =>
                in_array(self::IS_AUTHOR_CHANGE, $dataKeys)
                    ? $data[self::IS_AUTHOR_CHANGE]
                    : $this->hasChanged(ReviewModel::FIELD_AUTHOR, $values, $oldValues),
            self::IS_VOTE                    => false,
            self::IS_REVIEWERS_CHANGE        => false,
            self::IS_DESCRIPTION_CHANGE      =>
                in_array(self::IS_DESCRIPTION_CHANGE, $dataKeys)
                    ? $data[self::IS_DESCRIPTION_CHANGE]
                    : $this->hasChanged(ReviewModel::FIELD_DESCRIPTION, $values, $oldValues),
            ReviewModel::FIELD_TEST_STATUS   => null,
            ReviewModel::FIELD_DEPLOY_STATUS => null,
            ReviewModel::FIELD_DESCRIPTION   => null,
            self::PREVIOUS                   => $oldValues,
            self::QUIET                      => false
        ];

        $queue = $this->services->get(QueueManager::SERVICE);
        $queue->addTask(ListenerFactory::REVIEW, $review->getId(), $data);
    }


    /**
     * Can the files be archived and downloaded.
     *
     * @param int $change the changelist id we want to fetch
     * @return bool
     */
    public function canArchive($change)
    {
        $archiver = $this->services->get(Services::ARCHIVER);
        if ($archiver->canArchive()) {
            $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
            $changeDAO  = $this->services->get(IModelDAO::CHANGE_DAO);
            $changelist = $changeDAO->fetchById($change, $p4Admin);
            $files      = $changelist->getFileData($changelist->isPending());
            if (count($files)) {
                foreach ($files as $file) {
                    if ($file[ DecodeSpec::TYPE ] !== DecodeSpec::STREAM) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
    /**
     * This method implements our access controls for who can/can-not edit the reviewers.
     * Note joining/leaving and adjusting your own required'ness is a separate operation
     * and not governed by this check.
     *
     * @param   ReviewModel  $review     review model
     * @return  bool    true if active user can edit the review, false otherwise
     * @throws Exception
     */
    public function canEditReviewers(ReviewModel $review)
    {
        $permissions = $this->services->get(Permissions::PERMISSIONS);

        // if you aren't authenticated you aren't allowed to edit
        if (!$permissions->is(Permissions::AUTHENTICATED)) {
            return false;
        }

        // if you are an admin, the author, or there aren't any
        // projects associated with this review, you can edit.
        $userId = $this->services->get(ConnectionFactory::USER)->getId();
        if ($permissions->is('admin')
            || $review->get('author') === $userId
            || !$review->getProjects()
        ) {
            return true;
        }

        // looks like this review impacts projects, let's figure
        // out who the involved members and moderators are.
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $members    = [];
        $moderators = [];
        $impacted   = $review->getProjects();
        $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
        $projects   = $projectDAO->fetchAll(['ids' => array_keys($impacted)], $p4Admin);
        foreach ($projects as $project) {
            $branches   = $impacted[$project->getId()];
            $moderators = array_merge($moderators, $project->getModerators($branches));
            $members    = array_merge($members,    $project->getAllMembers());
        }

        // if there are moderators, only they can edit.
        // if there aren't any moderators, then any members can edit.
        return $p4Admin->stringMatches($userId, $moderators ?: $members);
    }

    /**
     * Work out if the content of a review has changed. If this is the first revision it is always changed otherwise
     * a change service is used to determine any differences between the head change and the change on the previous
     * revision.
     * @param ReviewModel $review
     * @return bool
     * @throws Exception
     */
    public function hasContentChanged(ReviewModel $review)
    {
        $head = $review->getHeadVersion();
        if ($head === 1) {
            $changed = true;
        } else {
            $previousChange = $review->getChangeOfVersion($head - 1, true);
            $currentChange  = $review->getHeadChange(true);
            $changeService  = $this->services->get(Services::CHANGE_SERVICE);
            $p4Admin        = $this->services->get(ConnectionFactory::P4_ADMIN);
            $changed        = $changeService->hasContentChanged($p4Admin, $currentChange, $previousChange);
        }
        return $changed;
    }

    /**
     * Return review record without any permission and private project filter check.
     * @param int                       $id         id of the record
     * @param ConnectionInterface|null  $connection the connection
     * @return mixed
     * @throws RecordNotFoundException
     */
    public function fetchNoCheck($id, ConnectionInterface $connection = null)
    {
        return ReviewModel::fetch($id, $this->getConnection($connection));
    }

    /**
     * Return the fetch max option value if set else fallback to filter-max and then finally 50.
     * @param $options  array   array of options
     * @return integer
     * @throws Exception
     */
    protected function getFetchMaxOption(array $options)
    {
        if (isset($options[IReview::FETCH_MAX])) {
            return $options[IReview::FETCH_MAX];
        } else {
            $config = $this->services->get(ConfigManager::CONFIG);
            return ConfigManager::getValue($config, ConfigManager::REVIEWS_FILTERS_FILTER_MAX, self::FETCH_MAX_DEFAULT);
        }
    }

    /**
     * Return the fetch keywords fields option value if keywords is set.
     * @param $options  array   array of options
     * @return array
     */
    protected function prepareOptionFetchKeywordsFields(array $options) : array
    {
        // If keyword is set we search based on the below fields.
        if (isset($options[Review::FETCH_BY_KEYWORDS])) {
            if (!isset($options[AbstractKey::FETCH_KEYWORDS_FIELDS])) {
                $options[AbstractKey::FETCH_KEYWORDS_FIELDS] = [
                    Review::FIELD_PARTICIPANTS,
                    Review::FIELD_DESCRIPTION,
                    Review::FIELD_PROJECTS,
                    Review::FIELD_ID
                ];
            }
        }
        return $options;
    }

    /**
     * Return the modified options/query if user is logged in and filters are set.
     * @param $options  array   array of options
     * @param ConnectionInterface|null $connection
     * @return array
     * @throws Exception
     */
    private function prepareOptionsByUser(array $options, ConnectionInterface $connection)
    {
        if (isset($options[IReview::FETCH_BY_HAS_VOTED]) || isset($options[IReview::FETCH_BY_MY_COMMENTS])) {
            $logger   = $this->services->get(SwarmLogger::SERVICE);
            $userName = null;
            try {
                $userName = $this->services->get(ConnectionFactory::P4_USER)->getUser();
            } catch (Exception $ex) {
                $logger->debug('Ignoring filters that require log in.');
            }
            // If current logged in user is found, use this.
            if ($userName) {
                // Set the user context value to the user being used.
                $options[IReview::FETCH_BY_USER_CONTEXT] = $userName;
                $options[IReview::FETCH_BY_PARTICIPANTS] = $userName;
            }
        } else {
            // unsetting the hasVoted value as it is coming as null
            unset($options[IReview::FETCH_BY_HAS_VOTED]);
        }
        // If FETCH_BY_DIRECT_PARTICIPANTS is provided this should become the value for 'participants'
        // without any group expansion
        if (isset($options[IReview::FETCH_BY_DIRECT_PARTICIPANTS])) {
            $options[IReview::FETCH_BY_PARTICIPANTS] = $options[IReview::FETCH_BY_DIRECT_PARTICIPANTS];
            unset($options[IReview::FETCH_BY_DIRECT_PARTICIPANTS]);
        } else {
            $options = $this->addGroupsToFetch($options, $connection);
        }
        return $options;
    }

    /**
     * prepare fetch-max options for filter query.
     * @param $options  array   array of options
     * @return array
     * @throws Exception
     */
    private function prepareFilterMaxOptions(array $options)
    {
        // Supported tuning options
        $services         = $this->services;
        $config           = $services->get(ConfigManager::CONFIG);
        $postFetchFilters = [Review::FETCH_BY_HAS_VOTED, Review::FETCH_BY_MY_COMMENTS, Review::FETCH_AFTER_UPDATED];
        $configKeys       = [
            'name' => ConfigManager::FETCH_MAX,
            'option' => Review::FETCH_MAXIMUM,
            'value' => ConfigManager::getValue($config, ConfigManager::REVIEWS_FILTERS_FETCH_MAX, 50),
        ];
        foreach ($postFetchFilters as $filter) {
            // Look for and set(high water mark) any configured tuning
            if (isset(
                $config[ConfigManager::REVIEWS][ConfigManager::FILTERS][$filter][ConfigManager::FETCH_MAX]
            ) &&
                $config[ConfigManager::REVIEWS][ConfigManager::FILTERS][$filter][ConfigManager::FETCH_MAX]
                > $configKeys['value']) {
                $configKeys['value'] =
                $config[ConfigManager::REVIEWS][ConfigManager::FILTERS][$filter][ConfigManager::FETCH_MAX];
            }
        }
        // Copy the tuning into the data query options
        if ($configKeys['value'] !== null) {
            $options[$configKeys['option']] = $configKeys['value'];
        }
        return $options;
    }

    /**
     * Add groups to a participant query.
     * @param $options
     * @param $p4Admin
     * @return array
     */
    private function addGroupsToFetch($options, ConnectionInterface $p4Admin)
    {
        // We want to add groups if relevant to 'participants' or 'authorparticipants'
        // (only 1 of them will be set for any given query)
        $participantField = null;
        if (isset($options[Review::FIELD_PARTICIPANTS]) && !empty($options[Review::FIELD_PARTICIPANTS])) {
            $participantField = Review::FIELD_PARTICIPANTS;
        } elseif (isset($options[Review::FETCH_BY_AUTHOR_PARTICIPANTS])
            && !empty($options[Review::FETCH_BY_AUTHOR_PARTICIPANTS])) {
            $participantField = Review::FETCH_BY_AUTHOR_PARTICIPANTS;
        }
        $groupDAO = $this->services->get(IModelDAO::GROUP_DAO);
        if ($participantField) {
            if (!is_array($options[$participantField])) {
                $options[$participantField] = [$options[$participantField]];
            }
            foreach ($options[$participantField] as $participant) {
                // Just in case that in future a participant query list might already contain a
                // group name check here before checking for group members
                if (!Group::isGroupName($participant)) {
                    $groups = $groupDAO->fetchAll(
                        [
                            Group::FETCH_BY_USER  => $participant,
                            Group::FETCH_INDIRECT => true
                        ],
                        $p4Admin
                    );
                    foreach ($groups as $group) {
                        // Don't include 'swarm-project-'
                        if (!ProjectModel::isProjectName($group->getId())) {
                            $swarmName = GroupConfig::KEY_PREFIX . $group->getId();
                            if (!in_array($swarmName, $options[$participantField])) {
                                array_push($options[$participantField], $swarmName);
                            }
                        }
                    }
                }
            }
        }
        return $options;
    }

    /**
     * Refresh the review Projects associations, the Review model will use the changelist for the current
     * head revision to determine the affected projects.
     *
     * @param string|integer $reviewId The review Id.
     *
     * @return Review
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     */
    public function refreshReviewProjects($reviewId)
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $review  = $this->fetch($reviewId, $p4Admin);
        $this->checkAccess($review);
        return $review->refreshProjects();
    }

    /**
     * Fetch the reviews that will appear on the action dashboard for a user
     * @param array                     $options        options
     * @param ConnectionInterface|null  $connection     connection to use, defaults to admin connection if not
     *                                                  provided
     * @throws ServiceNotCreatedException if there is no logged in user
     * @throws ConfigException
     * @throws P4Exception
     * @throws Exception
     * @return Iterator over review models
     */
    public function fetchDashboard(array $options = [], ConnectionInterface $connection = null) : Iterator
    {
        // This call to get a P4_USER connection will result in a ServiceNotCreatedException if there is no
        // user currently logged in
        $username       = $this->services->get(ConnectionFactory::P4_USER)->getUser();
        $config         = $this->services->get(ConfigManager::CONFIG);
        $maximumActions = ConfigManager::getValue($config, ConfigManager::DASHBOARD_MAX_ACTIONS, 1000);
        // Allow the dashboard to fetch $maximumActions (defaulting to 1000 if not found)
        // This will actually fetch up to maximum for each of the three queries for reviewer, author and
        // moderator roles before they are consolidated and only the max number returned
        $queryMax                        = isset($options[ReviewModel::FETCH_MAX])
            ? $options[ReviewModel::FETCH_MAX]
            : null;
        $options[ReviewModel::FETCH_MAX] = $queryMax ? $queryMax : $maximumActions;
        $connection                      = $connection ?? $this->services->get(ConnectionFactory::P4_ADMIN);
        $projectDAO                      = $this->services->get(IModelDAO::PROJECT_DAO);
        $projects                        = $projectDAO->fetchAll([], $connection);
        // filter out private projects
        $projects      = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($projects);
        $needsRevision = $this->fetchDashboardNeedsRevision($username, $options, $connection);
        $needsReview   = $this->fetchDashboardNeedsReview($username, $options, $connection);
        $needsReview->rewind();
        $allReviews = $this->setRoleForNeedsReview($username, $needsReview, $connection);
        $needsRevision->rewind();
        // Set/append the 'author' role on the reviews for reviews needing revision
        foreach ($needsRevision as $review) {
            if (isset($allReviews[$review->getId()])) {
                $review = $allReviews[$review->getId()];
            } else {
                $allReviews[$review->getId()] = $review;
            }
            $this->setDashboardRole($review, ReviewModel::ROLE_AUTHOR);
        }
        $moderated = $this->fetchReviewsForModeratorApproval($username, $projects, $connection);
        $moderated->rewind();
        // Set/append the 'moderator' role on the reviews needing moderation
        foreach ($moderated as $review) {
            if (isset($allReviews[$review->getId()])) {
                $review = $allReviews[$review->getId()];
            } else {
                $allReviews[$review->getId()] = $review;
            }
            $this->setDashboardRole($review, ReviewModel::ROLE_MODERATOR);
        }
        $allReviews  = array_slice($allReviews, 0, $options[ReviewModel::FETCH_MAX], true);
        $resultsIter = new Iterator($allReviews);
        // Filter out any private projects from 'projects' on each review if user does not have access
        $resultsIter = $this->services->get(PrivateProjects::PROJECTS_FILTER)
            ->filter($resultsIter, ReviewModel::FIELD_PROJECTS);
        $resultsIter = $resultsIter->sortBy(ReviewModel::FIELD_UPDATED, [Iterator::SORT_DESCENDING]);
        $resultsIter->setProperty(ReviewModel::FETCH_TOTAL_COUNT, $resultsIter->count());
        $resultsIter->setProperty(
            ReviewModel::LAST_SEEN,
            $resultsIter->last() ? $resultsIter->last()->getId() : null
        );
        // Set a 'projectsForUser' value for all the projects the user has access to. This is
        // a convenience for the dashboard results to enable javascript filtering without
        // re-querying data
        $projectsForUserService = $this->services->get(Services::PROJECTS_FOR_USER);
        $projectsForUser        = $projectsForUserService->filter(IProjectsForUser::PROJECTS_FOR_USER . $username);
        $resultsIter->setProperty(IProjectsForUser::PROJECTS_FOR_USER_VALUE, $projectsForUser);
        return $resultsIter;
    }

    /**
     * Set a role for a review based on the users participation
     * @param string                $userName       the logged in user
     * @param Iterator              $needsReview    an iterator of 'needsReview' reviews
     * @param mixed                 $connection     connection to use
     * @return array
     */
    private function setRoleForNeedsReview($userName, Iterator $needsReview, $connection) : array
    {
        $reviews  = [];
        $groupDAO = $this->services->get(IModelDAO::GROUP_DAO);
        foreach ($needsReview as $review) {
            $required         = false;
            $quorumGroupCount = 0;
            $quorumVoted      = 0;
            if ($review->isParticipantDirectlyRequired($connection, $userName)) {
                $required = true;
            } elseif ($review->isParticipantRequiredAsPartOfGroup($connection, $userName)) {
                // If the user is in a required group they are ROLE_REQUIRED_REVIEWER. However if they
                // are in require 1 groups it depends on the votes as to whether they are required.
                // We must take all groups into account by counting all the quorum groups and comparing
                // that to the count of all quorum groups where there have been at least 1 vote
                $groups = $review->getParticipantGroups();
                foreach ($review->getParticipantsData() as $participant => $participantData) {
                    if (in_array($participant, $groups)) {
                        if (isset($participantData['required'])) {
                            if ($participantData['required'] === true) {
                                $required = true;
                                break;
                            } else {
                                $quorumGroupCount++;
                                $group   = $groupDAO->fetchById(Group::getGroupName($participant), $connection);
                                $members = $groupDAO->fetchMembers(
                                    $group->getId(),
                                    [
                                        Group::FETCH_INDIRECT => true
                                    ],
                                    $connection
                                );
                                foreach ($members as $member) {
                                    if ($review->hasParticipantVotedUp($member)) {
                                        $quorumVoted++;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if ($quorumGroupCount !== $quorumVoted || $required === true) {
                $this->setDashboardRole($review, Review::ROLE_REQUIRED_REVIEWER);
            } else {
                $this->setDashboardRole($review, Review::ROLE_REVIEWER);
            }
            $reviews[$review->getId()] = $review;
        }
        return $reviews;
    }

    /**
     * Set the dashboard role on a review
     * @param mixed         $review     the review
     * @param mixed         $roles      role to set. If the review already has role(s) the role is added to existing
     */
    private function setDashboardRole($review, $roles)
    {
        $currentRoles = $review->get(ReviewModel::FIELD_ROLES);
        if ($currentRoles === null) {
            $currentRoles = [];
        }
        array_push($currentRoles, $roles);
        $review->set(ReviewModel::FIELD_ROLES, $currentRoles);
    }

    /**
     * Fetch the reviews that the current user needs to review
     * Included reviews:
     *      - Reviews in needsReview state for which the current user is not the author and which they
     *        have not yet voted on that they are participating in
     * @param string                $userName       the logged in user
     * @param array                 $options        options for the query
     * @param ConnectionInterface   $connection     connection to use
     * @return Iterator
     * @throws Exception
     */
    private function fetchDashboardNeedsReview(
        string $userName,
        array $options,
        ConnectionInterface $connection
    ) : Iterator {
        $options[ReviewModel::FIELD_STATE]        = [ReviewModel::STATE_NEEDS_REVIEW];
        $options[ReviewModel::FIELD_PARTICIPANTS] = $userName;
        $options                                  = $this->addGroupsToFetch($options, $connection);
        return ReviewModel::fetchAll($options, $connection)->filterByCallback(
            function ($review) use ($userName) {
                $includeReview = true;
                // If I am the author or I have voted I should not see an action to review
                if ($review->get('author') == $userName ||
                $review->hasParticipantVoted($userName)) {
                    $includeReview = false;
                }
                return $includeReview;
            }
        );
    }

    /**
     * Fetch the reviews that satisfy 'Needs Revision' on the dashboard.
     * Included reviews:
     *      - Current user is the author
     *      - State is needsRevision, or state is approved and not yet committed
     * @param string                $userName       the logged in user
     * @param array                 $options        options for the query
     * @param ConnectionInterface   $connection     connection to use
     * @return Iterator
     * @throws Exception
     */
    private function fetchDashboardNeedsRevision(
        string $userName,
        array $options,
        ConnectionInterface $connection
    ) : Iterator {
        $options[ReviewModel::FIELD_AUTHOR] = $userName;
        $options[ReviewModel::FIELD_STATE]  = [ReviewModel::STATE_NEEDS_REVISION, ReviewModel::STATE_APPROVED];
        return ReviewModel::fetchAll($options, $connection)->filterByCallback(
            function ($review) {
                try {
                    if ($review->getState() === ReviewModel::STATE_APPROVED) {
                        $versions     = $review->getVersions();
                        $firstVersion = array_shift($versions);
                        $head         = end($versions);
                        // shift may have shifted the only version so head could be false
                        $versionToCheck = $head ? $head : $firstVersion;
                        if (is_array($versionToCheck) && !$versionToCheck['pending']) {
                            return false;
                        }
                    }
                } catch (\RuntimeException $e) {
                    $logger = $this->services->get(SwarmLogger::SERVICE);
                    $logger->warn("Failed to get Review Version: " . $e->getMessage());
                    return false;
                }
                return true;
            }
        );
    }

    /**
     * Fetch reviews for the moderator role on the dashboard.
     * Included reviews:
     *      - Reviews in needsReview state for branches which the current user moderates and on which they have
     *        not reached the required number of votes
     * @param string                $userName       the logged in user
     * @param mixed                 $projects       current projects
     * @param ConnectionInterface $connection
     * @return ConnectedIterator|Iterator
     * @throws P4Exception
     */
    private function fetchReviewsForModeratorApproval($userName, $projects, ConnectionInterface $connection)
    {
        $groupDAO  = $this->services->get(IModelDAO::GROUP_DAO);
        $allGroups = $groupDAO->fetchAll([], $connection)->toArray(true);
        // Only include the project if the user is a moderator on any branch in the project
        $projects = $projects->filterByCallback(
            function (ProjectModel $project) use ($userName, $allGroups) {
                return $project->isModerator($userName, null, $allGroups);
            },
            null,
            [ConnectedIterator::FILTER_COPY]
        );

        $projectIds = [];
        foreach ($projects as $project) {
            $projectIds[] = $project->getId();
        }
        if (!empty($projectIds)) {
            $reviews          = Review::fetchAll(
                [
                    Review::FETCH_BY_PROJECT => $projectIds,
                    Review::FETCH_BY_STATE   => Review::STATE_NEEDS_REVIEW,
                ],
                $connection
            );
            $workflowsEnabled = $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER_RETURN);
            // As models are processed keep track of the branch rules for counted votes that we have already seen.
            // This avoids having to work the rule out multiple times for the same project:branch
            $branchRules = [];
            return $reviews->filterByCallback(
                function ($model) use ($userName, $projects, $connection, $workflowsEnabled, &$branchRules) {
                    $approvals = $model->getApprovals($userName);
                    if ($approvals && in_array($model->getHeadVersion(), $approvals, true)) {
                        // The user has already approved - review should not be included
                        $result = false;
                    } else {
                        $modelProjects = $model->getProjects();
                        // Remove any projects if the user is not a moderator on any of the branches
                        foreach ($modelProjects as $projectId => $branches) {
                            $projectToTest = null;
                            foreach ($projects as $project) {
                                if ($project->getId() == $projectId) {
                                    $projectToTest = $project;
                                    break;
                                }
                            }
                            if ($projectToTest) {
                                if (!$projectToTest->isModerator(
                                    $userName,
                                    (!$branches || sizeof($branches) == 0) ? null : $branches
                                )
                                ) {
                                    unset($modelProjects[$projectId]);
                                }
                            } else {
                                // Review project is not in the list of projects moderated by the user
                                unset($modelProjects[$projectId]);
                            }
                        }
                        if ($modelProjects) {
                            // Assess if the votes would be met if my up vote was included along with that
                            // of the author as you can add the author as a required reviewer in P4V etc
                            $includeVotes = [$userName];
                            if ($model->isValidAuthor()) {
                                $includeVotes[] = $model->getAuthorObject()->getId();
                            }
                            // The user is a moderator on one or more of the project branches so we have to assess
                            // approval by looking at min votes and any workflow rules in play
                            $result = $model->canApprove(
                                $includeVotes,
                                $modelProjects,
                                $workflowsEnabled,
                                $branchRules
                            );
                        } else {
                            // The user was not a moderator on any project branches (or there were no project branches)
                            // so the review should not be included
                            $result = false;
                        }
                    }
                    return $result;
                }
            );
        }
        return new Iterator;
    }

    /**
     * Append a review with a change. For the action to succeed the following checks must pass:
     * - a review cannot be added to another review
     * - change for change id should exist and the user should have access
     * - review for reviewId should exist and the user should have access
     * - the change should be pending
     * - the review should be pending
     * - the change should have shelved files
     * - review should not be WIP
     * - the review should not already have the change
     * - the change is not already part of another review
     * @param mixed     $changeId   the change id for the change to append
     * @param mixed     $reviewId   the review id for the review to append to
     * @param mixed     $pending    true if it is expected that the change to be appended is pending
     * @return mixed the updated review
     * @throws ForbiddenException for permissions issues on the change or review
     * @throws RecordNotFoundException if the change or review cannot be found
     * @throws InvalidArgumentException for errors that violate the rules above
     * @throws CommandException
     * @return mixed the updated review
     */
    public function appendChange($changeId, $reviewId, $pending)
    {
        return $this->appendReplaceChange(IAppendReplaceChange::MODE_APPEND, $changeId, $reviewId, $pending);
    }

    /**
     * Replace a review with a change. For the action to succeed the following checks must pass:
     * - a review cannot be added to another review
     * - change for change id should exist and the user should have access
     * - review for reviewId should exist and the user should have access
     * - if the change is pending it should have shelved files
     * - review should not be WIP
     * - the review should not already have the change
     * - the change is not already part of another review
     * @param mixed     $changeId   the change id for the change to append
     * @param mixed     $reviewId   the review id for the review to append to
     * @param mixed     $pending    true if it is expected that the change to be appended is pending
     * @return mixed the updated review
     * @throws ForbiddenException for permissions issues on the change or review
     * @throws RecordNotFoundException if the change or review cannot be found
     * @throws InvalidArgumentException for errors that violate the rules above
     * @throws CommandException
     * @return mixed the updated review
     */
    public function replaceWithChange($changeId, $reviewId, $pending)
    {
        return $this->appendReplaceChange(IAppendReplaceChange::MODE_REPLACE, $changeId, $reviewId, $pending);
    }

    /**
     * Handles append or replace of a change to the review
     * @see ReviewDAO::replaceWithChange()
     * @see ReviewDAO::appendChange()
     * @param string    $mode       either 'append' or 'replace'
     * @param mixed     $changeId   the change id for the change to append
     * @param mixed     $reviewId   the review id for the review to append to
     * @param mixed     $pending    true if it is expected that the change to be appended is pending
     * @throws RecordNotFoundException
     * @throws InvalidArgumentException
     * @throws CommandException
     * @throws ForbiddenException
     * @return mixed the updated review
     */
    private function appendReplaceChange(string $mode, $changeId, $reviewId, $pending)
    {
        $userId     = $this->services->get(ConnectionFactory::P4_USER)->getUser();
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $changeDAO  = $this->services->get(IDao::CHANGE_DAO);
        $reviewDAO  = $this->services->get(IDao::REVIEW_DAO);
        // cannot add a review to another review
        if ($changeId === $reviewId || $this->exists($changeId, $p4Admin)) {
            throw new InvalidArgumentException(
                $translator->t("A review cannot be added to a review")
            );
        }
        // change for change id should exist and the user should have access
        $change = $changeDAO->fetchById($changeId, $this->services->get(ConnectionFactory::P4_USER));
        if (!$change->canAccess()) {
            throw new ForbiddenException($translator->t("You don't have permission to access the specified change."));
        }
        // review for reviewId should exist and the user should have access
        $review = $reviewDAO->fetch($reviewId, $p4Admin);
        // if the change is pending it should have shelved files
        if ($change->isPending() && !count($change->getFileData(true))) {
            throw new InvalidArgumentException(
                $translator->t("The specified change has no shelved files")
            );
        }
        // if pending is set and not pending the change must be submitted
        if ($pending !== null && !$pending && $change->isPending()) {
            throw new InvalidArgumentException(
                $translator->t("Change %d must be committed", [$changeId])
            );
        }
        // if append or pending flag is set the change should be pending
        if ($mode === IReview::APPEND_MODE && !$change->isPending()) {
            throw new InvalidArgumentException(
                $translator->t("A committed changelist cannot be appended to a review")
            );
        }
        // if append the review should be pending
        if ($mode === IReview::APPEND_MODE && !$review->isPending()) {
            throw new InvalidArgumentException(
                $translator->t("A committed review cannot have another change appended")
            );
        }
        // review should not be WIP
        $wipService = $this->services->get(IWip::WIP_SERVICE);
        if ($wipService->checkWip($reviewId)) {
            throw new InvalidArgumentException(
                $translator->t("Cannot append or replace a change for a work in progress review")
            );
        }
        // the review should not already have the change
        if (in_array($changeId, $review->getChanges()) || $review->getVersionOfChange($changeId) !== false) {
            throw new InvalidArgumentException(
                $translator->t('The review already contains change %d.', [$changeId])
            );
        }
        // lock the next bit via our advisory locking to avoid potential race condition where another
        // process tries to create a review from the same change
        $lock = new Lock(IReview::LOCK_CHANGE_PREFIX . $changeId, $p4Admin);
        try {
            $lock->lock();
            // the change is not already part of another review
            $existing = $this->fetchAll([IReview::FETCH_BY_CHANGE => $changeId], $p4Admin);
            if ($existing->count() && (!isset($review) || !in_array($review->getId(), $existing->invoke('getId')))) {
                throw new InvalidArgumentException(
                    $translator->t('A review for change %d already exists.', [$changeId])
                );
            }
            if ($change->isSubmitted()) {
                $review->addCommit($changeId);
            }
            $review->addChange($changeId)->addParticipant($userId);
            $review->setConnection($p4Admin);
            $this->save($review);
            // push review into queue to process the files and create notifications
            $queue = $this->services->get(Manager::SERVICE);
            $queue->addTask(
                ListenerFactory::REVIEW,
                $review->getId(),
                [
                    'user'                   => $userId,
                    'updateFromChange'       => $changeId,
                    'isAdd'                  => false,
                    IReview::ADD_CHANGE_MODE => $mode
                ]
            );
        } finally {
            $lock->unlock();
        }
        return $review;
    }
}
