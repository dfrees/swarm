<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Activity\Model\Activity;
use Api\AbstractApiController;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Option;
use Application\Filter\FormBoolean;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Permissions;
use Comments\Model\Comment;
use Comments\Module as commentsModule;
use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Reviews\Filter\VoteValidator;
use Reviews\Model\Review;
use Reviews\Validator\Transitions;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;

/**
 * Swarm Reviews
 */
class ReviewsController extends AbstractApiController
{
    // Query parameter constants
    const MODE = 'mode';

    /**
     * Get the requested review; with an option to limit the fields returned
     * @param   mixed   $id
     * @return  JsonModel
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery(self::FIELDS);
        $result = $this->forward(\Reviews\Controller\IndexController::class, 'review', ['review' => $id]);

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(['review' => $result->getVariable('review')], $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Create a new review using the provided data
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function create($data)
    {
        $post = [
            'change'      => isset($data['change'])      ? $data['change']      : null,
            'description' => isset($data['description']) ? $data['description'] : null,
            'reviewers'   => isset($data['reviewers'])   ? $data['reviewers']   : null
        ];

        // if the api is 1.1 or newer, include required reviewers
        if ($this->getEvent()->getRouteMatch()->getParam('version') !== "v1") {
            $post['requiredReviewers'] = isset($data['requiredReviewers']) ? $data['requiredReviewers'] : null;
        }
        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        // REVIEWER_GROUPS are only supported in API v7 and up
        if (isset($data[Review::REVIEWER_GROUPS])) {
            if (in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'])) {
                $this->response->setStatusCode(405);
                return $this->prepareErrorModel(
                    new JsonModel(
                        [
                            'error' => Review::REVIEWER_GROUPS . ' parameter is only supported for v7+ of the API'
                        ]
                    )
                );
            } else {
                $post[Review::REVIEWER_GROUPS] = $data[Review::REVIEWER_GROUPS];
                $this->translateReviewerGroups($post);
            }
        }

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'add',
            null,
            null,
            $post
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Link a change to a review
     * @return  JsonModel
     */
    public function addChangeAction()
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();

        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }

        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        $review  = $this->getEvent()->getRouteMatch()->getParam('id');
        $change  = $request->getPost('change');
        $mode    = $request->getPost('mode');
        if (isset($mode)) {
            if (in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8'])) {
                $this->response->setStatusCode(405);
                return $this->prepareErrorModel(
                    new JsonModel(
                        [
                            'error' => ReviewsController::MODE . ' parameter is only supported for v9+ of the API'
                        ]
                    )
                );
            }
        }

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'add',
            null,
            null,
            ['id' => $review, 'change' => $change, ReviewsController::MODE => $mode]
        );

        if (!$result->getVariable('isValid')) {
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            // the legacy endpoint returns 404 for a bad change, which is technically incorrect
            // as 404's refer specifically to invalid URIs and change is not in the URI
            if ($response->getStatusCode() === 404 && strlen($result->getVariable('change'))) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Update the state of a review
     * @return  JsonModel
     */
    public function stateAction()
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();

        // this method is not inherently limited to patch, so we check it explicitly
        if (!$request->isPatch()) {
            $response->setStatusCode(405);
            return;
        }

        $review      = $this->getEvent()->getRouteMatch()->getParam('id');
        $data        = $this->processBodyContent($request);
        $state       = isset($data['state'])       ? $data['state']       : null;
        $commit      = isset($data['commit'])      ? $data['commit']      : false;
        $wait        = isset($data['wait'])        ? $data['wait']        : true;
        $description = isset($data['description']) ? $data['description'] : null;
        $jobs        = isset($data['jobs'])        ? $data['jobs']        : null;
        $fixStatus   = isset($data['fixStatus'])   ? $data['fixStatus']   : null;

        $request->setMethod(Request::METHOD_POST);
        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'transition',
            [
                'review'        => $review,
                'disableCommit' => !$commit,
            ],
            null,
            [
                'wait'        => $wait,
                'state'       => $state . ($commit ? ':commit' : ''),
                'description' => $description,
                'jobs'        => $jobs,
                'fixStatus'   => $fixStatus,
            ]
        );

        if (!$result->getVariable('isValid')) {
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * List the reviews that appear on the current user's dashboard, with support for pagination
     * @return  JsonModel
     */
    public function dashboardAction()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery(self::FIELDS);
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        $query = [
                'after'       => $request->getQuery(self::AFTER),
                'disableHtml' => true,
                'max'         => $request->getQuery(self::MAX, 1000),
        ];

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'dashboard',
            null,
            $query
        );

        return $this->prepareSuccessModel(
            [
                'lastSeen'   => $result->getVariable('lastSeen'),
                'reviews'    => $result->getVariable('reviews'),
                'totalCount' => $result->getVariable('totalCount')
            ],
            $fields
        );
    }

    /**
     * Get a list of reviews, with support for pagination and limiting the output fields
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery(self::FIELDS);
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $filters = [
            'change', 'hasReviewers', 'ids', 'keywords', 'participants', 'project', 'state', 'passesTests'
        ];

        // add the author filtering feature for API versions v1.2+
        if (!in_array($version, ['v1', 'v1.1'])) {
            array_push($filters, 'author');
        }

        // add notUpdatedSince, hasVoted and myComments reviews filtering feature for API versions v6+
        if (!in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5'])) {
            array_push(
                $filters,
                Review::FETCH_BY_NOT_UPDATED_SINCE,
                Review::FETCH_BY_HAS_VOTED,
                Review::FETCH_BY_MY_COMMENTS
            );
        }

        $query = [
            'after'       => $request->getQuery(self::AFTER),
            'disableHtml' => true,
            'max'         => $request->getQuery(self::MAX, 1000),
            ] + array_intersect_key((array) $request->getQuery(), array_flip($filters));

        // This is specifically for backwards compatibility with old API calls. hasVoted is a string in this case and if
        // an invalid value was set it was allowed but ignored. Here we see if the value is acceptable and if not it is
        // removed so the net affect is no filter on hasVoted keeping the behaviour as before.
        if (isset($query[Review::FETCH_BY_HAS_VOTED])) {
            $hasVoted = array_intersect(
                VoteValidator::VALID_FILTERS,
                (array)strtolower($query[Review::FETCH_BY_HAS_VOTED])
            );
            if ($hasVoted) {
                $query[Review::FETCH_BY_HAS_VOTED] = current($hasVoted);
            } else {
                unset($query[Review::FETCH_BY_HAS_VOTED]);
            }
        }

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'index',
            null,
            $query
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(
                [
                    'lastSeen'   => $result->getVariable('lastSeen'),
                    'reviews'    => $result->getVariable('reviews'),
                    'totalCount' => $result->getVariable('totalCount')
                ],
                $fields
            )
            : $this->prepareErrorModel($result);
    }

    /**
     * Modify part of an existing review, replacing the content of any field names provided with the new values
     * @param mixed $id     review to edit
     * @param mixed $data   changes to apply
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $request = $this->getRequest();
        $data    = array_merge($data, $request->getPost()->toArray());
        $version = $this->getEvent()->getRouteMatch()->getParam('version');

        // this endpoint is only supported in API v5 and up
        if (in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4'])) {
            $this->response->setStatusCode(405);

            return $this->prepareErrorModel(
                new JsonModel(
                    [
                        'error' => 'Method Not Allowed'
                    ]
                )
            );
        }
        // REVIEWER_GROUPS are only supported in API v7 and up
        if (isset($data[Review::REVIEWER_GROUPS])) {
            if (in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6'])) {
                $this->response->setStatusCode(405);
                return $this->prepareErrorModel(
                    new JsonModel(
                        [
                            'error' => Review::REVIEWER_GROUPS . ' parameter is only supported for v7+ of the API'
                        ]
                    )
                );
            } else {
                $this->translateReviewerGroups($data);
            }
        }

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'review',
            ['review' => $id],
            null,
            $data
        );

        if (!isset($result->isValid) || !$result->isValid) {
            $response = $this->getResponse();
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of review data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits review/reviews to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // some legacy endpoints include fields we don't want
        unset(
            $model->id,
            $model->messages,
            $model->avatars,
            $model->description,
            $model->canEditReviewers,
            $model->authorAvatar
        );

        // make adjustments to 'review' entity if present
        if ($model->getVariable('review')) {
            $model->setVariable('review', $this->normalizeReview($model->getVariable('review'), $limitEntityFields));
        }

        // if a list of reviews is present, normalize each one
        $reviews = $model->getVariable('reviews');
        if ($reviews) {
            foreach ($reviews as $key => $review) {
                $reviews[$key] = $this->normalizeReview($review, $limitEntityFields);
            }

            $model->setVariable('reviews', $reviews);
        }

        // API does not allow the 'approved:commit' transition (use commit param instead)
        $transitions = $model->getVariable('transitions');
        if ($transitions) {
            unset($transitions[Review::STATE_APPROVED . ':commit']);
            $model->setVariable('transitions', $transitions);
        }

        return $model;
    }

    protected function normalizeReview($review, $limitEntityFields = null)
    {
        // clobber redundant 'participants' field with more informative 'participantsData'
        if (isset($review['participants'], $review['participantsData'])) {
            $review['participants'] = $review['participantsData'];
            unset($review['participantsData']);
        }
        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        $v7plus  = !in_array($version, ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6']);
        // Return any participant groups as part of Review::FIELD_PARTICIPANTS_GROUPS with the name
        // that would be returned by 'p4 groups' (not with the 'swarm-group-' prefix) only do this for v7
        // onwards
        if ($v7plus) {
            $review[Review::REVIEWER_GROUPS] = [];
        }
        if (isset($review[Review::FIELD_PARTICIPANTS])) {
            $participants = $review[Review::FIELD_PARTICIPANTS];
            foreach ($participants as $key => $value) {
                $stringKey = (string)$key;
                $stripped  = Group::getGroupName($stringKey);

                if ($stripped !== $stringKey) {
                    // Populate reviewerGroups if v7+ otherwise strip the groups from participants
                    if ($v7plus) {
                        // It starts with 'swarm-group-' remove it from participants and add it to
                        // participant groups
                        $review[Review::REVIEWER_GROUPS] =
                            array_merge($review[Review::REVIEWER_GROUPS], [$stripped => $value]);
                        if (isset($review[Review::REVIEWER_GROUPS][$stripped]['required'])) {
                            $requiredField = $review[Review::REVIEWER_GROUPS][$stripped]['required'];

                            // If required is a number translate it to true and set a quorum field with the number
                            if (!in_array($requiredField, [true, "true", false, "false"], true)) {
                                $review[Review::REVIEWER_GROUPS][$stripped]['required']     = true;
                                $review[Review::REVIEWER_GROUPS][$stripped][Review::QUORUM] = $requiredField;
                            }
                        }
                    }
                    unset($review[Review::FIELD_PARTICIPANTS][$key]);
                }
            }
        }

        // several fields returned by the legacy endpoints are inconsistent/inappropriate for the api
        unset(
            $review['authorAvatar'],
            $review['createDate'],
            $review['downVotes'],
            $review['hasReviewer'],
            $review['upVotes']
        );

        // limit and re-order fields for aesthetics/consistency
        $review = $this->limitEntityFields($review, $limitEntityFields);
        $review = $this->sortEntityFields($review);

        return $review;
    }

    /**
     * Translate 'reviewerGroups' parameter to values in 'reviewers', 'requiredReviewers' and 'reviewerQuorums'.
     * @param $post post params
     */
    private function translateReviewerGroups(&$post)
    {
        if (isset($post[Review::REVIEWER_GROUPS])) {
            foreach ($post[Review::REVIEWER_GROUPS] as $key => $values) {
                if ($values && !empty($values)) {
                    $field     = Review::REVIEWERS;
                    $groupData = GroupConfig::KEY_PREFIX . $values['name'];
                    if (isset($values['required'])) {
                        if (isset($values[Review::QUORUM])) {
                            $field = Review::REVIEWER_QUORUMS;
                            if (!isset($post[$field])) {
                                $post[$field] = [$groupData => $values[ Review::QUORUM]];
                            } else {
                                $post[$field][$groupData] = $values[Review::QUORUM];
                            }
                            // Quorum reviewers must also be in required
                            if (!isset($post[Review::REQUIRED_REVIEWERS])) {
                                $post[Review::REQUIRED_REVIEWERS] = [];
                            }
                            array_push(
                                $post[Review::REQUIRED_REVIEWERS],
                                GroupConfig::KEY_PREFIX . $values['name']
                            );
                            continue;
                        }
                        $field = Review::REQUIRED_REVIEWERS;
                    }
                    if (!isset($post[$field])) {
                        $post[$field] = [];
                    }
                    array_push($post[$field], $groupData);
                }
            }
            unset($post[Review::REVIEWER_GROUPS]);
        }
    }

    /**
     * Transition a review to an archived state
     * @return  JsonModel
     */
    public function archiveInactiveAction()
    {
        $request     = $this->getRequest();
        $response    = $this->getResponse();
        $version     = $this->getEvent()->getRouteMatch()->getParam('version');
        $description = $request->getPost('description');
        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }
        $query = [
            Review::FETCH_BY_NOT_UPDATED_SINCE => $request->getPost(Review::FETCH_BY_NOT_UPDATED_SINCE),
            'disableHtml' => true,
        ];

        $result = $this->forward(
            \Reviews\Controller\IndexController::class,
            'archiveIndex',
            null,
            $query
        );

        $valid = $result->getVariable('isValid');
        if ($valid === false) {
            $response = $this->getResponse();
            // make sure the response indicates everything is not OK, without overwriting an existing error code
            if ($response->isOk()) {
                $response->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        $reviews         = $result->getVariable('reviews');
        $archivedReviews = [];
        $failedReviews   = [];
        foreach ($reviews as $review) {
            if (!($review[Review::FETCH_BY_STATE] == Review::STATE_ARCHIVED)) {
                $transitionResult = $this->forward(
                    \Reviews\Controller\IndexController::class,
                    'transition',
                    [
                        'review' => $review[Review::FIELD_ID],
                    ],
                    null,
                    [
                        'state' => Review::STATE_ARCHIVED,
                        'description' => $description,
                    ]
                );

                $valid = $transitionResult->getVariable('isValid');
                if ($valid === false) {
                    $transitionResult->setVariable('error', 'Failed to archive');
                    $model           = $this->prepareErrorModel($transitionResult);
                    $reviewArray     = $transitionResult->getVariable('review');
                    $failedReviews[] = [
                        'error'  => $model->getVariable('details'),
                        'review' => $reviewArray['id']
                    ];
                } else {
                    $archivedReviews[] = $this->prepareSuccessModel($transitionResult)->getVariable('review');
                }
            }
        }
        return new JsonModel(
            [
                'archivedReviews' => $archivedReviews,
                'failedReviews'   => $failedReviews
            ]
        );
    }

    /**
     * Clean the changelists attached to an approved/archived review, re-opening any files as appropriate
     * @throws RecordNotFoundException
     * @return  JsonModel
     */
    public function cleanupAction()
    {
        $boolean  = new FormBoolean;
        $request  = $this->getRequest();
        $response = $this->getResponse();
        // this method is not inherently limited to post, so we check it explicitly
        if (!$request->isPost()) {
            $response->setStatusCode(405);
            return;
        }
        $reopen   = $boolean->filter($request->getPost('reopen'));
        $services = $this->services;
        $p4User   = $services->get('p4_user');
        $reviewID = $this->getEvent()->getRouteMatch()->getParam('id');
        $logger   = $services->get('logger');

        $returnData = null;

        $incomplete = [];
        if ($p4User->isSuperUser()) {
            if (Review::exists($reviewID, $p4User)) {
                $review = Review::fetch($reviewID, $p4User);
                switch ($review->getState()) {
                    case Review::STATE_APPROVED:
                    case Review::STATE_ARCHIVED:
                        $logger->notice("Cleaning up the pending changelists from API.");
                        $returnData = $review->cleanup(['reopen' => $reopen], $p4User);
                        break;

                    default:
                        $incomplete[$reviewID][] = 'Review is in state (' . $review->getState() .
                            '). Only approved and archived reviews can be cleaned up.';
                        break;
                }
            } else {
                $incomplete[$reviewID][] = 'Review (' . $reviewID . ') not found';
            }
            $response->setStatusCode(200);
        } else {
            $response->setStatusCode(401);
            $incomplete[$reviewID][] = 'You must be a super user to run this operation';
        }
        // Return Json data.
        return new JsonModel(
            $returnData === null ? ['complete' => [], 'incomplete' => $incomplete] : $returnData
        );
    }

    /**
     * Vote a version of a review up/down
     * @return  JsonModel
     */
    public function voteAction()
    {
        // This is the vote action where the API controller forwards to the Web controller.
        // Due to architecture constraints and the amount of work carried out in the
        // IndexController->editReview we have to do it this way until refactoring to have
        // the API controller as being the source of truth.
        $services = $this->services;
        $services->get('permissions')->enforce('authenticated');
        $translator = $services->get('translator');
        $request    = $this->getRequest();
        $id         = $this->getEvent()->getRouteMatch()->getParam('id');
        $user       = $services->get('user');
        $valid      = true;
        $messages   = null;

        if ($this->requestHasContentType($request, self::CONTENT_TYPE_JSON)) {
            $data = json_decode($request->getContent(), true);
        } else {
            $data = $request->getPost()->toArray();
        }
        if (isset($id) && $data && isset($data['vote'])) {
            $data['user'] = $user->getId();
            if (isset($data['vote']['version'])) {
                $data['version'] = $data['vote']['version'];
                unset($data['vote']['version']);
            }
            try {
                $result = $this->forward(
                    \Reviews\Controller\IndexController::class,
                    'vote',
                    ['review' => $id, 'vote' => $data[ 'vote'][ 'value']],
                    null,
                    $data
                );
                $valid  = isset($result->isValid) && $result->isValid;
                if ($valid) {
                    $messages = [
                        $translator->t(
                            sprintf(
                                'User %s set vote to %s on review %s',
                                $user->getId(),
                                $data['vote']['value'],
                                $id
                            )
                        )
                    ];
                } else {
                    $response = $this->getResponse();
                    if ($response->isClientError() || $response->isServerError()) {
                        $model = $this->prepareErrorModel($result);
                    } else {
                        // Legacy requires that we prepare the model using 404 to flatten details but that
                        // calls are really seen as 200
                        $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                        $model = $this->prepareErrorModel($result);
                        $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
                    }
                    $messages = $model->getVariable('details')?:(array)$model->getVariable('error');
                }
            } catch (ForbiddenException $fe) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $messages = [$translator->t('You do not have permission to access this review')];
                $valid    = false;
            }
        } else {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $messages = [$translator->t('Invalid request')];
        }
        return new JsonModel(
            [
                'isValid'  => $valid,
                'messages' => $messages
            ]
        );
    }

    /**
     * Remove the Swarm data related to a review
     * @return JsonModel
     * @throws \Application\Config\ConfigException
     * @throws \Record\Exception\Exception
     */
    public function obliterateAction()
    {
        $services   = $this->services;
        $config     = $services->get('config');
        $logger     = $services->get('logger');
        $p4Admin    = $services->get('p4_admin');
        $p4User     = $services->get('p4_user');
        $translator = $services->get('translator');
        $route      = $this->getEvent()->getRouteMatch();
        $id         = (int) $route->getParam('id');
        $request    = $this->getRequest();
        $message    = $translator->t('Failed to Obliterate review'); // Shouldn't get this but incase of failure.
        $code       = Response::STATUS_CODE_400;

        $allowAuthorObliterate = ConfigManager::getValue($config, ConfigManager::REVIEWS_ALLOW_AUTHOR_OBLITERATE);
        // request must be a post
        if (!$request->isPost()) {
            return new JsonModel(
                [
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST required.'),
                    'code'      => $code
                ]
            );
        }

        // We first fetch the review and obliterate any data retaining to it.
        try {
            $review = Review::fetch($id, $p4Admin);
            try {
                // Check if the user has access to the given files and who the review author is.
                if (!$this->services->get('reviews_filter')->canAccessChangesAndProjects($review)) {
                    throw new ForbiddenException("You don't have permission to access this review.");
                }
            } catch (ForbiddenException $forbidden) {
                return new JsonModel(
                    [
                        'isValid' => false,
                        'error' => $translator->t("You don't have permission to access this review."),
                        'code'    => Response::STATUS_CODE_401
                    ]
                );
            }
            $author = $review->get('author');
            // Now check if the user making the request is Admin or above permissions or the author is
            // allowed to obliterate their own review.
            if (($allowAuthorObliterate && $author === $p4User->getUser())
                || $p4User->isAdminUser() || $p4User->isSuperUser()) {
                // Obliterate the review
                $obliterateMessages = $this->obliterateReview($services, $review);
                if (!empty($ObliterateMessages)) {
                    return new JsonModel(
                        [
                            'isValid' => true,
                            'message' => implode(", ", $obliterateMessages),
                            'code'    => Response::STATUS_CODE_400
                        ]
                    );
                }
                $logger->notice(
                    Review::REVIEW_OBLITERATE . "Review " . $id . " has been Obliterated by user "
                    . $p4User->getUser()
                );

                return new JsonModel(
                    [
                        'isValid' => true,
                        'message' => $translator->t('The review with id [%s] has been obliterated.', [$id]),
                        'code'    => Response::STATUS_CODE_200
                    ]
                );
            } else {
                $message = $translator->t(
                    'Failed to Obliterate the review, you need to have admin privileges or be the review author with '
                    .'"allow_author_obliterate" set to true.'
                );
                $code    = Response::STATUS_CODE_400;
            }
        } catch (RecordNotFoundException $e) {
            $message = $translator->t(
                "Review [%s] can not be found. User attempting to Obliterate the review was %s",
                [
                    $id, $p4User->getUser()
                ]
            );
            $code    = Response::STATUS_CODE_400;
            $logger->err(
                Review::REVIEW_OBLITERATE .$message
            );
        }
        // If we don't return before now we have not been successfully.
        return new JsonModel(
            [
                'isValid' => false,
                'error' => $message,
                'code'    => $code
            ]
        );
    }

    /**
     * This is the main function to call obliterate.
     *
     * @param object $services  This is the service provider
     * @param Review $review    This is the Review object.
     *
     * @return array
     */
    private function obliterateReview($services, Review $review)
    {
        $messages = [];
        try {
            $obliterateMessages = $review->obliterate();
            if (!empty($obliterateMessages)) {
                $messages[] = $obliterateMessages;
            }
            $reviewRelatedMessages = $this->obliterateReviewRelatedObjects($services, $review->getId());
            if (!empty($reviewRelatedMessages)) {
                $messages[] = $reviewRelatedMessages;
            }
        } catch (\Exception $e) {
            $messages[] = [$e->getMessage()];
        }
        return $messages;
    }

    /**
     * Obliterate all other data that is linked to the review like:
     * Comments and activity streams.
     *
     * @param  object  $services    This is the service provider
     * @param  int     $id          This is the Review ID.
     * @throws \Exception
     * @return  array               This is the messages.
     */
    private function obliterateReviewRelatedObjects($services, $id)
    {
        $p4Admin        = $services->get('p4_admin');
        $queue          = $services->get('queue');
        $p4User         = $services->get('p4_user');
        $logger         = $services->get('logger');
        $commentTopic   = 'review/' . $id;
        $activityStream = 'review-' . $id;
        $messages       = [];

        // Delete all the comments
        try {
            $comments = Comment::fetchAll([Comment::FETCH_BY_TOPIC => $commentTopic], $p4Admin);
            foreach ($comments as $comment) {
                // We need to ensure there are no future task for each of these comments.
                $hash = CommentsModule::getFutureCommentNotificationHash(
                    $commentTopic,
                    $comment->get('user')
                );
                $queue->deleteTasksByHash($hash);
                // Now delete the comment related.
                $comment->delete();
            }
            $logger->notice(
                "Review " . $id . " comments have been Obliterated by user " . $p4User->getUser()
            );
        } catch (\Exception $e) {
            $messages[] = $e->getMessage();
            $logger->trace(
                Review::REVIEW_OBLITERATE . "Review " . $id . " found an issue with Comments removal "
                . $e->getMessage()
            );
        }

        // Delete all the activity streams
        try {
            $reviewActivity = Activity::fetchAll([Activity::FETCH_BY_STREAM => $activityStream], $p4Admin);
            foreach ($reviewActivity as $activity) {
                $activity->delete();
            }
            $logger->notice(
                "Review " . $id . " activity's have been Obliterated by user ". $p4User->getUser()
            );
        } catch (\Exception $e) {
            $messages[] = $e->getMessage();
            $logger->trace(
                Review::REVIEW_OBLITERATE . "Review " . $id . " found an issue with Activity removal "
                . $e->getMessage()
            );
        }
        return $messages;
    }

    /**
     * Get the transitions which are available for the current state of the given review
     * @return  JsonModel
     */
    public function transitionsAction()
    {
        $services = $this->services;
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $p4User   = $services->get(ConnectionFactory::USER);
        $route    = $this->getEvent()->getRouteMatch();
        $id       = $route->getParam('id');
        $errors   = null;
        try {
            $review      = Review::fetch($id, $p4Admin);
            $services    = $this->services;
            $permissions = $services->get(Permissions::PERMISSIONS);
            // if you aren't authenticated you aren't allowed to edit
            $permissions->enforce(Permissions::AUTHENTICATED);

            // Pre-pare data for checking transitions for this review.
            $userId   = $p4User->getId();
            $request  = $this->getRequest();
            $upVoters = $request->getQuery(self::UP_VOTERS);
            $options  = [
                Option::USER_ID           => $userId,
                Transitions::OPT_UP_VOTES => $upVoters ? explode(',', $upVoters) : [],
                Transitions::REVIEW       => $review
            ];

            $reviewTransitions = $services->build(Services::TRANSITIONS, $options);

            // Then return only the states this review can move to next.
            return new JsonModel(
                [
                    'isValid' => true,
                    'transitions' => $reviewTransitions->getAllowedTransitions()
                ]
            );
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$e->getMessage()];
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = [$e->getMessage()];
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $errors = [$e->getMessage()];
        }

        return new JsonModel(
            [
                'isValid'  => false,
                'messages' => $errors,
            ]
        );
    }
}
