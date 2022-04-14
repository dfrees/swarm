<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Controller;

use Api\Controller\ReviewsController;
use Api\Validator\DateParser;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Controller\AbstractIndexController;
use Application\Filter\FormBoolean;
use Application\Filter\Preformat;
use Application\Helper\ArrayHelper;
use Application\InputFilter\InputFilter;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Module as ApplicationModule;
use Application\Option;
use Application\Permissions\ConfigCheck;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\IpProtects;
use Application\Permissions\Permissions;
use Application\Permissions\Protections;
use Application\Permissions\RestrictedChanges;
use Comments\Model\Comment;
use Groups\Model\Config as GroupConfig;
use Groups\Model\Group;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request as HttpRequest;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\Stdlib\Parameters;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\ConflictException;
use P4\File\Exception\Exception as FileException;
use P4\File\File;
use P4\Key\Key;
use P4\Log\Logger as P4Logger;
use P4\Model\Connected\Iterator as ConnectedIterator;
use P4\Model\Fielded\Iterator;
use P4\Spec\Change;
use P4\Spec\Definition as Spec;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Stream;
use Projects\Model\Project as ProjectModel;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Record\Lock\Lock;
use Reviews\Filter\GitInfo;
use Reviews\Filter\Review as ReviewFilter;
use Reviews\Model\FileInfo;
use Reviews\Model\Review;
use Reviews\UpdateService;
use Reviews\Validator\Transitions;
use TagProcessor\Service\IWip;
use Users\Model\User;
use Users\Settings\ReviewPreferences;
use Workflow\Manager;
use Workflow\Model\IWorkflow;

class IndexController extends AbstractIndexController
{
    const REVIEWS_KEY = 'reviews';
    const MODELS_KEY  = 'models';
    // pseudo field from the front end to patch and individual review participant
    const PATCH_USER = 'patchUser';
    // pseudo field from the front end to vote
    const VOTE = 'vote';

    /**
     * Index action to return rendered reviews.
     *
     * @return  ViewModel
     * @throws \Application\Config\ConfigException
     */
    public function indexAction()
    {
        $logger     = $this->services->get('logger');
        $p4Admin    = $this->services->get('p4_admin');
        $config     = $this->services->get('config');
        $request    = $this->getRequest();
        $query      = $request->getQuery();
        $translator = $this->services->get('translator');

        // for non-json requests, render the template and exit
        if ($query->get('format') !== 'json') {
            return;
        }

        $notUpdatedSinceDate = $query->get(Review::FETCH_BY_NOT_UPDATED_SINCE);
        if ($notUpdatedSinceDate) {
            $validUpdatedSinceDate =
                DateParser::validateDate($notUpdatedSinceDate) ? strtotime($notUpdatedSinceDate) : null;

            if (!$validUpdatedSinceDate) {
                $this->getResponse()->setStatusCode(400);
                return new JsonModel(
                    [
                        'isValid' => false,
                        'error' => $translator->t(
                            "Invalid updated since date. Check the date is correct and in the format YYYY-mm-dd," .
                            " for example 2017-01-01."
                        )
                    ]
                );
            }
        }

        // hasVoted/myComments buttons are privileged so you cannot see them when not logged in.
        // However bookmarks or manual URL typing may still have the parameter so we
        // check and ignore if not logged in.
        $hasVoted  = $query->get('hasVoted');
        $commented = $query->get(Review::FETCH_BY_MY_COMMENTS);
        if ($hasVoted === "up" || $hasVoted === "down" || $hasVoted === "none" || $commented === "true" ||
            $commented === "false") {
            $userName = null;
            try {
                $userName = $this->services->get('p4_user')->getUser();
            } catch (\Exception $ex) {
                $this->services->get('logger')->log(
                    P4Logger::DEBUG,
                    'Ignoring filters that require log in.'
                );
            }
            // If current logged in user is found, use this.
            if ($userName) {
                $modifiedQuery = $request->getQuery()->toArray();
                // Set the user context value to the user being used.
                $modifiedQuery[Review::FETCH_BY_USER_CONTEXT] = $userName;
                $modifiedQuery[Review::FETCH_BY_PARTICIPANTS] = $userName;
                $request->getQuery()->fromArray($modifiedQuery);
            }
        }
        // get review models
        $options = $this->getFetchAllOptions($query, $p4Admin);
        $models  = Review::fetchAll($options, $p4Admin);

        $lastSorted = null;

        if (ConfigManager::getValue($config, ConfigManager::REVIEWS_FILTERS_RESULT_SORTING, true)) {
            /*
             * Bit of lateral thinking around sorting of results. fetch_max is abandoned in
             * favour of going back in time 1 day at a time. Assuming this is a valid strategy it
             * needs to be improved to deal with more sort vectors and ranges.
             */
            $maxResults = $this->getFetchMaxOption($query);
            if ($query->offsetExists('resultOrder') && $query->get('resultOrder') == 'updated' &&
                ($query->offsetExists('afterUpdated') || count($models) >= $maxResults)) {
                // There was more data than needed, start working on sorted data
                $step        = 86400;  //  Better name, configurable
                $lastUpdated = time(); // Start at now
                $lastCreated = count($models) > 0 ? $models->last()->get('created') : 0;
                $lastSeen    = $options[Review::FETCH_AFTER];
                // Round to whole day
                $lastCreated = "" . ($lastCreated-$lastCreated%$step);
                $lastSorted  = $query->offsetExists('afterSorted') ? $query->get('afterSorted') : null;

                // Going back in last activity order
                if ($query->offsetExists('afterUpdated')) {
                    $lastUpdated = ($query->get('afterUpdated')) - (!$lastSorted ? $step : 0);
                }
                // Round to whole day
                $lastUpdated = "" . ($lastUpdated-$lastUpdated%$step);
                // So get reviews which were updated on the given day
                // FETCH_MAXIMUM is discarded at this point, we have to process each day
                $options[Review::ORDER_BY_UPDATED] = "$lastUpdated";
                unset($options[Review::FETCH_MAX]);
                unset($options[Review::FETCH_MAXIMUM]);
                unset($options[Review::FETCH_AFTER]);
                // Remember the original query result fields
                $originalProperties = $models->getProperties();
                // Get filtered data limited by last updated

                $logger->debug("Last (created $lastCreated seen $lastSeen updated $lastUpdated sorted $lastSorted)");
                $models = Review::fetchAll($options, $p4Admin);
                $logger->debug("Found " . count($models) . " for $lastUpdated");
                // Reset after, so that we come back in here next time
                $originalProperties['lastSeen'] = $query->offsetExists('after') ? $query->get('after') : "0";
                $models->setProperties($originalProperties);

                // Now sort the data by the relevant attribute
                if (count($models) > 0) {
                    $models->sortBy(["updated", "id"], [Iterator::SORT_DESCENDING, Iterator::SORT_NUMERIC]);
                    // Limit the data returned
                    if (count($models) > $maxResults) {
                        // There were more than max(50)
                        $resultArray = $models->getArrayCopy();
                        // Only work when there are more results than needed
                        $lastSeen   = null;
                        $spaceLeft  = $maxResults;
                        $properties = $models->getProperties();
                        $models     = new Iterator();
                        $models->setProperties($properties);
                        // Rebuild the result set, skipping to the last record in the sorted table
                        $populate = ! $lastSorted;
                        foreach ($resultArray as $key => $model) {
                            if ($populate) {
                                if ($spaceLeft-- > 0) {
                                    $models[$key] = $model;
                                    $lastSorted   = $key;
                                } else {
                                    break;
                                }
                            } elseif ("$key" === $lastSorted) {
                                $populate = true;
                            }
                        }
                    }
                } else {
                    // If there was no data, see if we are beyond the earliest possible updated date
                    if ($lastCreated > $lastUpdated) {
                        $options[Review::ORDER_BY_UPDATED] = "0";
                    }
                }
            }
        }
        // Grab the size before we remove any for restrictions
        $originalModelsSize = sizeof($models);

        // remove reviews that are restricted for the current user
        // we filter based on access to the most recent change
        $models = $this->services->get(RestrictedChanges::class)->filter($models, 'getHeadChange');
        // filter out private projects the current user doesn't have access to
        $models = $this->services->get('projects_filter')->filter($models, 'projects');

        // We may have removed some for restrictions due to changes/projects, get the new size
        $restrictedModelsSize = sizeof($models);

        // Reviews may have been removed because of access restrictions - we do not want to
        // show a count that indicates there were some reviews that have been removed from the
        // results.
        if ($models->hasProperty(AbstractKey::FETCH_TOTAL_COUNT)) {
            $totalCount = $models->getProperty(AbstractKey::FETCH_TOTAL_COUNT);
            $sizeDiff   = $originalModelsSize - $restrictedModelsSize;
            $models->setProperty(AbstractKey::FETCH_TOTAL_COUNT, $totalCount - $sizeDiff);
        }

        // prepare review data for output
        $reviews = $this->getReviewsFromModels($models, (bool) $request->getQuery()->get('disableHtml', false));

        return new JsonModel(
            [
                'models'        => $request->getQuery()->get('include_models') === true ? $models : null,
                'postFiltered'  => $hasVoted || $commented,
                'reviews'       => $reviews,
                'lastSeen'      => $models->getProperty('lastSeen'),
                'lastSorted'    => count($models) < $maxResults ? null : $lastSorted,
                'max'           => isset($options[Review::FETCH_MAX]) ? $options[Review::FETCH_MAX] : false,
                'afterUpdated'  => isset($options[Review::ORDER_BY_UPDATED]) && 0 < ($options[Review::ORDER_BY_UPDATED])
                    ? $options[Review::ORDER_BY_UPDATED]
                    : 0,
                'totalCount'    => $models->hasProperty(AbstractKey::FETCH_TOTAL_COUNT)
                    ? $models->getProperty(AbstractKey::FETCH_TOTAL_COUNT)
                    : null
            ]
        );
    }

    /**
     * Find all the reviews the user has authored that need revision or that are approved, later we
     * filter out approved that have been committed.
     * @param $request the request
     * @param $userName the current user
     * @param $query request query
     * @return array|mixed
     */
    private function getDashboardNeedsRevision($request, $userName, $query)
    {
        // Find all the reviews I authored that need revision or that are approved, later we
        // filter out approved that have been committed
        $query[Review::FIELD_STATE]  = [Review::STATE_NEEDS_REVISION, Review::STATE_APPROVED];
        $query[Review::FIELD_AUTHOR] = $userName;
        $request->getQuery()->fromArray($query);

        $jsonModel           = $this->indexAction();
        $needsRevisionResult = $jsonModel->getVariable(IndexController::REVIEWS_KEY);
        $needsRevisionModels = $jsonModel->getVariable(IndexController::MODELS_KEY);
        return array_filter(
            $needsRevisionResult,
            function ($review) use ($needsRevisionModels) {
                if ($review['state'] === Review::STATE_APPROVED) {
                    $fetchedReview = $needsRevisionModels[$review['id']];
                    $versions      = $fetchedReview->getversions();
                    $firstVersion  = array_shift($versions);
                    $head          = end($versions);
                    // shift may have shifted the only version so head could be false
                    $versionToCheck = $head ? $head : $firstVersion;
                    if (is_array($versionToCheck) && !$versionToCheck['pending']) {
                        return false;
                    }
                }
                return true;
            }
        );
    }

    /**
     * Gets all the 'needs review' reviews that the user should be interesed in.
     * @param $request the request
     * @param $userName the current user
     * @param $query request query
     * @param $p4Admin P4 connection
     * @param $result array to put results into
     */
    private function getDashboardNeedsReview($request, $userName, $query, $p4Admin, &$result)
    {
        $needsReviewResult = [];
        $needsReviewModels = [];
        // Set up criteria for finding the reviews I participate on
        $query[Review::FIELD_STATE]        = [Review::STATE_NEEDS_REVIEW];
        $query[Review::FIELD_PARTICIPANTS] = $userName;
        $this->addGroupsToFetch($query, $p4Admin);
        $request->getQuery()->fromArray($query);

        // Force json format for the indexAction call
        $jsonModel         = $this->indexAction();
        $needsReviewResult = $jsonModel->getVariable(IndexController::REVIEWS_KEY);
        $needsReviewModels = $jsonModel->getVariable(IndexController::MODELS_KEY);
        // Filter out any review I have voted up or down
        $needsReviewResult = array_filter(
            $needsReviewResult,
            function ($review) use ($userName, $needsReviewModels) {
                $includeReview = true;
                $reviewEntity  = $needsReviewModels[$review['id']];

                // If I am the author or I have voted I should not see an action to review
                if ((isset($review[Review::FIELD_AUTHOR]) && $review[Review::FIELD_AUTHOR] == $userName) ||
                    $reviewEntity->hasParticipantVoted($userName)) {
                    $includeReview = false;
                    unset($needsReviewModels[$review['id']]);
                }
                return $includeReview;
            }
        );
        $result[IndexController::REVIEWS_KEY] = $needsReviewResult;
        $result[IndexController::MODELS_KEY]  = $needsReviewModels;
    }

    /**
     * Gets all the reviews that should appear on the current authenticated users dashboard
     * for action ordered by updated time with the most recent first.
     * - I am a reviewer, the review needs review and I have not voted
     * - I am the author and the review needs revision
     * - I am a moderator on a branch in the review and the review needs review
     * @return JsonModel
     */
    public function dashboardAction()
    {
        $request       = $this->getRequest();
        $p4Admin       = $this->services->get('p4_admin');
        $modifiedQuery = $request->getQuery()->toArray();
        $config        = $this->services->get('config');
        try {
            $userName = $this->services->get('p4_user')->getUser();

            if ($userName) {
                // - get up to 1000 needs revision reviews
                // - get up to 1000 needs review reviews
                // - get up to 1000 reviews the user moderates for
                // - build a final combined list
                // - sort that list by updated date
                // - strip out extra results if there are more than required

                // Tell the index action to include the models it builds - we don't want to query for these again
                $modifiedQuery['include_models'] = true;
                // Force json format
                $modifiedQuery['format'] = 'json';
                // This action and the indexAction share common code to build the avatar,
                // however on the 'Reviews' page we leave the size as 32 but on the dashboard
                // it needs to be 64 like the activity stream
                $modifiedQuery['avatar_size'] = 64;

                $maximumActions = ConfigManager::getValue($config, ConfigManager::DASHBOARD_MAX_ACTIONS, 1000);
                // Allow the dashboard to fetch $maximumActions (defaulting to 1000 if not found)
                $queryMax                         = $request->getQuery()->get(Review::FETCH_MAX);
                $modifiedQuery[Review::FETCH_MAX] = $queryMax ? $queryMax : $maximumActions;

                $needsRevisionResult = $this->getDashboardNeedsRevision($request, $userName, $modifiedQuery);

                $reviewResult = [];
                $this->getDashboardNeedsReview($request, $userName, $modifiedQuery, $p4Admin, $reviewResult);
                $needsReviewResult = $reviewResult[IndexController::REVIEWS_KEY];
                $needsReviewModels = $reviewResult[IndexController::MODELS_KEY];

                $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
                $groupDAO   = $this->services->get(IModelDAO::GROUP_DAO);
                $projects   = $projectDAO->fetchAll([], $p4Admin);
                // filter out private projects
                $projects = $this->services->get('projects_filter')->filter($projects);
                // Get all the reviews I moderate
                $moderatedReviews = $this->getReviewsForModeratorApproval(
                    $userName,
                    $projects,
                    (bool)$request->getQuery()->get('disableHtml', false),
                    $modifiedQuery[Review::FETCH_MAX]
                );

                $allReviews = [];
                // Multiple rules are allowed with reviewing and needs revision taking precedence over
                // branch moderation (signified by the order in the 'roles' array)
                foreach ($needsReviewResult as $review) {
                    $reviewEntity     = $needsReviewModels[$review['id']];
                    $required         = false;
                    $quorumGroupCount = 0;
                    $quorumVoted      = 0;
                    // If the user is required as an individual they are ROLE_REQUIRED_REVIEWER
                    if ($reviewEntity->isParticipantDirectlyRequired($p4Admin, $userName)) {
                        $required = true;
                    } elseif ($reviewEntity->isParticipantRequiredAsPartOfGroup($p4Admin, $userName)) {
                        // If the user is in a required group they are ROLE_REQUIRED_REVIEWER. However if they
                        // are in require 1 groups it depends on the votes as to whether they are required.
                        // We must take all groups into account by counting all the quorum groups and comparing
                        // that to the count of all quorum groups where there have been at least 1 vote
                        $groups = $reviewEntity->getParticipantGroups();
                        foreach ($reviewEntity->getParticipantsData() as $participant => $participantData) {
                            if (in_array($participant, $groups)) {
                                if (isset($participantData['required'])) {
                                    if ($participantData['required'] === true) {
                                        $required = true;
                                        break;
                                    } else {
                                        $quorumGroupCount++;
                                        $group   = $groupDAO->fetchById(Group::getGroupName($participant), $p4Admin);
                                        $members = $groupDAO->fetchMembers(
                                            $group->getId(),
                                            [
                                                Group::FETCH_INDIRECT => true
                                            ],
                                            $p4Admin
                                        );
                                        foreach ($members as $member) {
                                            if ($reviewEntity->hasParticipantVotedUp($member)) {
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
                        $review[Review::FIELD_ROLES] = [Review::ROLE_REQUIRED_REVIEWER];
                    } else {
                        $review[Review::FIELD_ROLES] = [Review::ROLE_REVIEWER];
                    }
                    $allReviews[$review[Review::FIELD_ID]] = $review;
                }

                foreach ($needsRevisionResult as $review) {
                    if (!array_key_exists($review[Review::FIELD_ID], $allReviews)) {
                        $allReviews[$review[Review::FIELD_ID]]                      = $review;
                        $allReviews[$review[Review::FIELD_ID]][Review::FIELD_ROLES] = [];
                    }
                    array_push($allReviews[$review[Review::FIELD_ID]][Review::FIELD_ROLES], Review::ROLE_AUTHOR);
                }

                foreach ($moderatedReviews as $review) {
                    if (!array_key_exists($review[Review::FIELD_ID], $allReviews)) {
                        $allReviews[$review[Review::FIELD_ID]]                      = $review;
                        $allReviews[$review[Review::FIELD_ID]][Review::FIELD_ROLES] = [];
                    }
                    array_push($allReviews[$review[Review::FIELD_ID]][Review::FIELD_ROLES], Review::ROLE_MODERATOR);
                }
                // Sort by updated date descending
                uasort(
                    $allReviews,
                    function ($a, $b) {
                        $updateDateA = $a[Review::FIELD_UPDATE_DATE];
                        $updateDateB = $b[Review::FIELD_UPDATE_DATE];
                        if ($updateDateA == $updateDateB) {
                            return 0;
                        }
                        return ($updateDateA > $updateDateB) ? -1 : 1;
                    }
                );
                $lastSeen = null;
                if (!empty($allReviews)) {
                    // Trim the number down to our maximum
                    $allReviews = array_slice($allReviews, 0, $modifiedQuery[Review::FETCH_MAX], true);
                    end($allReviews);
                    $lastSeen = key($allReviews);
                }

                $options = $projects->count()
                    ? array_combine(
                        $projects->invoke('getId'),
                        $projects->invoke('getName')
                    ) : [];

                $allGroups  = $groupDAO->fetchAll([], $p4Admin)->toArray(true);
                $user       = $this->services->get('user');
                $myProjects = ($user->getId())
                    ? (array)$projects->filterByCallback(
                        function (ProjectModel $project) use ($user, $allGroups) {
                            return $project->isInvolved(
                                $user,
                                [
                                    ProjectModel::MEMBERSHIP_LEVEL_OWNER,
                                    ProjectModel::MEMBERSHIP_LEVEL_MEMBER,
                                    ProjectModel::MEMBERSHIP_LEVEL_MODERATOR
                                ],
                                $allGroups
                            );
                        }
                    )->invoke('getId')
                    : [];

                $userDao = $this->services->get(IModelDAO::USER_DAO);
                // we need to modify the author field before we pass it back to the dashboard so the user can look
                // for both the userid and the full name
                foreach ($allReviews as $key => $review) {
                    try {
                        $review['author'] .= " (" . $userDao->fetchById($review['author'])->getFullName() . ")";
                    } catch (\Exception $e) {
                        // user does not exist - leave the author field alone
                    }
                    $allReviews[$key] = $review;
                }

                return new JsonModel(
                    [
                        'lastSeen'   => $lastSeen,
                        'reviews'    => $allReviews,
                        'myProjects' => $myProjects,
                        'totalCount' => sizeof($allReviews)
                    ]
                );
            }
        } catch (ServiceNotCreatedException $snce) {
            return new JsonModel(
                [
                    'reviews'=> [],
                ]
            );
        }
    }

    /**
     * Gets an array of reviews that are associated with projects that the user
     * moderates a branch for.
     * @param $userName the user.
     * @param $projects projects to search
     * @param $disableHtml whether HTML is disabled.
     * @param $fetchMax maximum number of reviews to retrieve
     * @return array my moderated reviews or an empty array if none are found.
     * @throws \Exception
     */
    private function getReviewsForModeratorApproval($userName, $projects, $disableHtml, $fetchMax)
    {
        $p4Admin   = $this->services->get('p4_admin');
        $groupDAO  = $this->services->get(IModelDAO::GROUP_DAO);
        $allGroups = $groupDAO->fetchAll([], $p4Admin)->toArray(true);
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
        $reviews = [];
        if (!empty($projectIds)) {
            $models           = Review::fetchAll(
                [
                    Review::FETCH_BY_PROJECT => $projectIds,
                    Review::FETCH_BY_STATE   => Review::STATE_NEEDS_REVIEW,
                    Review::FETCH_MAX        => $fetchMax
                ],
                $p4Admin
            );
            $workflowsEnabled = $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER_RETURN);
            // As models are processed keep track of the branch rules for counted votes that we have already seen.
            // This avoids having to work the rule out multiple times for the same project:branch
            $branchRules = [];
            $models->filterByCallback(
                function ($model) use ($userName, $projects, $p4Admin, $workflowsEnabled, &$branchRules) {
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
            $reviews = $this->getReviewsFromModels($models, $disableHtml);
        }
        return $reviews;
    }

    /**
     * Builds a review array from models
     * @param $models the models
     * @param $disableHtml
     * @return array
     */
    private function getReviewsFromModels($models, $disableHtml)
    {
        // prepare review data for output
        $request       = $this->getRequest();
        $reviews       = [];
        $preformat     = new Preformat($this->services, $request->getBaseUrl());
        $avatar        = $this->services->get('ViewHelperManager')->get('avatar');
        $projectList   = $this->services->get('ViewHelperManager')->get('projectList');
        $activeProject = $this->getEvent()->getRouteMatch()->getParam('activeProject');
        $avatarSize    = $request->getQuery()->get('avatar_size') ? $request->getQuery()->get('avatar_size') : 32;
        $p4Admin       = $this->services->get('p4_admin');
        $topics        = $models->invoke('getTopic');
        $counts        = Comment::countByTopic(array_unique($topics), $p4Admin);
        foreach ($models as $model) {
            // - render author avatar
            // - add pre-formatted description
            // - add formatted date of creation
            // - add rendered list of projects
            // - add comments count
            // - add up/down votes
            $author      = $model->get('author');
            $description = $model->get('description');
            $projects    = $model->get('projects');
            $topic       = $model->getTopic();
            $reviews[]   = array_merge(
                $model->get(),
                [
                    'authorAvatar' => !$disableHtml ? $avatar($author, $avatarSize, $model->isValidAuthor()) : null,
                    'description'  => !$disableHtml ? $preformat->filter($description) : $description,
                    'createDate'   => date('c', $model->get('created')),
                    'projects'     => !$disableHtml ? $projectList($projects, $activeProject) : $projects,
                    'comments'     => isset($counts[$topic]) ? $counts[$topic] : [0, 0],
                    'upVotes'      => array_keys($model->getUpVotes()),
                    'downVotes'    => array_keys($model->getDownVotes()),
                    'updateDate'   => date('c', $model->get('updated')),
                ]
            );
        }
        return $reviews;
    }

    /**
     * Create a new review record for the specified change.
     *
     * @return  JsonModel
     */
    public function addAction()
    {
        $request    = $this->getRequest();
        $logger     = $this->services->get('logger');
        $translator = $this->services->get('translator');

        // only allow logged in users to add reviews
        $this->services->get('permissions')->enforce('authenticated');

        // request must be a post
        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.')
                ]
            );
        }

        $p4Admin = $this->services->get('p4_admin');
        $p4User  = $this->services->get('p4_user');

        // if this is an edge server and there is a commit Swarm, forward request
        // to the commit Swarm so that the review is bound to the commit server
        $info       = $p4Admin->getInfo();
        $serverType = isset($info['serverServices']) ? $info['serverServices'] : null;
        $flags      = ['-l', '-n', ApplicationModule::PROPERTY_SWARM_COMMIT_URL];
        $commitUrl  = $p4Admin->isServerMinVersion('2013.1')
            ? $p4Admin->run('property', $flags)->getData(0, 'value')
            : null;
        if ($commitUrl && strpos('edge-server', $serverType) !== false) {
            return $this->forwardAdd($commitUrl);
        }

        $user              = $this->services->get('user');
        $id                = $request->getPost('id');
        $changeId          = $request->getPost('change');
        $reviewers         = $request->getPost(Review::REVIEWERS);
        $requiredReviewers = $request->getPost(Review::REQUIRED_REVIEWERS);
        $reviewerQuorums   = $request->getPost(Review::REVIEWER_QUORUMS, []);
        $description       = $request->getPost('description');
        $state             = $request->getPost('state');
        $mode              = $request->getPost(ReviewsController::MODE);

        $wipService = $this->services->get(IWip::WIP_SERVICE);
        $matches    = $wipService->checkWip($id ?? $changeId);
        if ($matches) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t(
                        'The Review was not created, the changelist description has a Work in Progress tag.'
                    )
                ]
            );
        }

        $logger->notice('Review:addAction: Create a review for change [' . $changeId . '], mode[' . $mode . ']');
        // fail early for ridiculous invocations
        if ($changeId === $id || Review::exists($changeId, $p4Admin)) {
            // Cannot use a review change id
            return new JsonModel(
                [
                    'isValid' => false,
                    'error'   => $translator->t('A review cannot be added to a review.'),
                    'change'  => $changeId
                ]
            );
        }
        // validate specified change for existence and access
        try {
            $change = Change::fetchById($changeId, $p4User);
        } catch (SpecNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        if (!isset($change)) {
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('The specified change does not exist.'),
                    'change'    => $changeId
                ]
            );
        } elseif (!$change->canAccess()) {
            throw new ForbiddenException("You don't have permission to access the specified change.");
        } elseif ($change->isPending() && !count($change->getFileData(true))) {
            return new JsonModel(
                [
                    'isValid' => false,
                    'error'   => $translator->t('The specified change has no shelved files.'),
                    'change'  => $changeId
                ]
            );
        } elseif ($mode === Review::APPEND_MODE && ! $change->isPending()) {
            // Cannot append a committed changelist
            return new JsonModel(
                [
                    'isValid' => false,
                    'error'   => $translator->t('A committed changelist cannot be appended to a review.'),
                    'change'  => $changeId
                ]
            );
        }

        // if a review id is set, validate and fetch the review
        if (strlen($id)) {
            try {
                $review = Review::fetch($id, $p4Admin);
            } catch (RecordNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            // if we got a not found or invalid argument exception send a 404
            if (!isset($review)) {
                $this->getResponse()->setStatusCode(404);
                return new JsonModel(
                    [
                        'isValid'   => false,
                        'error'     => $translator->t('The specified review does not exist.'),
                    ]
                );
            } elseif ($mode === Review::APPEND_MODE && !$review->isPending()) {
                return new JsonModel(
                    [
                        'isValid' => false,
                        'error'   => $translator->t('A committed review cannot have another change appended.'),
                        'change'  => $id
                    ]
                );
            }

            $this->restrictAccess($review);
        }

        // if this is an existing review, ensure the change isn't already associated
        if (isset($review)
            && (in_array($changeId, $review->getChanges()) || $review->getVersionOfChange($changeId) !== false)
        ) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('The review already contains change %d.', [$changeId]),
                    'change'    => $changeId
                ]
            );
        }

        // lock the next bit via our advisory locking to avoid potential race condition where another
        // process tries to create a review from the same change
        $lock = new Lock(Review::LOCK_CHANGE_PREFIX . $changeId, $p4Admin);
        $lock->lock();

        try {
            // if there is an existing review and we weren't passed a review id or
            // the review id we were passed differs, error out.
            $existing = Review::fetchAll([Review::FETCH_BY_CHANGE => $changeId], $p4Admin);
            if ($existing->count() && (!isset($review) || !in_array($review->getId(), $existing->invoke('getId')))) {
                $lock->unlock();
                return new JsonModel(
                    [
                        'isValid'   => false,
                        'error'     => $translator->t('A Review for change %d already exists.', [$changeId]),
                        'change'    => $changeId
                    ]
                );
            }

            // create the review model from the change if a review was not passed
            if (!isset($review)) {
                $review = Review::createFromChange($changeId, $p4Admin);
                $isAdd  = true;
            }

            // users can optionally pass a description and add reviewers or required reviewers
            // if they have, make use of the review filter to validate and sanitize user input
            $filterData = array_filter(
                [
                    'description'              => $description,
                    Review::REVIEWERS          => $reviewers,
                    Review::REQUIRED_REVIEWERS => $requiredReviewers,
                    Review::REVIEWER_QUORUMS   => $reviewerQuorums,
                    ReviewsController::MODE    => $mode
                ]
            );
            if ($filterData) {
                $filter = $this->getReviewFilter($review);
                $this->combineReviewerData($review, $filterData);
                $this->checkDefaultReviewers($review, $filterData, true);
                $filter->setData($filterData);
                $filter->setValidationGroupSafe(array_keys($filterData));

                if (!$filter->isValid()) {
                    $lock->unlock();
                    return new JsonModel(
                        [
                            'isValid'  => false,
                            'messages' => $filter->getMessages(),
                        ]
                    );
                }
                // combined reviewers was built to help validate and check retention, we do not need
                // it to save on the review - we will keep the existing reviewers, requiredReviewers
                // and reviewerQuorum to work out what to set as we do not want to upset voting etc
                unset($filterData[ReviewFilter::COMBINED_REVIEWERS]);
                $review->set('description', $filter->getValue('description') ?: $review->get('description'));
            }
            // We do not want to allow users to be able to add non-committed
            // changes from the 'Add commit..', 'Already committed...' dialog
            if ($state && $state == 'attach-commit' && !$change->isSubmitted()) {
                $lock->unlock();
                return new JsonModel(
                    [
                        'isValid'   => false,
                        'error'     => $translator->t('The change %d must be committed.', [$changeId]),
                        'change'    => $changeId
                    ]
                );
            }
            // link the change and its author to the review
            if ($change->isSubmitted()) {
                $review->addCommit($changeId);
            }
            $review->addChange($changeId)
                   ->addParticipant($user->getId())
                   ->save();
        } catch (\Exception $e) {
            // we handle this after unlocking
        }

        // we are done with updating the review, release the lock
        $lock->unlock();

        // re-throw any errors we got when we were locked down
        if (isset($e)) {
            throw $e;
        }

        // push review into queue to process the files and create notifications
        $queue = $this->services->get('queue');
        $queue->addTask(
            'review',
            $review->getId(),
            [
                'user'                  => $user->getId(),
                'updateFromChange'      => $changeId,
                'isAdd'                 => isset($isAdd) && $isAdd,
                Review::ADD_CHANGE_MODE => $mode
            ]
        );

        return new JsonModel(
            [
                'isValid'   => true,
                'id'        => $review->getId(),
                'review'    => $review->toArray() + ['versions' => $review->getVersions()]
            ]
        );
    }

    /**
     * This is a dedicated function to only display the React page.
     * @return ViewModel
     * @throws RecordNotFoundException
     * @throws SpecNotFoundException
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     */
    public function reactPageAction()
    {
        $route  = $this->getEvent()->getRouteMatch();
        $id     = $route->getParam('review');
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $logger->info("Requesting React page for review $id");
        $reactView = new ViewModel(['id' => $id, ]);
        $reactView->setTemplate('reviews/index/react');
        return $reactView;
    }

    /**
     * View the specified review
     * @return ViewModel
     * @throws CommandException
     * @throws ForbiddenException
     * @throws RecordNotFoundException
     * @throws SpecNotFoundException
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\UnopenedException
     * @throws \Record\Exception\Exception
     */
    public function reviewAction()
    {
        $config = $this->services->get(ConfigManager::CONFIG);
        $ui     = $_COOKIE[IConfigDefinition::REVIEW_UI] ?? ConfigManager::getValue(
            $config,
            IConfigDefinition::REVIEWS_DEFAULT_UI,
            IConfigDefinition::CLASSIC
        );
        if ($ui === IConfigDefinition::PREVIEW) {
            return $this->reactPageAction();
        }
        $p4Config = $this->services->get(ConnectionFactory::P4_CONFIG);
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $request  = $this->getRequest();
        $route    = $this->getEvent()->getRouteMatch();
        $id       = $route->getParam('review');
        $version  = $route->getParam('version', $request->getQuery('v'));
        $format   = $request->getQuery('format');
        $archiver = $this->services->get(Services::ARCHIVER);
        $readme   = '';
        $logger   = $this->services->get(SwarmLogger::SERVICE);

        try {
            $logger->info("Request for review $id");
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // split version parameter into left and right (e.g. v2,3)
        // two comma-separated versions indicate we should diff one against the other
        $right = null;
        $left  = null;
        if ($version) {
            $parts = explode(',', $version);
            $right = count($parts) > 1 ? $parts[1] : $parts[0];
            $left  = count($parts) > 1 ? $parts[0] : null;
            if ($left && $right && $left > $right) {
                // Swap them around if they are out of order
                $tmp   = $left;
                $left  = $right;
                $right = $tmp;
            }
        }

        // if an invalid review or version was specified, send 404 response
        if (!isset($review) || ($right && !$review->hasVersion($right)) || ($left && !$review->hasVersion($left))) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        // if request was posted, consider this an edit attempt.
        if ($this->getRequest()->isPost() || $this->getRequest()->isPatch()) {
            $this->services->get(Permissions::PERMISSIONS)->enforce(Permissions::AUTHENTICATED);
            return $this->editReview($review, $this->getRequest()->getPost()->toArray());
        }

        // if requested format is json, return data needed by javascript to update the review
        if ($format === 'json') {
            $preformat = new Preformat($this->services, $request->getBaseUrl());
            $model     = new JsonModel(
                [
                    'review'           => $review->toArray() + ['versions' => $review->getVersions()],
                    'avatars'          => $this->getReviewAvatars($review),
                    'authorAvatar'     => $this->getAuthorAvatar($review),
                    'transitions'      => $this->getReviewTransitions($review),
                    'description'      => $preformat->filter($review->get('description')),
                    'canEditReviewers' => $this->canEditReviewers($review),
                    'canEditAuthor'    => $this->canEditAuthor($review),
                    'diffPreferences'  => ReviewPreferences::getReviewPreferences($config, $this->services->get('user'))
                ]
            );
            $logger->trace("Returning json data " . $model->serialize());
            return $model;
        }

        // get file information for the requested version(s)
        // if a second version was specified, we 'diff' the file lists
        // note we fetch max + 1 so that we know if we've exceeded max.
        $p4      = $this->services->get(ConnectionFactory::P4);
        $change  = Change::fetchById($right ? $review->getChangeOfVersion($right) : $review->getHeadChange(), $p4);
        $against = $left ? Change::fetchById($review->getChangeOfVersion($left), $p4) : null;
        $max     = isset($p4Config['max_changelist_files']) ? (int) $p4Config['max_changelist_files'] : 1000;
        $files   = $this->getAffectedFiles($review, $change, $against, $max ? $max + 1 : null);

        $expandAllLimit = ConfigManager::getValue($config, ConfigManager::REVIEWS_EXPAND_ALL);
        $fileCount      = count($files);
        $allowExpand    = $expandAllLimit == 0 || $fileCount <= $expandAllLimit;

        // if we've exceeded max files, indicate we've cropped the file list and drop the last element
        if ($max && $fileCount > $max) {
            $cropped = true;
            array_pop($files);
        }

        // filter files to comply with user's IP-based protections
        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);
        $files      = $ipProtects->filterPaths($files, Protections::MODE_LIST, 'depotFile');

        // generate add/edit/delete metrics
        $counts = ['adds' => 0, 'edits' => 0, 'deletes' => 0];
        // Archive will break if we have a stream spec only in the change.
        $streamOnly = true;
        foreach ($files as $file) {
            $counts['adds']    += (int) $file['isAdd'];
            $counts['edits']   += (int) $file['isEdit'];
            $counts['deletes'] += (int) $file['isDelete'];
            $streamOnly        &= $file['type'] === Stream::SPEC_TYPE;
        }

        // compute base-path (can't rely on change object when diffing two changes)
        // note: because the paths are sorted, we can get away with a clever trick
        // and just compare the first and last file.
        $basePath = '';
        if (count($files)) {
            $last    = end($files);
            $first   = reset($files);
            $length  = min(strlen($first['depotFile']), strlen($last['depotFile']));
            $compare = $p4->isCaseSensitive() ? 'strcmp' : 'strcasecmp';
            for ($i = 0; $i < $length; $i++) {
                if ($compare($first['depotFile'][$i], $last['depotFile'][$i]) !== 0) {
                    break;
                }
            }
            $basePath = substr($first['depotFile'], 0, $i);
            $basePath = substr($basePath, 0, strrpos($basePath, '/'));
        }

        // prepare json data for associated jobs - we show only jobs attached
        // to the associated swarm-managed shelf
        $jobs = $this->forward()->dispatch(
            \Changes\Controller\IndexController::class,
            [
                'action' => 'fixes',
                'change' => $review->getId(),
                'mode'   => null,
            ]
        );

        // filter existing projects associated with the review
        $projects = $this->services->get('projects_filter')->filterList($review->getProjects());
        $review->setProjects($projects);

        // check mentions settings, can be one of:
        // - disabled
        // - enabled for all users and all groups in all comments
        // - enabled only for project users and groups in review that has a project (default)
        $mentions = [];
        switch ($config['mentions']['mode']) {
            case 'disabled':
            case 'global':
                break;
            default:
                $mentions = Comment::getPossibleMentions('reviews/' . $review->getId(), $config, $p4Admin);
        }
        if (count($projects) === 1) {
            $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
            $project    = $projectDAO->fetch(current(array_keys($projects)), $p4Admin);
            $readme     = $this->services->get(Services::GET_PROJECT_README)->getReadme($project);
        }
        $groupDAO = $this->services->get(IModelDAO::GROUP_DAO);

        return new ViewModel(
            [
                'project'           => count($projects) === 1
                    ? current(array_keys($projects))
                    : null,
                'readme'            => $readme,
                'review'            => $review,
                'avatars'           => $this->getReviewAvatars($review),
                'authorAvatar'      => $this->getAuthorAvatar($review),
                'transitions'       => $this->getReviewTransitions($review),
                'canEditReviewers'  => $this->canEditReviewers($review),
                'canEditAuthor'     => $this->canEditAuthor($review),
                'left'              => $left,
                'right'             => $right,
                'change'            => $change,
                'changeRev'         => $right ?: $review->getVersionOfChange($change->getId()),
                'against'           => $against,
                'againstRev'        => $against ? ($left ?: $review->getVersionOfChange($against->getId())) : null,
                'files'             => $files,
                'fileInfos'         => FileInfo::fetchAllByReview($review, $p4Admin),
                'counts'            => $counts,
                'max'               => $max,
                'cropped'           => isset($cropped) ? true : false,
                'basePath'          => $basePath,
                'jobs'              => $jobs instanceof JsonModel ? $jobs->getVariable('jobs') : [],
                'jobSpec'           => Spec::fetch('job', $p4),
                'canArchive'        => $archiver->canArchive() && !$streamOnly,
                'mentionsMode'      => $config['mentions']['mode'],
                'mentions'          => $mentions,
                'cleanup'           => ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP),
                'allowExpand'       => $allowExpand,
                'fileCount'         => $fileCount,
                'expandAllLimit'    => $expandAllLimit,
                'groupsMembership'  => $groupDAO->fetchAllGroupsMembers($review->getParticipantGroups()),
                'diffPreferences'   => ReviewPreferences::getReviewPreferences(
                    $config,
                    $this->services->get(ConnectionFactory::USER)
                ),
                'changeModify'      => $this->allowChangeModify($review)
            ]
        );
    }

    /**
     * Determines if append/replace of a change list is allowed based on
     * workflow and end states.
     * @param Review    $review     the current review
     * @return bool true if option is allowed
     * @throws \Application\Config\ConfigException
     */
    private function allowChangeModify(Review $review)
    {
        try {
            $this->services->get('config_check')->enforce(IWorkflow::WORKFLOW);
            $workflowManager = $this->services->get(Services::WORKFLOW_MANAGER);
            $endRulesResult  = $workflowManager->checkEndRules($review);
            $allow           = $endRulesResult[Manager::KEY_STATUS] === Manager::STATUS_OK ? true : false;
        } catch (ForbiddenException $fe) {
            // This is fine, workflows are not enabled, always allow
            $allow = true;
        }
        return $allow;
    }

    /**
     * Update the test status
     */
    public function testStatusAction()
    {
        $p4Admin = $this->services->get('p4_admin');
        $match   = $this->getEvent()->getRouteMatch();
        $id      = $match->getParam('review');
        $status  = $match->getParam('status');
        $token   = $match->getParam('token');
        $details = $this->getRequest()->getPost()->toArray()
                 + $this->getRequest()->getQuery()->toArray();

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // this is intended to be a token authenticated action;
        // ensure a valid token was passed in
        if (!strlen($token) || $review->getToken() !== $token) {
            throw new ForbiddenException(
                'Invalid or missing token; cannot update test status'
            );
        }

        $version    = explode('.v', $token);
        $version    = (int) end($version);
        $oldStatus  = $review->get('testStatus');
        $oldDetails = $review->getTestDetails(true);
        $oldVersion = $oldDetails !== null ? (int) $oldDetails['version'] : null;

        // if the review touches multiple projects, we could get multiple results for the same version
        // in that case we want to preserve the details of the first failure
        if (count($review->getProjects()) > 1 && $version === $oldVersion && $oldStatus === Review::TEST_STATUS_FAIL) {
            $status  = $oldStatus;
            $details = $oldDetails;
        }

        // we always carry forward timing details
        $details['startTimes']   = $oldDetails['startTimes'];
        $details['endTimes']     = $oldDetails['endTimes'];
        $details['averageLapse'] = $oldDetails['averageLapse'];

        // we always update the version and record the end time
        $details['version']    = $version;
        $details['endTimes'][] = time();

        // if this is the last result we expect, update the average lapse time
        if ($details['startTimes'] && count($details['endTimes']) >= count($details['startTimes'])) {
            $lapse                   = max($details['endTimes']) - min($details['startTimes']);
            $details['averageLapse'] = $details['averageLapse']
                ? ($details['averageLapse'] + $lapse) / 2
                : $lapse;
        }

        $result = $this->editReview($review, ['testStatus' => $status, 'testDetails' => $details]);

        // pluck specific fields - token only grants access to update status, not review details
        return new JsonModel(['isValid' => $result->isValid, 'messages' => $result->messages]);
    }

    /**
     * Update the deploy status
     */
    public function deployStatusAction()
    {
        $p4Admin = $this->services->get('p4_admin');
        $match   = $this->getEvent()->getRouteMatch();
        $id      = $match->getParam('review');
        $status  = $match->getParam('status');
        $token   = $match->getParam('token');
        $details = $this->getRequest()->getPost()->toArray()
                 + $this->getRequest()->getQuery()->toArray();

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // this is intended to be a token authenticated action;
        // ensure a valid token was passed in
        if (!strlen($token) || $review->getToken() !== $token) {
            throw new ForbiddenException(
                'Invalid or missing token; cannot update deploy status'
            );
        }

        $result = $this->editReview($review, ['deployStatus' => $status, 'deployDetails' => $details]);

        // pluck specific fields - token only grants access to update status, not review details
        return new JsonModel(['isValid' => $result->isValid, 'messages' => $result->messages]);
    }

    /**
     * Edit author of the current review
     */
    public function editAuthorAction()
    {
        // only allow logged in users to edit author

        $p4Admin    = $this->services->get('p4_admin');
        $translator = $this->services->get('translator');
        $request    = $this->getRequest();
        $route      = $this->getEvent()->getRouteMatch();
        $id         = $route->getParam('review');
        $userId     = $route->getParam('user', $request->getPost('user'));

        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.')
                ]
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review) || !isset($userId)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        return $this->editReview($review, ['author' => $userId]);
    }

    /**
     * Vote up/down the current review
     */
    public function voteAction()
    {
        // only allow votes for logged in users
        $this->services->get('permissions')->enforce('authenticated');
        $p4Admin    = $this->services->get('p4_admin');
        $user       = $this->services->get('user');
        $translator = $this->services->get('translator');
        $request    = $this->getRequest();
        $route      = $this->getEvent()->getRouteMatch();
        $id         = $route->getParam('review');
        $userId     = $request->getPost('user');
        $version    = $request->getPost('version');
        $vote       = strtolower($route->getParam(self::VOTE, $request->getPost(self::VOTE)));

        // if a userId was passed we will make sure it matches the current user
        if (isset($userId) && $userId !== $user->getId()) {
            throw new ForbiddenException('Not logged in as ' . $userId);
        }

        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.')
                ]
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review) || !isset($vote)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        return $this->editReview($review, [self::VOTE => ['value' => $vote, 'version' => $version]]);
    }

    /**
     * edit the active user's review preferences for the current review
     * at present only required is supported but over time this will be extended out
     * to more generically support properties in an extensible manner
     */
    public function reviewerAction()
    {
        $p4Admin    = $this->services->get('p4_admin');
        $user       = $this->services->get('user');
        $translator = $this->services->get('translator');
        $request    = $this->getRequest();
        $route      = $this->getEvent()->getRouteMatch();
        $id         = $route->getParam('review');
        $userId     = $route->getParam('user');

        // only allow votes for logged in users
        $this->services->get('permissions')->enforce('authenticated');

        // we only support PATCH/DELETE or a simulated patch/delete
        $isPatch  = $request->isPatch()
            || ($request->isPost() && strtolower($request->getQuery('_method')) === 'patch');
        $isDelete = $request->isDelete()
            || ($request->isPost() && strtolower($request->getQuery('_method')) === 'delete');
        if (!$isPatch && !$isDelete) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. PATCH or DELETE required.')
                ]
            );
        }

        // make sure the userId matches the current user
        if (!Group::isGroupName($userId) && $userId !== $user->getId()) {
            throw new ForbiddenException('Not logged in as ' . $userId);
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        return $isDelete
            ? $this->editReview($review, ['leave' => $userId])
            : $this->editReview($review, [self::PATCH_USER => $request->getPost(), 'reviewer' => $userId]);
    }

    /**
     * edit the reviewers for the current review
     */
    public function reviewersAction()
    {
        $p4Admin           = $this->services->get('p4_admin');
        $request           = $this->getRequest();
        $route             = $this->getEvent()->getRouteMatch();
        $id                = $route->getParam('review');
        $reviewers         = $request->getPost('reviewers', []);
        $requiredReviewers = $request->getPost('requiredReviewers', []);
        $reviewerQuorums   = $request->getPost(Review::REVIEWER_QUORUMS, []);

        $this->services->get('permissions')->enforce('authenticated');

        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => 'Invalid request method. HTTP POST required.'
                ]
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        return $this->editReview(
            $review,
            [
                'reviewers'              => $reviewers,
                'requiredReviewers'      => $requiredReviewers,
                Review::REVIEWER_QUORUMS => $reviewerQuorums
            ]
        );
    }

    /**
     * Specific index action to search for inactive reviews. Uses the generic index action but mandates that
     * Review::FETCH_BY_NOT_UPDATED_SINCE must be in the query passed.
     * @return JsonModel|ViewModel
     */
    public function archiveIndexAction()
    {
        $translator            = $this->services->get('translator');
        $request               = $this->getRequest();
        $query                 = $request->getQuery();
        $date                  = $query->get(Review::FETCH_BY_NOT_UPDATED_SINCE);
        $validUpdatedSinceDate = DateParser::validateDate($date) ? strtotime($date) : null;

        if ($date) {
            return $this->indexAction();
        } else {
            return new JsonModel(
                [
                    'isValid' => false,
                    'error' => $translator->t(
                        "Updated since date not provided. Check the date is correct and in the format YYYY-mm-dd," .
                        " for example 2017-01-01."
                    )
                ]
            );
        }
    }

    /**
     * Execute a transition on the review
     */
    public function transitionAction()
    {
        // only allow transition if they are logged in
        $logger = $this->services->get('logger');
        $config = $this->services->get('config');
        $this->services->get('permissions')->enforce('authenticated');

        $p4Admin     = $this->services->get('p4_admin');
        $user        = $this->services->get('user');
        $queue       = $this->services->get('queue');
        $translator  = $this->services->get('translator');
        $id          = $this->getEvent()->getRouteMatch()->getParam('review');
        $request     = $this->getRequest();
        $response    = $this->getResponse();
        $state       = $request->getPost('state');
        $jobs        = $request->getPost('jobs');
        $fixStatus   = $request->getPost('fixStatus');
        $wait        = $request->getPost('wait');
        $description = trim($request->getPost('description'));
        $cleanup     =
            ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP_MODE) === ConfigManager::USER
                ? $request->getPost('cleanup') === "on"
                : ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP_DEFAULT) === true;

        if (!$request->isPost()) {
            return new JsonModel(
                [
                     'isValid'   => false,
                     'error'     => $translator->t('Invalid request method. HTTP POST required.')
                ]
            );
        }

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        // if we got a not found or invalid argument exception
        // send a more appropriate 404
        if (!isset($review)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        // if the user has not supplied a commit description, borrow the review description
        if ($state == 'approved:commit' && !strlen($description)) {
            $description = $review->get('description');
        }

        // let edit do the validation and work. always clear commit status.
        // clearing commit status allows the user to move to needs review/revision,
        // archive or reject to escape a hung commit.
        $values = ['state' => $state, 'commitStatus' => null];

        // approve/reject states will also upvote/downvote
        if (strpos($state, Review::STATE_APPROVED) === 0 || $state == Review::STATE_REJECTED) {
            $values[self::VOTE] = ['value' => $state == Review::STATE_REJECTED ? -1 : 1];
        }
        // Get the original state in case we have to revert it
        $originalState = $review->getOriginalState();

        $json = $this->editReview($review, $values, $description);

        // if we received a description for a non-commit and edit succeeded; add a comment
        if (strlen($description) && $state != 'approved:commit' && $json->getVariable('isValid')) {
            $comment = new Comment($p4Admin);
            $comment->set(
                [
                    'topic'   => 'reviews/' . $review->getId(),
                    'user'    => $user->getId(),
                    'context' => ['review' => $review->getId()],
                    'body'    => $description
                ]
            )->save();

            // push comment into queue for possible further processing
            // note: we pass 'quiet' so that no activity is created and no mail is sent.
            $queue->addTask('comment', $comment->getId(), ['quiet' => true, 'current' => $comment->get()]);
        }

        // if we aren't doing a commit, or we want to but edit failed, simply return
        if ($state != 'approved:commit' || $json->getVariable('isValid') != true) {
            return $json;
        }

        // looks like we're in for a commit; disconnect the browser to get it rolling
        if (!$wait) {
            $response->getHeaders()->addHeaderLine('Content-Type: application/json; charset=utf-8');
            $response->setContent($json->serialize());
            $this->disconnect();
        }

        // large changes can take a while to commit
        ini_set(
            'max_execution_time',
            isset($config['reviews']['commit_timeout'])
            ? (int) $config['reviews']['commit_timeout']
            : 1800
        );

        // commit the review as the user, and check whether we should attribute the commit to the review author
        $p4User       = $this->services->get('p4_user');
        $creditAuthor = isset($config['reviews']['commit_credit_author']) && $config['reviews']['commit_credit_author'];

        // if jobs were not provided, check to see if any existing jobs should be carried over to the commit
        if ($jobs === null) {
            $jobs = $p4User->run('fixes', ['-c', $review->getId()])->getData();
            $jobs = array_map(
                function ($value) {
                    return $value['Job'];
                },
                $jobs
            );
        }
        // Lock the review to prevent the review from being picked up till the commit
        // and owner of the changelist has been changed.
        $lock = new Lock(Review::LOCK_CHANGE_PREFIX . $id, $p4Admin);
        $lock->lock();
        try {
            $commit = $review->commit(
                [
                    Review::COMMIT_DESCRIPTION   => $description,
                    Review::COMMIT_JOBS          => is_array($jobs) ? $jobs : null,
                    Review::COMMIT_FIX_STATUS    => $fixStatus,
                    Review::COMMIT_CREDIT_AUTHOR => $creditAuthor
                ],
                $p4User
            );
            if ($cleanup) {
                // Cleanup via the admin connection, might need super?
                $logger->notice("Cleaning up the pending changelists.");
                $review->cleanup(
                    ['reopen' => ConfigManager::getValue($config, ConfigManager::REVIEWS_CLEANUP_REOPEN_FILES)],
                    $p4User
                );
            }
        } catch (ConflictException $e) {
            $this->revertState($review, $originalState, $this->services);
            // As we have taken a lock earlier we now need to unlock. As we won't hit final return
            // unlocking before the jsonmodel is return to prevent full lock up of the review.
            $lock->unlock();
            // inform the user that files are outdated
            return new JsonModel(
                ['isValid' => false, 'error' => 'Out of date files must be resolved or reverted.']
            );
        } catch (CommandException $e) {
            $this->revertState($review, $originalState, $this->services);
            // As we have taken a lock earlier we now need to unlock. As we won't hit final return
            // unlocking before the jsonmodel is return to prevent full lock up of the review.
            $lock->unlock();
            // handle invalid job ID by returning an informative error response
            $pattern = "/(Job '[^']*' doesn't exist.)/s";
            if (preg_match($pattern, $e->getMessage(), $matches)) {
                return new JsonModel(['isValid' => false, 'error' => $matches[ 1]]);
            }

            // handle an invalid job status by returning an informative error response
            $pattern = "/(Job fix status must be one of [^\.]*\.)/s";
            if (preg_match($pattern, $e->getMessage(), $matches)) {
                return new JsonModel(['isValid' => false, 'error' => $matches[ 1]]);
            }

            // fall back to original CommandException handling
            throw $e;
        }

        // re-fetch the review and update the json model with review and commit data
        if ($wait) {
            $review = Review::fetch($review->getId(), $p4Admin);

            $json ->setVariable('isValid', isset($commit))
                  ->setVariable('commit',  isset($commit) ? $commit->getId() : null)
                  ->setVariable('review',  $review->get());
            // As we have taken a lock earlier we now need to unlock. As we won't hit final return
            // unlocking before the JSON is return to prevent full lock up of the review.
            $lock->unlock();
            return $json;
        }

        // If we have not returned yet we need to unlock the review before we return.
        $lock->unlock();

        return $response;
    }

    /**
     * Reverts a review state to its value before the transition. If a commit fails the index
     * values will have been updated to reflect the new state and we need to save the review
     * again after setting it back to the original state before failure.
     * @param $review
     * @param $originalState
     */
    private function revertState($review, $originalState)
    {
        try {
            $review->setState($originalState);
            $review->save();
        } catch (\Exception $e) {
            $this->services->get('logger')->log(
                P4Logger::ERR,
                'Unable to revert review ' . $review->getId() . ' to state ' . $originalState
            );
        }
    }

    /**
     * Remove a version from a review
     *
     * @todo    do the project re-assessment in a worker?
     * @todo    rebuild the canonical shelf
     * @todo    safer to reference versions by change?
     */
    public function deleteVersionAction()
    {
        $p4Admin = $this->services->get('p4_admin');
        $match   = $this->getEvent()->getRouteMatch();
        $id      = $match->getParam('review');
        $version = $match->getParam('version');

        try {
            $review = Review::fetch($id, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }
        if (!isset($review) || !$review->hasVersion($version)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $this->restrictAccess($review);

        // only admins can delete versions from reviews
        $this->services->get('permissions')->enforce('admin');

        // refuse to delete the only version
        // (that should probably be a delete review operation, but we don't have one)
        $versions = $review->getVersions();
        if (count($versions) <= 1) {
            throw new ForbiddenException("Cannot remove the last version of a review.");
        }

        // remove version's change from commits/changes lists
        $version--;
        $change = isset($versions[$version]['archiveChange'])
            ? $versions[$version]['archiveChange']
            : $versions[$version]['change'];
        $review->setChanges(array_diff($review->getChanges(), [$change]));
        $review->setCommits(array_diff($review->getCommits(), [$change]));

        // drop the version
        unset($versions[$version]);
        $review->setVersions($versions);

        $findAffected = $this->services->get(Services::AFFECTED_PROJECTS);
        // re-assess projects
        $review->setProjects(null);
        foreach ($versions as $version) {
            $change = Change::fetchById($version['change'], $p4Admin);
            $review->addProjects($findAffected->findByChange($p4Admin, $change));
        }

        $review->save();

        return new JsonModel(['review' => $review->get()]);
    }

    /**
     * Upgrade review records.
     *
     * The application-wide upgrade level is stored in a 'swarm-upgrade-level' key.
     * If the value is greater-than or equal to the latest level we know about, this action
     * will report that upgrades are done. If the counter does not exist or the value is
     * less-than the latest, we will do the upgrade.
     *
     * The review model's save() method handles upgrading automatically, so all we do here
     * is iterate over all review records and re-save those that haven't been upgraded yet.
     * The upgrade level of each record is indicated by the (hidden) 'upgrade' field.
     *
     * There is some complexity around upgrading and reporting status to the browser.
     * The initial request disconnects the client and runs in the background.
     * We use a refresh header to tell the browser to reload the page to show the latest
     * status. Status is written to a 'swarm-upgrade-status' key. The status key is cleared
     * when upgrades are complete.
     */
    public function upgradeAction()
    {
        $response   = $this->getResponse();
        $logger     = $this->services->get('logger');
        $config     = $this->services->get('config');
        $p4Admin    = $this->services->get('p4_admin');
        $translator = $this->services->get('translator');
        // Define 'starting' to be picked up by translation scripts.
        // $status->get() will translate correctly without us writing
        // a translated string into the key
        $translator->t('starting');

        // only allow upgrade if user is an admin
        $this->services->get('permissions')->enforce('admin');

        // if an upgrade is in progress, report the status
        $status = new Key($p4Admin);
        $status->setId('swarm-upgrade-status');
        if ($status->get()) {
            $refreshInterval = ConfigManager::getValue($config, ConfigManager::UPGRADE_STATUS_REFRESH_INTERVAL, 10);
            $response->getHeaders()->addHeaderLine("Refresh: $refreshInterval");

            return new ViewModel(['status' => $translator->t($status->get())]);
        }

        // if upgrade is all done, report done
        $level = new Key($p4Admin);
        $level->setId('swarm-upgrade-level');
        if ($level->get() > 0) {
            $level->delete();  // Upgrade is complete, allow it to run again
            return new ViewModel(['status' => $translator->t('done')]);
        }

        // looks like we need to upgrade!
        // we want to avoid two upgrade processes running concurrently, so we
        // increment the counter and verify we got the expected result (1).
        if ((int) $level->increment() !== 1) {
            $level->set(1);
            throw new \Exception("Cannot upgrade. It appears another upgrade is in progress.");
        }

        // Get a key counter/key to keep track of updated date indexing
        $upgradeReviewIndexes = new Key($p4Admin);
        $upgradeReviewIndexes->setId('swarm-upgrade-review-indexes');
        $after = $upgradeReviewIndexes->get()?:0;

        // write out 'started' to status counter, tell client to refresh
        // and disconnect so we can process upgrade in the background.
        $status->set('starting');
        $response->getHeaders()->addHeaderLine('Refresh: 1');
        $this->disconnect();

        // ensure we don't run out of resources prematurely
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        // fetch all review records in batches and re-save them to trigger upgrade logic
        $progress  = 0;
        $reviews   = [];
        $batchSize = ConfigManager::getValue($config, ConfigManager::UPGRADE_BATCH_SIZE, 1000);
        $logger->debug("Reviews: Upgrading 0% complete.");
        do {
            $reviews = Review::fetchAll(
                [
                    Review::FETCH_AFTER => $after?:false,
                    Review::FETCH_MAXIMUM => $batchSize,
                    Review::FETCH_TOTAL_COUNT => true
                ],
                $p4Admin
            );
            foreach ($reviews as $review) {
                if ($review->get('upgrade') < Review::UPGRADE_LEVEL) {
                    $review->save([Review::EXCLUDE_UPDATED_DATE]);
                } else {
                    // Index updated date regardless of the current status
                    try {
                        $review->index(1313, 'updated', $review->get('updated'), false);
                    } catch (\Exception $e) {
                        $logger->err("Reviews: Indexing " . $e);
                        $reviews = new Iterator();
                        break;
                    }
                }
                if (!($progress++%100)) {
                    $status->set(floor(($progress / $reviews->getProperty('totalCount')) * 100) . '%');
                }
            }

            $after = isset($review) ? $review->getId() : false;
            $logger->debug("Reviews: Upgraded to " . ($after ?: 'unknown') . ", " . $status->get() . " complete.");
            $upgradeReviewIndexes->set($after);
        } while (count($reviews) >= $batchSize);  // Stop once all reviews have been checked
        // all done, clear status counters.
        $upgradeReviewIndexes->delete();
        $status->delete();
    }

    /**
     * Set file information. At present, this only knows how to mark a file as read/unread.
     */
    public function fileInfoAction()
    {
        $p4Admin  = $this->services->get('p4_admin');
        $p4User   = $this->services->get('p4_user');
        $route    = $this->getEvent()->getRouteMatch();
        $request  = $this->getRequest();
        $response = $this->getResponse();
        $review   = $route->getParam('review');
        $version  = $route->getParam('version');
        $file     = $route->getParam('file');

        // user must be logged in to adjust file info
        $this->services->get('permissions')->enforce('authenticated');

        // fetch review, or return 404
        try {
            $review = Review::fetch($review, $p4Admin);
        } catch (RecordNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }
        if (!$review instanceof Review) {
            $response->setStatusCode(404);
            return;
        }
        $this->restrictAccess($review);

        // ensure specified version(s) exists, else 404
        // note we grab the 'archive' change number for consistency
        $versionParts = explode(',', $version);
        $against      = strpos($version, ',') ? current($versionParts) : null;
        $version      = end($versionParts);
        try {
            $change  = $review->getChangeOfVersion($version, true);
            $against = $against ? $review->getChangeOfVersion($against, true) : null;
        } catch (\Exception $e) {
            $response->setStatusCode(404);
            return;
        }

        // ensure specified file exists, else 404
        try {
            $file = trim($file, '/');
            $file = strlen($file) ? '//' . $file : null;
            $file = File::fetch($file ? $file . '@=' . $change : null, $p4User);
        } catch (FileException $e) {
            // try again if we are diffing against an older version
            // this is needed in the case of files that have been removed
            if ($against) {
                try {
                    $file = File::fetch($file ? $file . '@=' . $against : null, $p4User);
                } catch (FileException $e) {
                    // handled below
                }
            }

            if (!$file instanceof File) {
                $response->setStatusCode(404);
                return;
            }
        }

        // validate posted data
        $filter = $this->getFileInfoFilter()->setData($request->getPost());
        if (!$filter->isValid()) {
            $response->setStatusCode(400);
            return new JsonModel(
                [
                    'isValid'  => false,
                    'messages' => $filter->getMessages()
                ]
            );
        }

        // at this point, we are good to update the record
        // if the record doesn't exist yet, make one
        try {
            $fileInfo = FileInfo::fetch(
                FileInfo::composeId($review->getId(), $file->getDepotFilename()),
                $p4Admin
            );
        } catch (RecordNotFoundException $e) {
            $fileInfo = new FileInfo($p4Admin);
            $fileInfo->set('review',    $review->getId())
                     ->set('depotFile', $file->getDepotFilename());
        }

        if ($filter->getValue('read')) {
            // use digest if we have one and it is current
            $digest = $file->hasField('digest') && $file->get('headChange') == $change
                ? $file->get('digest')
                : null;

            $fileInfo->markReadBy($filter->getValue('user'), $version, $digest);
        } else {
            $fileInfo->clearReadBy($filter->getValue('user'));
        }
        $fileInfo->save();

        return new JsonModel(
            [
                'isValid' => true,
                'readBy'  => $fileInfo->getReadBy()
            ]
        );
    }

    protected function getFileInfoFilter()
    {
        $filter = new InputFilter;
        $user   = $this->services->get('user');

        // ensure user is provided and refers to the active user
        $filter->add(
            [
                'name'          => 'user',
                'required'      => true,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback' => function ($value) use ($user) {
                                if ($value !== $user->getId()) {
                                    return 'Not logged in as %s';
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        // ensure read flag is provided and has value 1 or 0 (for true/false)
        $filter->add(
            [
                'name'          => 'read',
                'required'      => true,
                'validators'    => [
                    [
                        'name'      => 'InArray',
                        'options'   => [
                            'haystack' => ['1', '0']
                        ]
                    ]
                ]
            ]
        );

        return $filter;
    }

    /**
     * Combines 'reviewers', 'requiredReviewers' and 'reviewerQuorum' and possibly 'patchUser' into a combined field
     * that can be validated by the filter.
     * Additionally, it will filter out any blacklisted reviewers that do not already exist.
     *
     * @param Review    $review the review
     * @param array     $data   values from the request
     * @throws RecordNotFoundException
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     */
    private function combineReviewerData(Review $review, array &$data)
    {
        $this->removeBlacklistedReviewers($review, $data);
        $p4Admin           = $this->services->get('p4_admin');
        $combinedReviewers = null;
        $requiredReviewers = isset($data[Review::REQUIRED_REVIEWERS]) ? $data[Review::REQUIRED_REVIEWERS] : null;
        $reviewers         =
            isset($data[Review::REVIEWERS])
                ? is_array($data[Review::REVIEWERS]) ? $data[Review::REVIEWERS] : explode(",", $data[Review::REVIEWERS])
                : null;
        if ($reviewers != null || $requiredReviewers !== null || isset($data[self::PATCH_USER])) {
            $combinedReviewers = [];
        }
        if ($reviewers != null) {
            array_map(
                function ($value) use (&$combinedReviewers) {
                    $combinedReviewers[$value] = [];
                },
                array_unique(array_merge($reviewers, (array) $requiredReviewers))
            );
        }
        if ($requiredReviewers !== null) {
            $reviewerQuorums = isset($data[Review::REVIEWER_QUORUMS]) ? $data[Review::REVIEWER_QUORUMS] : [];
            array_map(
                function ($key, $value) use (&$combinedReviewers) {
                    $combinedReviewers[$key]['required'] = $value;
                },
                array_keys($reviewerQuorums),
                $reviewerQuorums
            );
            array_map(
                function ($value) use (&$combinedReviewers) {
                    // Groups may already have been set from the quorums field
                    if (!isset($combinedReviewers[$value]['required'])) {
                        $combinedReviewers[$value]['required'] = true;
                    }
                },
                $requiredReviewers
            );
        }
        // We also have to deal with the special patchUser field when and individual reviewer is sent
        // patchUser is 'required' => value with a 'reviewer' field to indicate the participant
        if (isset($data[self::PATCH_USER])) {
            $combinedReviewers[$data[Review::REVIEWER]] = [];
            foreach ((array) $data[self::PATCH_USER] as $key => $value) {
                $combinedReviewers[$data[Review::REVIEWER]][(string)$key] =
                    ($value === '1' || $value === 'true' || $value === true) ? true : false;
            }
            if ($review) {
                $combinedReviewers = ArrayHelper::merge($review->getParticipantsData(), $combinedReviewers);
            }
        }
        if ($combinedReviewers !== null) {
            UpdateService::mergeDefaultReviewersForProjects(
                $review->getProjects(),
                $combinedReviewers,
                $p4Admin,
                [UpdateService::ALWAYS_ADD_DEFAULT => false]
            );
            // Before we update participants we need to preserve any fields already set (for example
            // 'vote' and 'notificationsDisabled' etc)
            foreach ($review->getParticipantsData() as $participant => $participantData) {
                foreach ($participantData as $participantField => $fieldValue) {
                    if ($participantField !== 'required' && $participantField !== Review::FIELD_MINIMUM_REQUIRED) {
                        $combinedReviewers[$participant][$participantField] = $fieldValue;
                    }
                }
            }
            $review->setParticipantsData($combinedReviewers);
        }
        $data[ReviewFilter::COMBINED_REVIEWERS] = $combinedReviewers;
    }

    /**
     * Removes blacklisted reviewers from the $data object.
     * Note it will only doe this if the blacklisted reviewer does not already exist.
     *
     * @param Review    $review the review
     * @param array     $data   values from the request
     * @throws \Application\Config\ConfigException
     */
    private function removeBlacklistedReviewers(Review $review, array &$data)
    {
        $config          = $this->services->get('config');
        $caseSensitive   = $this->services->get('p4_admin')->isCaseSensitive();
        $groupsBlacklist = ConfigManager::getValue($config, ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST, []);
        $usersBlacklist  = ConfigManager::getValue($config, ConfigManager::MENTIONS_USERS_EXCLUDE_LIST, []);

        if (count($groupsBlacklist) + count($usersBlacklist) == 0) {
            return;
        }

        $old      = $review->get();
        $oldNames = $old && isset($old['participantsData']) ? array_keys($old['participantsData']) : [];

        if (isset($data[Review::REVIEWER])
            && $this->inBlacklist($data[Review::REVIEWER], $oldNames, $groupsBlacklist, $usersBlacklist, $caseSensitive)
        ) {
            unset($data[Review::REVIEWER]);
        }

        if (isset($data[Review::REVIEWERS])) {
            foreach ($data[Review::REVIEWERS] as $index => $reviewer) {
                if ($this->inBlacklist($reviewer, $oldNames, $groupsBlacklist, $usersBlacklist, $caseSensitive)) {
                    unset($data[Review::REVIEWERS][$index]);
                }
            }
            $data[Review::REVIEWERS] = array_values($data[Review::REVIEWERS]);
        }

        if (isset($data[Review::REQUIRED_REVIEWERS])) {
            foreach ($data[Review::REQUIRED_REVIEWERS] as $index => $reviewer) {
                if ($this->inBlacklist($reviewer, $oldNames, $groupsBlacklist, $usersBlacklist, $caseSensitive)) {
                    unset($data[Review::REQUIRED_REVIEWERS][$index]);
                }
            }
            $data[Review::REQUIRED_REVIEWERS] = array_values($data[Review::REQUIRED_REVIEWERS]);
        }
    }

    /**
     * Only blacklists if adding a blacklisted user or group that does not already exist in the reviewers.
     *
     * @param string        $name              the name to check
     * @param array         $oldNames          list of users & groups already in the reviewers list
     * @param array         $groupsBlacklist   list of blacklisted groups
     * @param array         $usersBlacklist    list of blacklisted users
     * @param bool          $caseSensitive     if p4d is case sensitive
     * @return bool
     */
    private function inBlacklist($name, $oldNames, $groupsBlacklist, $usersBlacklist, $caseSensitive)
    {
        if (in_array($name, $oldNames)) {
            return false;
        }

        $groupName        = Group::getGroupName($name);
        $groupBlacklisted = Group::isGroupName($name)
            ? ConfigCheck::isExcluded($groupName, $groupsBlacklist, $caseSensitive)
            : false;

        if ($groupBlacklisted || ConfigCheck::isExcluded($name, $usersBlacklist, $caseSensitive)) {
            return true;
        }

        return false;
    }

    /**
     * Merges in default reviewers when votes or state changes.
     * @param Review        $review             the review
     * @param array         $data               data changes
     * @param bool          $alwaysAddDefault   whether defaults are added regardless of retention
     * @throws RecordNotFoundException
     * @throws \P4\Exception
     */
    private function checkDefaultReviewers(Review $review, array $data, $alwaysAddDefault = false)
    {
        if (isset($data[self::VOTE]) ||
            isset($data[Review::FIELD_STATE])) {
            $p4Admin      = $this->services->get('p4_admin');
            $participants = $review->getParticipantsData();
            $review->setParticipantsData(
                UpdateService::mergeDefaultReviewersForProjects(
                    $review->getProjects(),
                    $participants,
                    $p4Admin,
                    [UpdateService::ALWAYS_ADD_DEFAULT => $alwaysAddDefault]
                )
            );
        }
    }

    /**
     * Edit the review record.
     * @param Review    $review         review record to edit
     * @param array     $data           list with field-value pairs to change in $review record
     *                                  (values for fields not present in $data will remain unchanged)
     * @param string    $description    optional - text to accompany update (e.g. comment or commit message)
     * @return JsonModel
     * @throws CommandException
     * @throws RecordNotFoundException
     * @throws SpecNotFoundException
     * @throws \Application\Config\ConfigException
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\UnopenedException
     * @throws \Record\Exception\Exception
     */
    protected function editReview(Review $review, array $data, $description = null)
    {
        // validate user input
        $old = $review->get();
        $this->combineReviewerData($review, $data);
        $this->checkDefaultReviewers($review, $data);
        $filter = $this->getReviewFilter($review);
        $filter->setData($data);
        // indicate that we want to validate only fields present in $data
        $filter->setValidationGroupSafe(array_keys($data));

        // if the data is valid, update the review record
        $isValid = $filter->isValid();
        $config  = $this->services->get('config');
        $logger  = $this->services->get('logger');

        if ($isValid && $data && !empty($data)) {
            $queue    = $this->services->get('queue');
            $user     = $this->services->get('user');
            $filtered = $filter->getValues();

            // combined reviewers was built to help validate and check retention, we do not need
            // it to save on the review - we will keep the existing reviewers, requiredReviewers
            // and reviewerQuorum to work out what to set as we do not want to upset voting etc
            unset($filtered[ReviewFilter::COMBINED_REVIEWERS]);

            // dear future me, if you are about to add a pseudo field or edit the
            // order of items below there are a few things to keep in mind:
            // - we want direct modifications of involved reviewers, require-reviewers to
            //   occur early on so we can detect them, this means things like voting, @mentions,
            //   adding active user as participant, etc. have to happen after isReviewersChange detection
            // - put another way, anything that changes participantsData should occur after
            //   we have detected $isReviewersChange to avoid erroneously tripping it (e.g. voting)
            // - we should keep in mind the event.review listener will also 'quiet' some things
            //   so we don't have to capture all ignorable events at this level

            // 'patchUser' is a pseudo-field indicating we should adjust the given user's properties
            $patchUser  = isset($filtered[self::PATCH_USER]) ? $filtered[self::PATCH_USER] : null;
            $patchGroup = false;
            foreach ((array) $patchUser as $key => $value) {
                $review->setParticipantData($data[Review::REVIEWER], $value, $key);
                if (Group::isGroupName($data[Review::REVIEWER])) {
                    $patchGroup = true;
                }
            }
            unset($filtered[self::PATCH_USER]);

            // 'join' is a pseudo-field indicating the active user wishes to join the review
            $join = isset($filtered['join']) ? $filtered['join'] : null;
            unset($filtered['join']);
            $review->addParticipant($join);

            // 'leave' is a pseudo-field indicating the active user wishes to leave the review
            $leave = isset($filtered['leave']) ? $filtered['leave'] : null;
            unset($filtered['leave']);
            $review->setParticipants(array_diff($review->getParticipants(), (array) $leave));

            // 'requiredReviewers' and 'reviewers' are pseudo-fields used to set the list of reviewers
            // and which are required. if both are being passed we make sure we don't temporarily lose
            // any required reviewers as their votes and other properties would be lost. Their processing
            // is handled in combineReviewerData but we need to establish is we were passed reviewers here
            // for a check later on
            $reviewers = isset($data[Review::REVIEWERS]) ? $data[Review::REVIEWERS] : null;
            // detect if anyone adjusted participants, do this before we deal with votes as they
            // will also muck the participants data but are classified separately
            $isReviewersChange = $old['participantsData'] != $review->getParticipantsData();

            // 'vote' is a pseudo-field indicating we should add a vote for the current user
            $vote = isset($filtered[self::VOTE]) ? $filtered[self::VOTE] : null;
            if ($vote !== null) {
                $logger->info(
                    'Vote ' . $vote['value'] .
                    ' by ' . $user->getId() . ' for review ' . $review->getId() . ' v'. $vote['version']
                );
                $review->addParticipant($user->getId())
                       ->addVote($user->getId(), $vote['value'], $vote['version']);
                unset($filtered[self::VOTE]);
                // Voting has occurred, look for auto approval
                if ($vote['value'] === 1) {
                    // Voted up, need to look through moderators and workflows
                    $p4admin    = $this->services->get('p4_admin');
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
                            $logger->debug('Review ' . $review->getId() . ' has moderators, cannot auto-approve');
                            break;
                        }
                    }
                    if (!$moderators) {
                        // There are no moderators, look for a workflow with auto-approve = 'votes'
                        $workflowsEnabled =
                            $this->services->get(Services::CONFIG_CHECK)->check(IWorkflow::WORKFLOW_CHECKER_RETURN);
                        if ($workflowsEnabled) {
                            $wfManager       = $this->services->get(Services::WORKFLOW_MANAGER);
                            $autoApproveRule = $wfManager->getBranchRule(
                                IWorkflow::AUTO_APPROVE,
                                $affected,
                                $projects
                            );
                            $branchRules     = [];
                            if ($autoApproveRule === IWorkflow::VOTES &&
                                $review->canApprove([], $affected, $workflowsEnabled, $branchRules)) {
                                // We are auto-approving on last vote up
                                $filtered[Review::FIELD_STATE] = Review::STATE_APPROVED;
                            }
                        }
                    }
                }
            }

            // 'approvals' is a pseudo-field indicating which versions of a review a user has approved
            if (isset($filtered[Review::FIELD_STATE]) &&
                strpos($filtered[Review::FIELD_STATE], Review::STATE_APPROVED) === 0) {
                $review->approve($user->getId(), $review->getHeadVersion());
                // State change can only be allowed if all necessary approvals have been made
                if (!$review->isStateAllowed(
                    $filtered[Review::FIELD_STATE],
                    [
                        ConfigManager::MODERATOR_APPROVAL => ConfigManager::getValue(
                            $config,
                            ConfigManager::REVIEWS_MODERATOR_APPROVAL
                        )
                    ]
                )) {
                    unset($filtered[Review::FIELD_STATE]);
                }
            }

            // set all of the other non-pseudo fields
            $review->set($filtered);

            // if no fields we care about have changed, this isn't worth reporting on. this
            // causes us to ignore the approved:commit transition if already approved, changes
            // to just the deployStatus and nops such as edit reviewers with no modifications.
            // we don't quiet fields such as testStatus, even if they haven't changed, though
            // later logic may still choose to.
            $ignored = array_flip(['deployStatus', 'deployDetails', Review::FIELD_APPROVALS]);
            $notify  = array_flip(['testStatus']);
            $quiet   = !array_intersect_key($filtered, $notify)
                     && array_diff_key($review->get(), $ignored) == array_diff_key($old, $ignored);

            // add whoever just edited the review as a participant unless the operation was
            // to set all reviewers (as we should simply honor the list in that case) or to
            // leave (as adding you back would be silly).
            // we do this late so we can detect if any 'requested' changes occurred accurately.
            // We also do not want to add them as a participant if the change was to simply
            // patch a group (which is what is sent when the group requirement is changed from
            // its drop down
            if ($reviewers === null && $leave === null && !$patchGroup) {
                $review->addParticipant($user->getId());
            }

            $review->save();

            // convenience function to detect changes - special handling for the state field
            $old       += [
                'state'        => null,
                'author'       => null,
                'testStatus'   => null,
                'deployStatus' => null,
                'description'  => null
            ];
            $hasChanged = function ($field) use ($filtered, $old) {
                if (!array_key_exists($field, $filtered)) {
                    return false;
                }

                $newValue = $filtered[$field];
                $oldValue = isset($old[$field]) ? $old[$field] : null;

                // 'approved' to 'approved:commit' is not considered a state change
                if ($field === 'state' && $newValue === Review::STATE_APPROVED_COMMIT) {
                    $newValue = Review::STATE_APPROVED;
                }

                return $newValue != $oldValue;
            };

            // description of the canonical shelf should match review description
            // note: we need a valid client for this operation
            if ($hasChanged('description')) {
                $p4 = $review->getConnection();
                $p4->getService('clients')->grab();
                $change = Change::fetchById($review->getId(), $p4);

                // if this is a git review update the description but keep the
                // existing git info (keys/values), otherwise just use the review
                // description as-is for the update
                $description = $review->get('description');
                if ($review->getType() === 'git') {
                    $gitInfo     = new GitInfo($change->getDescription());
                    $description = $gitInfo->setDescription($description)->getValue();
                }

                $change->setDescription($description)->save(true);
                $this->updateOriginalChangelist($data, $review, $description, $user, $p4);
                $p4->getService('clients')->release();
            }

            // push update into queue for further processing.
            $queue->addTask(
                'review',
                $review->getId(),
                [
                    'user'                => $user->getId(),
                    'isStateChange'       => $hasChanged('state'),
                    'isAuthorChange'      => $hasChanged('author'),
                    'isVote'              => isset($vote['value']) ? $vote['value'] : false,
                    'isReviewersChange'   => $isReviewersChange,
                    'isDescriptionChange' => $hasChanged('description'),
                    'testStatus'          => $filter->getValue('testStatus'),   // defaults to null if not posted
                    'deployStatus'        => $filter->getValue('deployStatus'), // defaults to null if not posted
                    'description'         => trim($description),
                    'previous'            => $old,
                    'quiet'               => $quiet
                ]
            );
        }

        $preformat = new Preformat($this->services, $this->getRequest()->getBaseUrl());
        return new JsonModel(
            [
                'isValid'           => $isValid,
                'messages'          => $filter->getMessages(),
                'review'            => $review->toArray() + ['versions' => $review->getVersions()],
                'avatars'           => $this->getReviewAvatars($review),
                'authorAvatar'      => $this->getAuthorAvatar($review),
                'transitions'       => $this->getReviewTransitions($review),
                'description'       => $preformat->filter($review->get('description')),
                'canEditReviewers'  => $this->canEditReviewers($review),
                'canEditAuthor'     => $this->canEditAuthor($review),
                'changeModify'      => $this->allowChangeModify($review)
            ]
        );
    }

    /**
     * Updates the description of a review's original changelist to match the review's new
     * description only if all of the following conditions are met:
     * - It is flagged for update
     * - The description has changed
     * - The editor is also the owner of the original changelist
     * - It is a pre-commit review
     *
     * @param array      $data                  list with field-value pairs to change in $review record
     * @param Review     $review                review record
     * @param string     $description           new description for the original changelist
     * @param User       $editor                editor of the review's description
     * @param Connection $p4
     *
     * @throws CommandException
     * @throws SpecNotFoundException
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\UnopenedException
     */
    protected function updateOriginalChangelist(array $data, Review $review, $description, User $editor, Connection $p4)
    {
        // It's not flagged for update or it's not a pre-commit review
        if (!isset($data['updateOriginalChangelist']) || !$review->isPending()) {
            return;
        }

        try {
            $originalChange = Change::fetchById($review->getChanges()[0], $p4);
        } catch (SpecNotFoundException $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->trace($e->getMessage());
            return;
        }

        // The description has not changed or the editor is not the owner of the original changelist
        if ($originalChange->getDescription() == $description || $originalChange->getUser() != $editor->getId()) {
            return;
        }

        // No conditions were violated, so we are free to update
        $originalChange->setDescription($description)->save(true);
    }

    /**
     * This method implements our access controls for who can/can-not edit the reviewers.
     * Note joining/leaving and adjusting your own required'ness is a separate operation
     * and not governed by this check.
     *
     * @param   Review  $review     review model
     * @return  bool    true if active user can edit the review, false otherwise
     */
    protected function canEditReviewers(Review $review)
    {
        $permissions = $this->services->get('permissions');

        // if you aren't authenticated you aren't allowed to edit
        if (!$permissions->is('authenticated')) {
            return false;
        }

        // if you are an admin, the author, or there aren't any
        // projects associated with this review, you can edit.
        $userId = $this->services->get('user')->getId();
        if ($permissions->is('admin')
            || $review->get('author') === $userId
            || !$review->getProjects()
        ) {
            return true;
        }

        // looks like this review impacts projects, let's figure
        // out who the involved members and moderators are.
        $p4Admin    = $this->services->get('p4_admin');
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
     * This method implements our access controls for who can/can-not change the author.
     *
     * @param   Review  $review     review model
     * @return  bool    true if active user can change the review author, false otherwise
     */
    protected function canEditAuthor(Review $review)
    {
        $permissions = $this->services->get('permissions');

        // if you aren't authenticated you aren't allowed to edit
        if (!$permissions->is('authenticated')) {
            return false;
        }

        return true;
    }

    /**
     * Get transitions for review model and filter response.
     * If the user isn't a candidate to transition this review, false is returned. It is recommended
     * the transition UI be disabled in that case.
     * If the user is a candidate, an array will be returned though it may be empty. Even an empty
     * array indicates transitioning is viable and the UI may opt to stay enable and show items such
     * as 'add a commit' in this case.
     *
     * @param  Review      $review     review model
     * @return array                   array of available transitions (may be empty)
     */
    protected function getReviewTransitions(Review $review)
    {
        $userId = $this->services->get(ConnectionFactory::USER)->getId();
        // As the front end expects the user to have voted as part of this check
        $upVoters          = $userId ? [$userId] : [];
        $options           = [
            Option::USER_ID           => $userId,
            Transitions::OPT_UP_VOTES => $upVoters,
            Transitions::REVIEW       => $review
        ];
        $reviewTransitions = $this->services->build(Services::TRANSITIONS, $options);
        return $reviewTransitions->getAllowedTransitions();
    }

    /**
     * Get filter for review model input data.
     *
     * @param   Review          $review     review model for context
     * @return  ReviewFilter    filter for review model input data
     */
    protected function getReviewFilter(Review $review)
    {
        $transitions = $this->getReviewTransitions($review);

        // determine if the active user is allowed to edit other reviewers and author
        $canEditReviewers = $this->canEditReviewers($review);
        // normalize transitions to an array, it can be false which is effectively empty set
        $transitions   = is_array($transitions) ? $transitions : [];
        $canEditAuthor = $this->canEditAuthor($review);
        return new ReviewFilter(
            $review,
            $this->getRequest(),
            $this->services,
            $transitions,
            $canEditReviewers,
            $canEditAuthor
        );
    }

    /**
     * List files affected by the given change or between two changes.
     *
     * The intent is to show the work the author did in a review at a version or
     * between two versions. If one change is given, it is easy. We simply ask
     * the server to 'describe' the change. If two changes are given, it is hard.
     *
     * It is hard because the server can't tell us. We need to collect the list
     * of files affected by either change, analyze the file actions in each change
     * and produce information we can use to show just the diffs introduced between
     * those changes.
     *
     * @param   Review      $review     the review to get affected files in
     * @param   Change      $right      the primary (newer) change
     * @param   Change      $left       optional - an older change
     * @param   int|null    $max        optional - limit number of files returned (can cause inaccurate results)
     * @return  array   list of affected files with describe-like information
     * @throws  \InvalidArgumentException   if left or right is not a version of the review
     *                                      or if the left change is newer than the right
     * @todo    consider moving this into the review model or a utility class
     * @todo    handle moves when called with left & right (report fromFile, fromRev)
     * @todo    support non-consecutive changes (e.g. v3 vs. v1)
     */
    protected function getAffectedFiles(Review $review, Change $right, Change $left = null, $max = null)
    {
        $isAdd = function ($action) {
            return preg_match('/add|branch|import/', $action) !== 0;
        };
        $isEdit = function ($action) {
            return preg_match('/add|branch|import|delete/', $action) === 0;
        };
        $isDelete = function ($action) {
            return strpos($action, 'delete') !== false;
        };

        // early exit for a single change (simple case)
        if (!$left) {
            $affected = [];
            foreach ($right->getFileData(true, $max) as $file) {
                if ($file['type'] === Stream::SPEC_TYPE && !isset($file['action'])) {
                    // stream is new and review created from committed changelist
                    $file['rev']    = "";
                    $file['action'] = "edit";
                }
                $file['isAdd']    = $isAdd($file['action']);
                $file['isEdit']   = $isEdit($file['action']);
                $file['isDelete'] = $isDelete($file['action']);
                $affected[]       = $file;
            }

            return $affected;
        }

        // left must be older than right
        $leftVersion  = $review->getVersionOfChange($left);
        $rightVersion = $review->getVersionOfChange($right);
        if (!$leftVersion || !$rightVersion) {
            throw new \InvalidArgumentException(
                "Left and right must be versions of the review."
            );
        }

        // because we have two changes, we need to collect files affected by either change
        // and we need to keep both sides of file info so we can tell what happened.
        // if the server is case insensitive, we lower case the depotFile key to ensure
        // accurate left/right cross-references.
        $affected        = [];
        $isCaseSensitive = $this->services->get('p4')->isCaseSensitive();
        foreach ([$left, $right] as $i => $change) {
            foreach ($change->getFileData(true, $max) as $file) {
                $depotFile = $isCaseSensitive ? $file['depotFile'] : strtolower($file['depotFile']);
                $affected += [$depotFile => ['left' => null, 'right' => null]];
                $file     += ['digest' => null, 'fileSize' => null];

                $affected[$depotFile][$i === 0 ? 'left' : 'right'] = $file;
            }
        }

        // we need to resort filesPaths after we build the affected array
        // in order to match the ordering we would get from getFileData
        ksort($affected, SORT_STRING);

        // because we merged files from two different changes, we need to re-apply max
        // otherwise we could end up returning more files than the caller requested
        array_splice($affected, $max);

        // assess what happened to each file - we are looking for three things:
        //  1. action - determine what the basic action was (add, edit, delete)
        //  2. diff   - determine what revs should be diffed (left vs. right)
        //  3. remove - if the file was not meaningfully affected, remove it
        //
        // the following table which shows how we treat each file given different
        // combinations of left/right actions:
        //
        //                        R I G H T
        //
        //                  |  A  |  E  |  D  |  X
        //             -----+-----+-----+-----+-----
        //               A  |  E  |  E  |  D  | D/R
        //         L   -----+-----+-----+-----+-----
        //         E     E  |  E  |  E  |  D  | E/R
        //         F   -----+-----+-----+-----+-----
        //         T     D  |  A  |  A  |  R  | A/R
        //             -----+-----+-----+-----+-----
        //               X  |  A  |  E  |  D  |
        //
        //    A = add
        //    E = edit
        //    D = delete
        //    X = not present
        //    R = remove (no difference)
        //  D/R = delete if left shelved, otherwise remove (no diff)
        //  E/R = reverse diff (edits undone) if left shelved, otherwise remove (no diff)
        //  A/R = add if left shelved, otherwise remove (no diff)
        //
        foreach ($affected as $depotFile => $file) {
            $action = null;
            // work out if we are dealing with a stream spec.
            $isStream = ($file['left']['type'] ?? false) === Stream::SPEC_TYPE
                || ($file['left']['type'] ?? false) === Stream::SPEC_TYPE;

            // for most cases, we diff using '#rev' for submits and '@=change' for shelves
            $diffLeft  = $left->isSubmitted()
                ? (isset($file['left'])  ? ($isStream ? '@' . $left->getId() :'#' . $file['left']['rev']) : null)
                : '@=' . $left->getId();
            $diffRight = $right->isSubmitted()
                ? (isset($file['right']) ? ($isStream ? '@' . $right->getId() :'#' . $file['right']['rev']) : null)
                : '@=' . $right->getId();

            // handle the cases where we have both a left and right side
            if (!$isStream && isset($file['left'], $file['right'])) {
                // check the digests - if they match and action doesn't involve delete, drop the file
                if ($file['left']['digest'] == $file['right']['digest']
                    && !$isDelete($file['left']['action']) && !$isDelete($file['right']['action'])
                ) {
                    unset($affected[$depotFile]);
                    continue;
                }

                // both deletes    = no diff, drop the file
                // delete on left  = add
                // delete on right = delete
                // add/edit combo  = edit
                if ($isDelete($file['left']['action']) && $isDelete($file['right']['action'])) {
                    unset($affected[$depotFile]);
                    continue;
                } elseif ($isDelete($file['left']['action'])) {
                    $action = 'add';
                } elseif ($isDelete($file['right']['action'])) {
                    $action = 'delete';
                } else {
                    $action = 'edit';
                }
            }

            // file only present on left
            if (!isset($file['right'])) {
                // if left hand change was committed, just drop the file
                // (the fact it's missing on the right means it's unchanged)
                if ($left->isSubmitted()) {
                    unset($affected[$depotFile]);
                    continue;
                }

                // since the left hand change is shelved, the absence of a file on
                // the right means whatever was done on the left, has been undone
                // therefore, we 'flip' the diff around
                // add    = delete
                // edit   = edit (edits undone)
                // delete = add
                if ($isAdd($file['left']['action'])) {
                    $action    = 'delete';
                    $diffRight = null;
                } elseif ($isEdit($file['left']['action'])) {
                    // edits going away, put the have-rev on the right
                    $action    = 'edit';
                    $diffRight = '#' . $file['left']['rev'];
                } else {
                    // file coming back, put have-rev on the right and clear the left
                    $action    = 'add';
                    $diffRight = '#' . $file['left']['rev'];
                    $diffLeft  = null;
                }
            }

            // file only present on right
            // if file is added, clear diff-left (nothing to diff against)
            // otherwise diff against have-rev for shelves and previous for commits
            if (!isset($file['left'])) {
                if ($isAdd($file['right']['action'])) {
                    $diffLeft = null;
                } else {
                    $diffLeft = $right->isSubmitted()
                        ? '#' . ($file['right']['rev'] - 1)
                        : '#' . $file['right']['rev'];
                }
            }

            // handled notice, coming while right is committed and no action and rev is
            // set in the $file['right'] for streams. This notice only appear in Unit test case
            if (isset($file['right']['type']) && $file['right']['type'] === Stream::SPEC_TYPE
                && !isset($file['right']['action'])) {
                $file['right']['action'] = 'edit';
            }
            if (isset($file['right']['type']) && $file['right']['type'] === Stream::SPEC_TYPE
                && !isset($file['right']['rev'])) {
                $file['right']['rev'] = null;
            }

            // action should default to the action of the right-hand file
            $action = $action ?: ($file['right'] ? $file['right']['action'] : null);

            // type should default to the right-hand file, but fallback to the left
            $type = $file['right']
                ? $file['right']['type']
                : (isset($file['left']['type']) ? $file['left']['type'] : null);

            // compose the file information to keep and return to the caller
            // we start with basic 'describe' output, and add in some useful bits
            // if we don't have a right-side, then we can't populate certain fields
            $file                 = [
                'depotFile' => $file[($file['right'] ? 'right' : 'left')]['depotFile'],
                'action'    => $action,
                'type'      => $type,
                'rev'       => $file['right'] ? $file['right']['rev']      : null,
                'fileSize'  => $file['right'] ? $file['right']['fileSize'] : null,
                'digest'    => $file['right'] ? $file['right']['digest']   : null,
                'isAdd'     => $isAdd($action),
                'isEdit'    => $isEdit($action),
                'isDelete'  => $isDelete($action),
                'diffLeft'  => $diffLeft,
                'diffRight' => $diffRight
            ];
            $affected[$depotFile] = $file;
        }

        return $affected;
    }

    /**
     * Gets the reviews current author avatar.
     *
     * @param   Review  $review     the review to get author for
     * @param   string|int          $size   the size of the avatar (e.g. 64, 128) (default=40)
     * @param   bool                $link   optional - link to the user (default=true)
     * @param   bool                $class  optional - class to add to the image
     * @param   bool                $fluid  optional - match avatar size to the container (default=false)
     * @return  array   single element
     */
    protected function getAuthorAvatar(
        Review $review,
        $size = 256,
        $link = true,
        $class = null,
        $fluid = false
    ) {
        $avatar = $this->services->get('ViewHelperManager')->get('avatar');

        return [$avatar($review->get('author'), $size, $link, $class, $fluid)];
    }

    /**
     * Gets a list of rendered html for avatars of users in this review.
     *
     * @param   Review  $review     the review to get avatars for
     * @param   string|int          $size   the size of the avatar (e.g. 64, 128) (default=40)
     * @param   bool                $link   optional - link to the user (default=true)
     * @param   bool                $class  optional - class to add to the image
     * @param   bool                $fluid  optional - match avatar size to the container (default=false)
     * @return  array   list of rendered avatars indexed by usernames
     */
    protected function getReviewAvatars(Review $review, $size = 40, $link = true, $class = null, $fluid = false)
    {
        $avatar  = $this->services->get('ViewHelperManager')->get('avatar');
        $avatars = [];
        foreach ($review->getParticipants() as $user) {
            // If the user is a group
            if (Group::isGroupName($user)) {
                // Get groupAvatar helper.
                $groupAvatar = $this->services->get('ViewHelperManager')->get('groupAvatar');
                // Get the group avatar and save it to the page.
                $avatars[$user] = $groupAvatar(Group::getGroupName($user), $size, $link, $class, $fluid);
            } else {
                $avatars[$user] = $avatar($user, $size, $link, $class, $fluid);
            }
        }
        return $avatars;
    }

    /**
     * Helper to ensure that the given review is accessible by the current user.
     *
     * @param   Review  $review     the review to check access for
     * @throws  ForbiddenException  if the current user can't access the review.
     */
    protected function restrictAccess(Review $review)
    {
        // access to the review depends on access to its head change
        if (!$this->services->get('reviews_filter')->canAccessChangesAndProjects($review)) {
            throw new ForbiddenException("You don't have permission to access this review.");
        }
    }

    /**
     * Add groups to a participant query.
     * @param $query
     * @param $p4Admin
     */
    private function addGroupsToFetch(&$query, Connection $p4Admin)
    {
        // We want to add groups if relevant to 'participants' or 'authorparticipants' (only 1 of them will be set for
        // any given query)
        $participantField = null;
        if ($query[Review::FIELD_PARTICIPANTS] && !empty($query[Review::FIELD_PARTICIPANTS])) {
            $participantField = Review::FIELD_PARTICIPANTS;
        } elseif ($query[Review::FETCH_BY_AUTHOR_PARTICIPANTS]
            && !empty($query[Review::FETCH_BY_AUTHOR_PARTICIPANTS])) {
            $participantField = Review::FETCH_BY_AUTHOR_PARTICIPANTS;
        }

        $groupDAO = $this->services->get(IModelDAO::GROUP_DAO);
        if ($participantField) {
            if (!is_array($query[$participantField])) {
                $query[$participantField] = [$query[ $participantField]];
            }
            foreach ($query[$participantField] as $participant) {
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
                            if (!in_array($swarmName, $query[$participantField])) {
                                array_push($query[$participantField], $swarmName);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Prepare FetchAll options for searching reviews based on a query
     *
     * @param  Parameters   $query      query parameters to build options from
     * @param  Connection   $p4Admin    P4 admin connection
     * @return array        the resulting options array
     * @throws \Exception
     */
    protected function getFetchAllOptions(Parameters $query, Connection $p4Admin)
    {
        $config  = $this->services->get('config');
        $boolean = new FormBoolean;
        $options = [
            Review::FETCH_MAXIMUM                => $config['reviews']['filters']['fetch-max'],
            Review::FETCH_MAX                    => $this->getFetchMaxOption($query, $config),
            Review::FETCH_AFTER                  => $query->get('after'),
            Review::FETCH_AFTER_SORTED           => $query->get('afterSorted'),
            Review::FETCH_AFTER_UPDATED          => $query->get('afterUpdated'),
            Review::FETCH_TOTAL_COUNT            => true,
            Review::FETCH_KEYWORDS_FIELDS        => ['participants', 'description', 'projects', 'id'],
            Review::FETCH_BY_AUTHOR              => $query->get('author'),
            Review::FETCH_BY_CHANGE              => $query->get('change'),
            Review::FETCH_BY_HAS_REVIEWER        => null,
            Review::FETCH_BY_IDS                 => $query->get('ids'),
            Review::FETCH_BY_KEYWORDS            => $query->get('keywords'),
            Review::FETCH_BY_PARTICIPANTS        => $query->get('participants'),
            Review::FETCH_BY_AUTHOR_PARTICIPANTS => $query->get(Review::FETCH_BY_AUTHOR_PARTICIPANTS),
            Review::FETCH_BY_PROJECT             => $query->get('project'),
            Review::FETCH_BY_STATE               => $query->get('state'),
            Review::FETCH_BY_TEST_STATUS         => null,
            Review::FETCH_BY_GROUP               => $query->get('group'),
            Review::FETCH_BY_NOT_UPDATED_SINCE   => DateParser::validateDate(
                $query->get(Review::FETCH_BY_NOT_UPDATED_SINCE)
            ) ? strtotime($query->get(Review::FETCH_BY_NOT_UPDATED_SINCE)): null,
            Review::FETCH_BY_HAS_VOTED           => $query->get(Review::FETCH_BY_HAS_VOTED),
            Review::FETCH_BY_USER_CONTEXT        => $query->get(Review::FETCH_BY_USER_CONTEXT),
            Review::FETCH_BY_MY_COMMENTS         => $query->get(Review::FETCH_BY_MY_COMMENTS)
        ];

        // Supported tuning options
        $postFetchFilters = [Review::FETCH_BY_HAS_VOTED, Review::FETCH_BY_MY_COMMENTS, Review::ORDER_BY_UPDATED];
        $configKeys       = [
            ['name' => 'fetch-max', 'option' => Review::FETCH_MAXIMUM, 'value' => null],
            ['name' => 'filter-max', 'option' => Review::FETCH_MAX, 'value' => null]
        ];
        foreach ($postFetchFilters as $filter) {
            // If filtering by this vector, tune the p4d interaction
            if ($query->offSetExists($filter)) {
                foreach ($configKeys as $idx => $limit) {
                    // Look for and set(high water mark) any configured tuning
                    if (isset($config['reviews']['filters'][$filter][$limit['name']]) &&
                        $config['reviews']['filters'][$filter][$limit['name']] > $limit['value']) {
                        $configKeys[$idx]['value'] =
                            $config['reviews']['filters'][$filter][$limit['name']];
                    }
                }
            }
        }
        // Copy the tuning into the data query options
        foreach ($configKeys as $limit) {
            if ($limit['value'] !== null) {
                $options[$limit['option']] = $limit['value'];
            }
        }

        if ($query->offsetExists('hasReviewers')) {
            $options[Review::FETCH_BY_HAS_REVIEWER] = $boolean->filter($query->get('hasReviewers')) ? '1' : '0';
        }

        if ($query->offsetExists('passesTests')) {
            $options[Review::FETCH_BY_TEST_STATUS] = $boolean->filter($query->get('passesTests')) ? 'pass' : 'fail';
        }
        $this->addGroupsToFetch($options, $p4Admin);

        // If project equals 'projects-for-user:NNN' then get that user's projects.
        // This is to avoid passing the project id for all projects in the URI,
        // which for a user with many projects, will exceed the request limits.
        // We still need to support queries such as reviews?project[]=acme from
        // the API where FETCH_BY_PROJECT will be an array - just let that pass
        // through
        if (is_string($options[Review::FETCH_BY_PROJECT]) &&
            preg_match('/^projects-for-user:(.+)$/', $options[Review::FETCH_BY_PROJECT], $matches)) {
            $userId     = $matches[1];
            $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
            $projects   = $projectDAO->fetchAll([], $p4Admin);
            $groupDAO   = $this->services->get(IModelDAO::GROUP_DAO);
            $allGroups  = $groupDAO->fetchAll([], $p4Admin)->toArray(true);
            // filter out private projects
            $projects   = $this->services->get('projects_filter')->filter($projects);
            $user       = $this->services->get('user');
            $myProjects = (array)$projects->filterByCallback(
                function (ProjectModel $project) use ($user, $allGroups) {
                    return $project->isInvolved($user, null, $allGroups);
                }
            )->invoke('getId');

            $options[Review::FETCH_BY_PROJECT] = $myProjects;
        }

        // eliminate blank values to avoid potential side effects
        return array_filter(
            $options,
            function ($value) {
                return is_array($value) ? count($value) : strlen($value);
            }
        );
    }

    /**
     * Prepare FetchAll options for searching reviews based on a query
     *
     * @param  Parameters   $query  query parameters to build options from
     * @param  Parameters   $config map containing the configuration options
     * @return array        the resulting options array
     */
    protected function getFetchMaxOption(Parameters $query, $config = null)
    {
        if (!$config) {
            $config = $this->services->get('config');
        }
        return $query->get('max', $config['reviews']['filters']['filter-max']);
    }

    /**
     * Forward the add review action to the given Swarm host
     *
     * @param   string  $url    the url of the Swarm host to forward to
     * @return  JsonModel       a JSON model instance with the response data
     */
    protected function forwardAdd($url)
    {
        $identity = $this->services->get('auth')->getIdentity() + ['id' => null, 'ticket' => null];
        $url      = trim($url, '/') . '/review/add';
        $client   = new HttpClient;

        $client->setUri($url)
               ->setMethod(HttpRequest::METHOD_POST)
               ->setAuth($identity['id'], $identity['ticket'])
               ->getRequest()
               ->setPost($this->getRequest()->getPost())
               ->setQuery($this->getRequest()->getQuery());

        // set the http client options; including any special overrides for our host
        $options = $this->services->get('config') + ['http_client_options' => []];
        $options = (array) $options['http_client_options'];
        if (isset($options['hosts'][$client->getUri()->getHost()])) {
            $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
        }
        unset($options['hosts']);
        $client->setOptions($options);

        // return the remote response as a new JSON model
        $response = $client->dispatch($client->getRequest());
        $this->getResponse()->setStatusCode($response->getStatusCode());
        return new JsonModel(json_decode($response->getBody(), true));
    }
}
