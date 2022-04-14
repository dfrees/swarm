<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Model;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Helper\ArrayHelper;
use Application\Model\IdTrait;
use Application\Model\ServicesModelTrait;
use Application\Service\P4Command;
use Changes\Service\IChangeComparator;
use Groups\Model\Group;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Log\Logger;
use P4\Model\Fielded\Iterator;
use P4\Spec\Client;
use P4\Spec\Depot;
use P4\Spec\Exception\NotFoundException;
use P4\Spec\Change;
use P4\Uuid\Uuid;
use Psr\SimpleCache\InvalidArgumentException;
use Record\Key\AbstractKey;
use Record\Key\AbstractKey as KeyRecord;
use Reviews\ReviewTrait;
use Users\Model\User;
use Comments\Model\Comment;
use Projects\Filter\ProjectList as ProjectListFilter;
use Record\Exception\Exception;
use Workflow\Model\IWorkflow;
use Reviews\Filter\VoteValidator;
use Record\Exception\NotFoundException as RecordNotFoundException;
use P4\Exception as P4Exception;

/**
 * Provides persistent storage and indexing of reviews.
 */
class Review extends KeyRecord implements IReview
{
    use ServicesModelTrait;
    use IdTrait;
    use ReviewTrait;

    const KEY_PREFIX    = 'swarm-review-';
    const UPGRADE_LEVEL = 5;

    protected $userObjects = [];
    protected $fields      = [
        'type'          => [
            self::ACCESSOR      => 'getType',
            'readOnly'      => true
        ],
        'changes'       => [       // changes associated with this review
                                   self::INDEX         => 1301,
                                   self::ACCESSOR      => 'getChanges',
                                   self::MUTATOR       => 'setChanges'
        ],
        'commits'       => [
            self::ACCESSOR      => 'getCommits',
            self::MUTATOR       => 'setCommits'
        ],
        'versions'      => [
            'hidden'        => true,
            self::ACCESSOR      => 'getVersions',
            self::MUTATOR       => 'setVersions'
        ],
        'author'        => [       // author of code under review
                                   self::INDEX         => 1302
        ],
        Review::FIELD_APPROVALS,
        self::FIELD_PARTICIPANTS  => [  // anyone who has touched the review (workflow change, commented on, etc.)
                self::INDEX         => 1304,// we return just user ids but properties (e.g. votes) are stored here too
                'indexOnlyKeys' => true,
                self::ACCESSOR      => 'getParticipants',
                self::MUTATOR       => 'setParticipants'
        ],
        'participantsData' => [
            self::ACCESSOR      => 'getParticipantsData',
            self::MUTATOR       => 'setParticipantsData',
            'unstored'      => true
        ],
        'hasReviewer'   => [       // flag to indicate if review has one or more reviewers
                                   self::INDEX         => 1305     // necessary to avoid using wildcards in p4 search
        ],
        'description'   => [       // change description
                                   self::ACCESSOR      => 'getDescription',
                                   self::MUTATOR       => 'setDescription',
                                   self::INDEX         => 1306,
                                   'indexWords'    => true
        ],
        'created',                      // timestamp when the review was created
        'updated',                      // timestamp when the review was last updated
        'projects'      => [       // an array with project id's as keys and branches as values
                                   self::INDEX         => 1307,
                                   self::ACCESSOR      => 'getProjects',
                                   self::MUTATOR       => 'setProjects'
        ],
        'state'         => [       // one of: needsReview, needsRevision, approved, rejected
                                   self::INDEX         => 1308,
                                   'default'       => 'needsReview',
                                   self::ACCESSOR      => 'getState',
                                   self::MUTATOR       => 'setState'
        ],
        self::FIELD_STATE_LABEL    => [
            self::ACCESSOR      => 'getStateLabel',
            'unstored'      => true
        ],
        'testStatus'    => [       // one of: pass, fail
            self::INDEX         => 1309,
            self::ACCESSOR      => 'getTestStatus',
            self::MUTATOR       => 'setTestStatus'
        ],
        self::FIELD_PREVIOUS_TEST_STATUS => [
            self::ACCESSOR      => 'getPreviousTestStatus',
            self::MUTATOR       => 'setPreviousTestStatus'
        ],
        'testDetails'   => [
            self::ACCESSOR      => 'getTestDetails',
            self::MUTATOR       => 'setTestDetails'
        ],
        self::FIELD_DEPLOY_STATUS,                 // one of: success, fail
        'deployDetails' => [
            self::ACCESSOR      => 'getDeployDetails',
            self::MUTATOR       => 'setDeployDetails'
        ],
        'pending'       => [
            self::INDEX         => 1310,
            self::ACCESSOR      => 'isPending',
            self::MUTATOR       => 'setPending'
        ],
        'commitStatus'  => [
            self::ACCESSOR      => 'getCommitStatus',
            self::MUTATOR       => 'setCommitStatus'
        ],
        'token'         => [
            self::ACCESSOR      => 'getToken',
            self::MUTATOR       => 'setToken',
            'hidden'        => true
        ],
        'upgrade'       => [
            self::ACCESSOR      => 'getUpgrade',
            'hidden'        => true
        ],
        'groups'        => [       // an array with associated groups
                                   self::INDEX         => 1311,
                                   self::ACCESSOR      => 'getGroups',
                                   self::MUTATOR       => 'setGroups'
        ],
        'updated'       => [       // fetch review by updated time.
                                   self::INDEX         => 1313,
                                   'indexer'       => 'getEpochDay'
        ],
        self::FIELD_COMPLEXITY  => [
            self::ACCESSOR      => 'getComplexity',
            self::MUTATOR       => 'setComplexity'
        ]
    ];

    /**
     * Work out if the user has voted taking into account stale votes
     * @param array     $votes      the votes
     * @param string    $userName   the user
     * @return bool true if the user has voted and the vote is not stale
     */
    private static function hasVoted($votes, $userName)
    {
        return !empty($votes) && array_key_exists($userName, $votes) && $votes[$userName]['isStale'] === false;
    }

    /**
     * Work out if the user has not voted taking into account stale votes
     * @param array     $votes      the votes
     * @param string    $userName   the user
     * @return bool true if the user has not voted and the vote is not stale
     */
    private static function hasNotVoted($votes, $userName)
    {
        $hasNotVoted = $votes == null || empty($votes);
        if (!$hasNotVoted) {
            $hasNotVoted = !array_key_exists($userName, $votes)
                || $votes[$userName]['isStale'] === true;
        }
        return $hasNotVoted;
    }

    /**
     * Test to see if the review should be included based on the votes, hasVoted and the given user.
     * @param mixed     $review     the review
     * @param mixed     $hasVoted   the value(s) for hasVoted
     * @param string    $userName   the user name
     * @return bool
     */
    public static function shouldIncludeForHasVoted($review, $hasVoted, $userName)
    {
        $includeNotVoted = false;
        $includeVoted    = false;
        $votes           = [];
        $hasVoted        = (array)$hasVoted;
        if (in_array(VoteValidator::VOTE_UP, $hasVoted)) {
            $votes = $review->getUpVotes();
        }
        if (in_array(VoteValidator::VOTE_DOWN, $hasVoted)) {
            $votes = array_merge($votes, $review->getDownVotes());
        }
        if ($votes) {
            $includeVoted = static::hasVoted($votes, $userName);
        }
        if (in_array(VoteValidator::VOTE_NONE, $hasVoted)) {
            $includeNotVoted = static::hasNotVoted($review->getVotes(), $userName);
        }
        return $includeVoted || $includeNotVoted;
    }

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by various fields.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  supported options are:
     *                                  FETCH_BY_CHANGE       - set to a 'changes' value(s) to limit results
     *                                  FETCH_BY_HAS_REVIEWER - set to limit results to include only records that:
     *                                                          * have at least one reviewer (if value is '1')
     *                                                          * don't have any reviewers   (if value is '0')
     *                                  FETCH_BY_STATE        - set to a 'state' value(s) to limit results
     *                                  FETCH_BY_TEST_STATUS  - set to a 'testStatus' values(s) to limit results
     * @param   Connection  $p4         the perforce connection to use
     * @return  \P4\Model\Fielded\Iterator   the list of zero or more matching review objects
     * @throws \Exception
     */
    public static function fetchAll(array $options, ConnectionInterface $p4)
    {
        // normalize options
        $options += [
            static::FETCH_BY_AUTHOR              => null,
            static::FETCH_BY_CHANGE              => null,
            static::FETCH_BY_PARTICIPANTS        => null,
            static::FETCH_BY_HAS_REVIEWER        => null,
            static::FETCH_BY_PROJECT             => null,
            static::FETCH_BY_GROUP               => null,
            static::FETCH_BY_STATE               => null,
            static::FETCH_BY_TEST_STATUS         => null,
            static::FETCH_BY_NOT_UPDATED_SINCE   => null,
            static::FETCH_BY_HAS_VOTED           => null,
            static::FETCH_BY_USER_CONTEXT        => null,
            static::FETCH_BY_MY_COMMENTS         => null,
            static::FETCH_BY_UPDATED_SINCE       => null,
            static::FETCH_BY_AUTHOR_PARTICIPANTS => null,
        ];
        // build the search expression
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            [
                'author'                             => $options[static::FETCH_BY_AUTHOR],
                'changes'                            => $options[static::FETCH_BY_CHANGE],
                static::FETCH_BY_PARTICIPANTS        => $options[static::FETCH_BY_PARTICIPANTS] === null
                    ? $options[static::FETCH_BY_AUTHOR_PARTICIPANTS] : $options[static::FETCH_BY_PARTICIPANTS],
                'hasReviewer'                        => $options[static::FETCH_BY_HAS_REVIEWER],
                'projects'                           => $options[static::FETCH_BY_PROJECT],
                'groups'                             => $options[static::FETCH_BY_GROUP],
                'state'                              => $options[static::FETCH_BY_STATE],
                'testStatus'                         => $options[static::FETCH_BY_TEST_STATUS],
                static::FETCH_BY_NOT_UPDATED_SINCE   => $options[static::FETCH_BY_NOT_UPDATED_SINCE],
                static::FETCH_BY_HAS_VOTED           => $options[static::FETCH_BY_HAS_VOTED],
                static::FETCH_BY_USER_CONTEXT        => $options[static::FETCH_BY_USER_CONTEXT],
                static::FETCH_BY_MY_COMMENTS         => $options[static::FETCH_BY_MY_COMMENTS],
                static::FETCH_BY_UPDATED_SINCE       => $options[static::ORDER_BY_UPDATED],
            ]
        );

        $reviews = parent::fetchAll($options, $p4);
        if (isset($options[AbstractKey::FETCH_TOTAL_COUNT])) {
            // Only set the count if AbstractKey has not handled it already as part of
            // searching.
            if (!$reviews->hasProperty(AbstractKey::FETCH_TOTAL_COUNT)) {
                $reviews->setProperty(AbstractKey::FETCH_TOTAL_COUNT, sizeof($reviews));
            }
        }
        $myComments          = $options[static::FETCH_BY_MY_COMMENTS];
        $filteringByComments =
            $myComments && (strcasecmp($myComments, 'true') == 0 || strcasecmp($myComments, 'false') == 0);

        $participants = $options[static::FETCH_BY_PARTICIPANTS];
        if ($participants) {
            $reviews->filterByCallback(
                function (Review $review) use ($participants, $reviews, $options, $filteringByComments) {
                    $includeReview = true;
                    // The author of the review may have been deleted and
                    // we still want to return the review without issues.
                    // If we are filtering by comments we do want to include
                    // reviews where I am the author
                    if ($review->isValidAuthor() && !$filteringByComments) {
                        $author = $review->getAuthorObject()->getId();
                        // Participants may be a single string or an array of strings
                        // Only remove reviews if we are not doing author or participant
                        if ($options[Review::FETCH_BY_AUTHOR_PARTICIPANTS] === null) {
                            if ($author == $participants ||
                                (is_array($participants) && in_array($author, $participants))) {
                                $includeReview = false;
                            }
                        }
                    }
                    if ($includeReview === false) {
                        Review::decrementTotal($options, $reviews);
                    }
                    return $includeReview;
                }
            );
            $reviews->setProperty(AbstractKey::LAST_SEEN, $reviews->count() ? $reviews->last()->getId() : null);
        }
        $notUpdateSince = $options[static::FETCH_BY_NOT_UPDATED_SINCE];
        if ($notUpdateSince) {
            $reviews->filterByCallback(
                function (Review $review) use ($notUpdateSince, $reviews, $options) {
                    $includeReview = $review->notUpdatedSince($notUpdateSince);
                    if (!$includeReview) {
                        Review::decrementTotal($options, $reviews);
                    }
                    return $includeReview;
                }
            );
            $reviews->setProperty(AbstractKey::LAST_SEEN, $reviews->count() ? $reviews->last()->getId() : null);
        }
        $hasVoted = $options[static::FETCH_BY_HAS_VOTED];
        $userName = $options[static::FETCH_BY_USER_CONTEXT];
        // Filter out by hasVoted for this user if it is specified.
        if ($hasVoted) {
            $reviews->filterByCallback(
                function (Review $review) use ($hasVoted, $userName, $reviews, $options) {
                    $includeReview = static::shouldIncludeForHasVoted($review, $hasVoted, $userName);
                    if (!$includeReview) {
                        Review::decrementTotal($options, $reviews);
                    }
                    return $includeReview;
                }
            );
        }
        if ($filteringByComments) {
            $reviews->filterByCallback(
                function (Review $review) use ($userName, $reviews, $p4, $myComments, $options) {
                    $includeReview = false;
                    $comments      = Comment::fetchAll(
                        [Comment::FETCH_BY_TOPIC => ['reviews/' . $review->getId()]],
                        $p4
                    );
                    if ($comments && $comments->count() > 0) {
                        // Have we got a comment for the user for this review?
                        $foundForUser = false;
                        foreach ($comments as $comment) {
                            $user = $comment->get('user');
                            if ($user == $userName) {
                                $foundForUser = true;
                                break;
                            }
                        }
                        if ($foundForUser) {
                            // We have found comments for the user so only include if
                            // myComments is true
                            $includeReview = strcasecmp($myComments, 'true') == 0;
                        } else {
                            // We didn't find for this user so we do still want to include if
                            // myComments is false (reviews I have not commented on)
                            $includeReview = strcasecmp($myComments, 'false') == 0;
                        }
                    } else {
                        // There were no comments on the review and I specified either true
                        // or false so only return this review if I specified false
                        $includeReview = strcasecmp($myComments, 'false') == 0;
                    }
                    if (!$includeReview) {
                        Review::decrementTotal($options, $reviews);
                    }
                    return $includeReview;
                }
            );
        }

        // When a filter request has a max option, limit the results returned
        $maxResults = isset($options[static::FETCH_MAX]) ? $options[static::FETCH_MAX] : null;
        if ($maxResults) {
            $resultArray = $reviews->getArrayCopy();
            // Only work when there are more results than needed
            if (count($resultArray) > $maxResults) {
                $lastSeen   = null;
                $spaceLeft  = $maxResults;
                $properties = $reviews->getProperties();
                $reviews    = new Iterator();
                $reviews->setProperties($properties);
                // When max is specified it is usually quite small, so rebuild rather than delete
                foreach ($resultArray as $key => $model) {
                    if ($spaceLeft-- > 0) {
                        $reviews[$key] = $model;
                        $lastSeen      = $key;
                    } else {
                        break;
                    }
                }
                // Reset last seen to pick up any discarded data
                $reviews->setProperty(AbstractKey::LAST_SEEN, $lastSeen);
            }
        }
        return $reviews;
    }

    /**
     * Sets the updated time and saves the review. Was initially
     * introduced to help testing.
     * @param null $updatedTime the time to set updated time to. Should be the number of seconds since the Unix Epoch,
     * defaults to the current time as per the 'time()' function if not provided.
     * @return Review      to maintain a fluent interface
     */
    public function saveUpdated($updatedTime = null)
    {
        // Default argument value cannot be a function so we check it here.
        $timeToUse = isset($updatedTime) ? $updatedTime : time();
        $this->set('updated', $timeToUse);
        return parent::save();
    }

    /**
     * Tests whether this review is inactive since given date.
     *
     * @param   int     $notUpdatedSince   number of seconds since the Unix Epoch.
     *                                     For example as per 'time()' or strtotime().
     * @return  bool    whether or not this review is not updated since given date, or false if the
     *                  not updated since value specified is not an integer or cannot be converted to
     *                  an integer (for example a string representing an integer).
     */
    public function notUpdatedSince($notUpdatedSince)
    {
        // Check for a valid integer. We want to allow a string if it
        // can be interpreted as an integer so an is_int check is not sufficient.
        if (!$notUpdatedSince || !ctype_digit(strval($notUpdatedSince))) {
            return false;
        }
        return (int) $this->getRawValue('updated') < $notUpdatedSince;
    }

    /**
     * Decrement the total review count if total count was returned in the results.
     * @param $options the options
     * @param $reviews the reviews
     */
    public static function decrementTotal($options, $reviews)
    {
        if (isset($options[AbstractKey::FETCH_TOTAL_COUNT])) {
            $reviews->setProperty(
                AbstractKey::FETCH_TOTAL_COUNT,
                $reviews->getProperty(AbstractKey::FETCH_TOTAL_COUNT) - 1
            );
        }
    }

    /**
     * Return new review instance populated from the given change.
     *
     * @param Change|string $change change to populate review record from
     * @param Connection    $p4     the perforce connection to use
     * @return Review instance of this model
     * @throws P4Exception
     */
    public static function createFromChange($change, $p4 = null)
    {
        $userDao   = self::getUserDao();
        $changeDao = self::getChangeDao();
        if (!$change instanceof Change) {
            $change = $changeDao->fetchById($change, $p4);
        }
        $p4         = isset($p4) ? $p4 : $change->getConnection();
        $streamSpec = self::getChangeService()->getStream($p4, $change);
        // refuse to create reviews for un-promoted remote edge shelves
        if ($change->isRemoteEdgeShelf() && !$streamSpec) {
            throw new \InvalidArgumentException(
                "Cannot create review. The change is not promoted and appears to be on a remote edge server."
            );
        }

        // normalize author id in case we're on a case insensitive server
        $userId = $change->getUser();
        $userId = $userDao->exists($userId) ? $userDao->fetchById($userId)->getId() : $userId;

        // populate data from the change
        $model = new static($p4);
        $model->set(self::FIELD_AUTHOR,      $userId);
        $model->set(self::FIELD_DESCRIPTION, $change->getDescription());
        $model->addParticipant($userId);

        // add the change as either a pending or committed value
        if ($change->isSubmitted()) {
            $model->setPending(false);
            $model->addCommit($change);
            $model->addChange($change->getOriginalId());
        } else {
            $model->setPending(true);
            $model->addChange($change);
        }

        return $model;
    }

    /**
     * This will delete any files that have been requested from a given review.
     *
     * @param $data
     * @return $this
     * @throws Exception
     * @throws NotFoundException
     * @throws P4Exception
     * @throws InvalidArgumentException
     */
    public function deleteFromChange($data)
    {
        // normalize change to an object
        $p4         = $this->getConnection();
        $clientName = $data[Review::CLIENT];
        $user       = $data[Review::USER];
        $files      = $data[Review::FILES];

        // our managed shelf should always be pending but defend anyways
        $shelf = Change::fetchById($this->getId(), $p4);
        if ($shelf->isSubmitted()) {
            throw new Exception(
                Review::SHELVEDEL . 'Cannot update review; the shelved change we manage is unexpectedly committed.'
            );
        }
        // try to determine the stream by inspecting the user's client
        // if the client is already deleted we're left with a hard false
        // indicating we couldn't tell one way or the other.
        $stream = false;
        try {
            $stream = Client::fetchById($clientName)->getStream();
        } catch (\InvalidArgumentException $e) {
        } catch (NotFoundException $e) {
            // failed to get stream from client (client might be on an edge)
            // we don't consider this fatal
            Logger::log(Logger::TRACE, Review::SHELVEDEL . "Couldn't get stream infomation from client.");
            unset($e);
        }

        // we'll need a client for this next bit, we're going to update our shelved files
        $p4->getService('clients')->grab();
        try {
            // try and hard reset the client to ensure a clean environment
            $p4->getService('clients')->reset(true, $stream);

            // update metadata on the canonical shelf:
            //  - swap its client over to the one we grabbed
            //  - match type (public/restricted) of updating change
            //  - add any jobs from the updating change
            $shelf->setClient($p4->getClient())
                ->setType($shelf->getType())
                ->setJobs(array_keys(array_flip(array_merge($shelf->getJobs(), $shelf->getJobs()))))
                ->setUser($p4->getUser())
                ->save(true);

            // We need to get all the version of the review and only need the latest version.
            // In most case this will be the review ID.
            $versions = $this->getVersions();
            $head     = end($versions);
            if ($head
                && isset($head['pending'])
                && isset($head['change'])
                && $head['change'] == $shelf->getId()
                && !isset($head['archiveChange'])
            ) {
                try {
                    $this->retroactiveArchive($shelf);
                } catch (\Exception $e) {
                    Logger::log(
                        Logger::TRACE,
                        Review::SHELVEDEL . 'Was unable to create a new revision from previous shelf.'
                    );
                    // well at least we tried!
                }
            }

            // revert state back to 'Needs Review'
            if ($this->getState() === static::STATE_APPROVED) {
                $this->setState(static::STATE_NEEDS_REVIEW);
            }

            // Now get the head change as a changelist.
            $headChange = Change::fetchById($head['change'], $p4);
            // Run the deleteFilesFromShelf function to get the shelf into the right state.
            $deleteResults = $this->deleteFileFromShelf($shelf, $files);
            // If the delete was successful to delete some or all files then we should
            // show this in the review as a new revision.
            if ($deleteResults === true) {
                $flags  = $this->canBypassLocks() ? ['--bypass-exclusive-lock'] : [];
                $flags  = array_merge($flags, ['-s', $headChange->getId(), '-c', $shelf->getId()]);
                $result = $p4->run('unshelve', $flags);
                $opened = array_filter($result->getData(), 'is_array') && !$result->hasWarnings();
                $this->exclusiveOpenCheck($result);
                if ($opened) {
                    // shelve opened files to the canonical shelf
                    $p4->run('shelve', ['-r', '-c', $shelf->getId()]);
                    // we know we've shelved some files so update our 'pending' status
                    $this->setPending(true);
                    // make a new archive/version for this update, preserving append mode
                    $this->archiveShelf(
                        $headChange,
                        ['deletedFiles' => true]
                        + ($stream !== false ? ['stream' => $stream] : []),
                        $this->isAppendMode(null),
                        $user
                    );
                    // we're done with the workspace files, be friendly and remove them
                    $p4->getService('clients')->clearFiles();
                } else {
                    // As the change has no pending files we need to create a new empty changelist.
                    $this->createEmptyChangelist(
                        $headChange,
                        ['deletedFiles' => true]
                        + ($stream !== false ? ['stream' => $stream] : []),
                        $user
                    );
                }
                $findAffected = ServicesModelTrait::getAffectedProjectsService();
                $this->setProjects($findAffected->findByChange($p4, $headChange))->save();
            }
        } catch (\Exception $e) {
            Logger::log(
                Logger::TRACE,
                Review::SHELVEDEL . 'Error trying to build new revision from deleted shelf. Error: ' . $e->getMessage()
            );
        }
        // send workspace files to Garbage Compactor 3263827 before releasing the client
        try {
            $p4->getService('clients')->clearFiles();
        } catch (\Exception $clearException) {
            // we're in a whole world of hurt right now, but let's log before the sweet release of death
            $message = 'Could not clear files on client: ' . $p4->getClient() . ' ' . $clearException->getMessage();
            Logger::log(Logger::ERR, $message);
        }
        $p4->getService('clients')->release();

        // if badnesses occurred re-throw now that we have released our client lock
        if (isset($e)) {
            throw $e;
        }

        return $this;
    }

    /**
     * Updates this review record using the passed change.
     *
     * Add the change as a participant in this review and, if its pending,
     * updates the swarm managed shelf with the changes shelved content.
     *
     * @param   Change|string   $change                 change to populate review record from
     * @param   bool            $unapproveModified      whether approved reviews can be unapproved if they contain
     *                                                  modified files
     * @param   bool            $append                 whether to add the files in this changelist to the review or
     *                                                  to replace them
     * @return  Review          instance of this model
     * @throws  \Exception      re-throws any exceptions which occur during re-shelving
     */
    public function updateFromChange($change, $unapproveModified = true, $append = null)
    {
        // normalize change to an object
        $p4        = $this->getConnection();
        $changeDao = self::getChangeDao();
        if (!$change instanceof Change) {
            $change = $changeDao->fetchById($change, $p4);
        }

        // our managed shelf should always be pending but defend anyways
        $shelf = $changeDao->fetchById($this->getId(), $p4);
        if ($shelf->isSubmitted()) {
            throw new Exception(
                'Cannot update review; the shelved change we manage is unexpectedly committed.'
            );
        }

        // add the passed change's id to the review
        if ($change->isSubmitted()) {
            // if we've already added a committed version for this change, nothing to do
            foreach ($this->getVersions() as $version) {
                if ($version['change'] == $change->getId() && !$version['pending']) {
                    return $this;
                }
            }

            $this->addCommit($change);
            $this->addChange($change->getOriginalId());
        } else {
            $this->addChange($change);
        }
        $userDao = self::getUserDao();
        // ensure the change user is now a participant
        // normalize id in case we're on a case insensitive server
        $userId = $change->getUser();
        $userId = $userDao->exists($userId) ? $userDao->fetchById($userId)->getId() : $userId;
        $this->addParticipant($userId);

        // clear commit status if:
        // - this review isn't mid-commit (intended to clear old errors)
        // - this review is in the process of committing the given change
        if (!$this->isCommitting() || $this->getCommitStatus('change') == $change->getOriginalId()) {
            $this->setCommitStatus(null);
        }

        // try to determine the stream by inspecting the user's client
        // if the client is already deleted we're left with a hard false
        // indicating we couldn't tell one way or the other.
        $stream = false;
        try {
            $stream = Client::fetchById($change->getClient())->getStream();
        } catch (\InvalidArgumentException $e) {
        } catch (NotFoundException $e) {
            // failed to get stream from client (client might be on an edge)
            // we don't consider this fatal and will try another approach
            unset($e);

            // try to determine the stream by looking at the path to the first file
            $stream = $this->guessStreamFromChange($change);
        }

        // we'll need a client for this next bit, we're going to update our shelved files
        $p4->getService('clients')->grab();
        try {
            $changeService = self::getChangeService();
            // try and hard reset the client to ensure a clean environment
            $p4->getService('clients')->reset(true, $stream);

            // update metadata on the canonical shelf:
            //  - swap its client over to the one we grabbed
            //  - match type (public/restricted) of updating change
            //  - add any jobs from the updating change
            $shelf->setClient($p4->getClient())
                  ->setType($change->getType())
                  ->setJobs(array_keys(array_flip(array_merge($change->getJobs(), $shelf->getJobs()))))
                  ->setUser($p4->getUser())
                  ->save(true);

            // if the current contents of the canonical shelf are pending,
            // but not archived (as is the case for pre-versioning reviews),
            // attempt to archive it before we clobber it with this update.
            $versions = $this->getVersions();
            $head     = end($versions);
            if ($head
                && $head['pending']
                && $head['change'] == $shelf->getId()
                && !isset($head['archiveChange'])
            ) {
                try {
                    $this->retroactiveArchive($shelf);
                } catch (\Exception $e) {
                    // well at least we tried!
                }
            }
            // Normalise mode with respect of the last version
            $append                  = $this->isAppendMode($append);
            $changeComparatorService = ServicesModelTrait::getChangeComparatorService();
            // evaluate whether the new version differs, we get back a flag indicating the amount of change:
            // 0 - no changes, 1 - modified name, type or digest, 2 - modified only insignificant fields
            $changesDiffer = $changeComparatorService->compare($shelf, $change, $p4);
            // revert state back to 'Needs Review' if auto state reversion is enabled, the review
            // was approved and the new version is different
            if ($this->getState() === static::STATE_APPROVED && $unapproveModified &&
                $changesDiffer === IChangeComparator::DIFFERENCE) {
                $this->setState(static::STATE_NEEDS_REVIEW);
            }

            // if the contributing change is a commit:
            //  - empty out our shelf
            //  - add a version entry for the commit
            //  - clear our pending flag (review is committed now)
            if ($change->isSubmitted()) {
                // forcibly delete files in our shelf (in case another client has pending resolves)
                // silence the expected exceptions that occur if no shelved files were present
                // (e.g. user commits to a committed review) or files can't be deleted due to
                // pending resolves in another client (still an issue if server version <2014.2)
                try {
                    $changeService->shelve($p4, [P4Command::COMMAND_FLAGS => ['-d', '-f']], $shelf);
                } catch (CommandException $e) {
                    if (preg_match('/needs resolve\sShelve aborted/', $e->getMessage())) {
                        Logger::log(Logger::ERR, $e);
                    } elseif (strpos($e->getMessage(), 'No shelved files in changelist to delete.') === false) {
                        throw $e;
                    }
                    unset($e);
                }

                // write a new version entry for this commit
                // only include the stream if we could determine its value
                $this->addVersion(
                    [
                        'change'     => $change->getId(),
                        'user'       => $change->getUser(),
                        'time'       => $change->getTime(),
                        'pending'    => false,
                        'difference' => $changesDiffer,
                        Review::ADD_CHANGE_MODE => $append ? Review::APPEND_MODE : Review::REPLACE_MODE
                    ]
                    + ($stream !== false ? ['stream' => $stream] : [])
                );

                // at this point we have no shelved files, clear our isPending status
                $this->setPending(false);
            }
            // Check if there is a stream spec change.
            $streamSpecDiffer = IChangeComparator::NO_DIFFERENCE;
            if ($stream) {
                $streamSpecDiffer = $changeComparatorService->compareStreamSpec($shelf, $change, $p4);
            }
            // if the contributing change is pending and files have been updated:
            //  - unshelve it and check if that opened any files
            //  - bypass exclusive locks if supported, always check and throw if need be
            //  - update the canonical shelf with opened files
            //  - create a new archive/version for posterity
            //  - set the pending flag (review is not committed now)
            if ($change->isPending() && ($changesDiffer || $streamSpecDiffer)) {
                $flags  = $this->canBypassLocks() ? ['--bypass-exclusive-lock'] : [];
                $flags  = array_merge($flags, ['-c', $shelf->getId()]);
                $result = $changeService->unshelve($p4, [P4Command::COMMAND_FLAGS => $flags], $change);
                $opened = array_filter($result->getData(), 'is_array') && !$result->hasWarnings();
                $this->exclusiveOpenCheck($result);
                // if we have open files or stream spec change
                if ($opened || $streamSpecDiffer) {
                    // shelve opened files to the canonical shelf
                    if ($append) {
                        // For appending, we shelve all opened files then unshelve the whole review
                        $changeService->shelve($p4, [P4Command::COMMAND_FLAGS => ['-f']], $shelf);
                        $changeService->unshelve($p4, [], $shelf);
                    } else {
                        // For replacing clear out the existing shelved files
                        $changeService->shelve($p4, [P4Command::COMMAND_FLAGS => ['-r']], $shelf);
                    }

                    // we know we've shelved some files so update our 'pending' status
                    $this->setPending(true);

                    // make a new archive/version for this update
                    $this->archiveShelf(
                        $change,
                        ['difference' => $changesDiffer]
                        + ($stream !== false ? ['stream' => $stream, self::STREAMSPECDIFF => $streamSpecDiffer] : []),
                        $append
                    );

                    // we're done with the workspace files, be friendly and remove them
                    $p4->getService('clients')->clearFiles();
                }
            }
        } catch (\Exception $e) {
        }

        // send workspace files to Garbage Compactor 3263827 before releasing the client
        try {
            $p4->getService('clients')->clearFiles();
        } catch (\Exception $clearException) {
            // we're in a whole world of hurt right now, but let's log before the sweet release of death
            $message = 'Could not clear files on client: ' . $p4->getClient() . ' ' . $clearException->getMessage();
            Logger::log(Logger::ERR, $message);
        }

        $p4->getService('clients')->release();

        // if badnesses occurred re-throw now that we have released our client lock
        if (isset($e)) {
            throw $e;
        }

        return $this;
    }

    /**
     * Commits this review's pending work to perforce.
     *
     * You'll need to call 'update from change' after running this to have
     * the new change added to the review record.
     *
     * @param   array           $options        optional - currently supported options are:
     *                                            COMMIT_CREDIT_AUTHOR - credit change to review author
     *                                            COMMIT_DESCRIPTION   - change description
     *                                            COMMIT_JOBS          - list of jobs to attach to the committing change
     *                                            COMMIT_FIX_STATUS    - status to set on jobs upon commit
     * @param   Connection|null $p4             optional - connection to use for the submit or null for default
     *                                          it is recommend this be done as the user committing.
     * @return  Change          the submitted change object; useful for passing to update from change
     * @throws  Exception       if there are no pending files to commit
     * @throws  \Exception      re-throws any errors that occur during commit
     */
    public function commit(array $options = [], Connection $p4 = null)
    {
        // normalize connection to use, we may have received null
        $p4 = $p4 ?: $this->getConnection();

        // normalize options
        $options += [
            static::COMMIT_CREDIT_AUTHOR => null,
            static::COMMIT_DESCRIPTION   => null,
            static::COMMIT_JOBS          => null,
            static::COMMIT_FIX_STATUS    => null
        ];

        // ensure commit status is set
        $this->setCommitStatus(['start' => time()])->save();

        // we'll need a client for this next bit
        $p4->getService('clients')->grab();
        try {
            // try and hard reset the client to ensure a clean environment
            // if the change is against a stream; make sure we're on it
            $p4->getService('clients')->reset(true, $this->getHeadStream());
            $changeDao = self::getChangeDao();
            // get the authoritative shelf, we need to examine if its restricted when creating the submit
            $shelf = $changeDao->fetchById($this->getId(), $p4);

            // create a new 'commit' change, we never commit the managed change
            // as we may later need to re-open this review.
            $commit = new Change($p4);
            $commit->setDescription($options[static::COMMIT_DESCRIPTION] ?: $this->get('description'))
                   ->setJobs($options[static::COMMIT_JOBS])
                   ->setFixStatus($options[static::COMMIT_FIX_STATUS])
                   ->setType($shelf->getType())
                   ->save();
            // update status with our change id, state and committer
            $this->setCommitStatus('change',    $commit->getId())
                 ->setCommitStatus('status',    'Unshelving')
                 ->setCommitStatus('committer', $p4->getUser())
                 ->save();

            $changeService = self::getChangeService();
            // unshelve our managed change and check if that opened any files.
            // bypass exclusive locks if supported, always check and throw if need be.
            $flags  = $this->canBypassLocks() ? ['--bypass-exclusive-lock'] : [];
            $flags  = array_merge($flags, ['-c', $commit->getId()]);
            $result = $changeService->unshelve($p4, [P4Command::COMMAND_FLAGS => $flags], $shelf);
            $opened = $result->hasData() && !$result->hasWarnings();
            $this->exclusiveOpenCheck($result);
            $streamSpec = self::getChangeService()->getStream($p4, $shelf);

            // if we didn't unshelve any files or no stream spec blow up.
            if (!$opened && !$streamSpec) {
                throw new Exception(
                    "Review doesn't contain any files to commit."
                );
            }

            // we need to get the change id in as a commit early to
            // avoid having issues with double reporting activity.
            // also a good opportunity to update the state.
            $this->addCommit($commit->getId())
                 ->setCommitStatus('status', 'Committing')
                 ->save();

            // we must have unshelved some work, lets commit it.
            $commit->submit();

            $this->setCommitStatus('end', time())
                 ->setCommitStatus('status', 'Committed')
                 ->save();
        } catch (\Exception $e) {
            // if we got far enough to create the commit, remove it from the
            // list of 'commits' for this review as we didn't make it in.
            if (isset($commit) && $commit->getId()) {
                $this->setCommits(array_diff($this->getCommits(), [$commit->getId()]));
                $this->setChanges(array_diff($this->getChanges(), [$commit->getId()]));
            }

            // we cannot show the actual error coming from perforce to the user as it will contain wrong changelist
            // number. Instead - hide that bit out and replace with 'try again'
            $msg = str_replace("use 'p4 submit -c " . $commit->getId() . "'", 'try again', $e->getMessage());
            $this->setCommitStatus(['error' => $msg])
                 ->save();

            // as something went wrong we might be leaving files behind; cleanup
            $p4->getService('clients')->clearFiles();

            // delete the commit change we created; it's no longer needed
            // suppress exceptions without overwriting the one that got us here
            try {
                isset($commit) && $commit->delete();
            } catch (\Exception $ignore) {
            }
        }

        $p4->getService('clients')->release();

        // if badnesses occurred re-throw now that we have released our client lock
        if (isset($e)) {
            throw $e;
        }

        // if the credit author flag is set, re-own the change so the review creator gets credit
        if ($options[static::COMMIT_CREDIT_AUTHOR] && $p4->getUser() != $this->get('author')) {
            $p4Admin = $this->getConnection();
            $p4Admin->getService('clients')->grab();
            try {
                $commit->setConnection($p4Admin)
                       ->setUser($this->get('author'))
                       ->save(true);
            } catch (\Exception $e) {
                Logger::log(Logger::ERR, 'Failed to re-own change ' . $commit->getId() . ' to ' . $this->get('author'));
            }

            // ensure client gets released and we stop using the admin connection even if an exception occurred
            $p4Admin->getService('clients')->release();
            $commit->setConnection($p4);
        }

        return $commit;
    }

    /**
     * Cleanup any metadata associated with pending changelists on the review, optionally
     * trying to reopen any opened files into the default changelist.
     *
     * @param array options $reopen
     * @param ConnectionInterface|null $p4
     * @return array
     */
    public function cleanup(array $options = [], Connection $p4 = null)
    {
        // Get the UUID so we can use it later on to exclude clients.
        $uuid = new Uuid();

        $incomplete = [];
        $complete   = [];
        try {
            // Make sure that we have a user and an alternative(potentially admin/super) connection
            $p4User   = $p4 ?: $this->getConnection();
            $p4Review = $this->getConnection();

            Logger::log(
                Logger::INFO,
                'Review cleanup:'
                . $p4User->getUser() . "(" . ($p4 ? $p4->getUser() : "default") . ")"
                . ': Cleaning up for Review ' . $this->getId()
            );

            // Build a list of changelists associated with this review
            $changes = [];
            foreach ($this->getChanges() as $change) {
                try {
                    Logger::log(
                        Logger::DEBUG,
                        'Review cleanup:' . $p4User->getUser() . ": Getting change details for #$change"
                    );
                    $changes[] = Change::fetchById($change, $p4User);
                } catch (\Exception $e) {
                    // Tolerate an exception and continue. If the changelist has already been
                    // removed, then we don't raise an error or mark it as incomplete.
                    if (strpos($e->getMessage(), 'Cannot fetch change ') === false) {
                        Logger::log(
                            Logger::WARN,
                            'Review cleanup:' . $p4User->getUser() . ': Failed to fetch change ' . $change
                            . ' for ' . $this->getId() . ', continuing. ' . $e->getMessage()
                        );
                        $incomplete[$this->getId()][$change][] = $e->getMessage();
                    }
                    unset($e);
                }
            }
            $changes = array_filter(
                $changes,
                function ($change) use ($uuid) {
                    // Only consider changelists that are pending and not ...-uuid
                    return 'pending' === $change->getStatus() &&
                        !$uuid->isValid(
                            preg_replace('/^.*-([^-]*-[^-]*-[^-]*-[^-]*-[^-]*$)/', '\1', $change->getClient())
                        );
                }
            );

            // Clean up each appropriate changelist
            foreach ($changes as $pendingChange) {
                // Use changelist user if they are the same otherwise try current
                $p4Cleanup = $p4User->getUser() === $pendingChange->getUser() ? $p4User : $p4Review;
                $force     = $p4Cleanup->isAdminUser(true) ? ['-f'] : [];

                Logger::log(
                    Logger::DEBUG,
                    'Review cleanup:' . $p4Cleanup->getUser() . ': Cleaning up for ' . $pendingChange->getId()
                );
                // Get the client workspace; we will need, at least, the value of host
                try {
                    Logger::log(
                        Logger::DEBUG,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Fetching client ' . $pendingChange->getClient()
                    );
                    $client = Client::fetchById($pendingChange->getClient(), $p4Cleanup);

                    // Just in case the client is host locked
                    $p4Cleanup->setHost($client->getHost())->setClient($client->getId());
                    // refreshing pending change, file are only available from the right workspace.
                    $pendingChange = Change::fetchById($pendingChange->getId(), $p4Cleanup);
                } catch (\Exception $e) {
                    Logger::log(
                        Logger::WARN,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Failed to get client details for '
                        . $this->getId() . ', continuing. ' . $e->getMessage()
                    );
                    $incomplete[$this->getId()][$pendingChange->getId()][] = $e->getMessage();
                    unset($e);
                }

                // Expecting to delete any shelved files off the swarm managed change
                // silence the expected exception that occurs when no shelved files were present
                try {
                    Logger::log(
                        Logger::DEBUG,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Deleting shelved files for '
                        . $pendingChange->getId()
                    );
                    $p4Cleanup->run('shelve', array_merge($force, ['-d', '-c', $pendingChange->getId()]));
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'No shelved files in changelist to delete.') === false) {
                        Logger::log(
                            Logger::WARN,
                            'Review cleanup:' . $p4Cleanup->getUser() . ': Failed to delete shelved files for '
                            . $this->getId() . ', continuing. ' . $e->getMessage()
                        );
                    }
                    unset($e);
                }

                // Detach jobs
                try {
                    Logger::log(
                        Logger::DEBUG,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Detaching jobs ('
                        . implode(',', $pendingChange->getJobs()) . ') from ' . $pendingChange->getId()
                    );
                    $p4Cleanup->run(
                        'fix',
                        array_merge(['-d', '-s', 'same', '-c', $pendingChange->getId()], $pendingChange->getJobs())
                    );
                } catch (\Exception $e) {
                    // We expect wrong number of arguments for changes without fixes
                    if (strpos($e->getMessage(), 'Missing/wrong number of arguments.') === false) {
                        Logger::log(
                            Logger::WARN,
                            'Review cleanup:' . $p4Cleanup->getUser() . ': Failed to detach fixes for ' . $this->getId()
                            . ', continuing. ' . $e->getMessage()
                        );
                        $incomplete[$this->getId()][$pendingChange->getId()][] = $e->getMessage();
                    }
                    unset($e);
                }
                // move opened files to another changelist, only if mine or I'm a super user
                if (true === $options['reopen'] &&
                    ($p4Cleanup->isSuperUser() || $p4Cleanup->getUser() === $pendingChange->getUser())) {
                    // Get the Users running Swarm details and save them
                    $originalUser = $p4Cleanup->getUser();
                    $originalPass = $p4Cleanup->getPassword() ? $p4Cleanup->getPassword() : $p4Cleanup->getTicket();
                    $superUser    = $p4Cleanup->isSuperUser() ? true : false;

                    if ($superUser === true) {
                        // Now we get the client owner details and set them
                        $userPass = $p4Cleanup->run("login", ["-p", $pendingChange->getUser()]);
                        $onlyPass = $userPass->getData();
                        $onlyPass = $onlyPass[0];

                        $p4Cleanup->setPassword($onlyPass);
                    }
                    $p4Cleanup->setUser($pendingChange->getUser());

                    try {
                        Logger::log(
                            Logger::DEBUG,
                            'Review cleanup:' . $p4Cleanup->getUser() . ': Reopening files ('
                            . implode(',', $pendingChange->getFiles()) . ') into default changelist as '
                            . $p4Cleanup->getUser() . '.'
                        );
                        $p4Cleanup->run('reopen', array_merge(['-c', 'default'], $pendingChange->getFiles()));
                    } catch (\Exception $e) {
                        if (strpos($e->getMessage(), 'Usage: reopen [-c changelist#] [-t type]') === false) {
                            Logger::log(
                                Logger::WARN,
                                'Review cleanup:' . $p4Cleanup->getUser() . ': Failed to reopen files for '
                                . $this->getId() . '. ' . $pendingChange->getUser()
                                . ' may need to cleanup the changelist #'
                                . $pendingChange->getId() . ' manually for themseleves, continuing. '
                                . $e->getMessage()
                            );
                        }
                        $incomplete[$this->getId()][$pendingChange->getId()][] = $e->getMessage();
                        unset($e);
                    }
                    // Set the user back to the user running Swarm
                    $p4Cleanup->setUser($originalUser);
                    if ($superUser) {
                        $p4Cleanup->setPassword($originalPass);
                    }
                } else {
                    $message =
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Not re-opening files for ' . $this->getId()
                        . '. ' . (true !== $options['reopen'] ? 'Reopen is disabled. ' : '')
                        . $pendingChange->getUser() . ' may need to cleanup the changelist #'
                        . $pendingChange->getId() . ' manually for themselves.';
                    Logger::log(
                        Logger::NOTICE,
                        $message
                    );
                }

                // Finally delete the changelist
                try {
                    Logger::log(
                        Logger::DEBUG,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Deleting pending changelist files '
                        . $pendingChange->getId() . ' for review ' . $this->getId()
                    );
                    // Delete the change, with brute force for admin/super
                    $response = $p4Cleanup->run('change', array_merge($force, ['-d', $pendingChange->getId()]));

                    $responseText = implode('. ', $response->getData());
                    Logger::log(
                        Logger::INFO,
                        'Review cleanup:' . $p4Cleanup->getUser() . ': Response from delete was. '
                        . $responseText
                    );
                    // Other open files on the changelist will prevent deletion so we should treat this as incomplete
                    if (strpos($responseText, "can't be deleted") !== false) {
                        $incomplete[$this->getId()][$pendingChange->getId()][] = $responseText;
                    }
                } catch (\Exception $e) {
                    Logger::log(
                        Logger::WARN,
                        'Review cleanup:' . $p4Cleanup->getUser()
                        . ': Failed to delete changelist ' . $pendingChange->getId() . ' for review ' . $this->getId()
                        . ', continuing. ' . $e->getMessage()
                    );
                    $incomplete[$this->getId()][$pendingChange->getId()][] = $e->getMessage();
                    unset($e);
                }

                if (!isset($incomplete[$this->getId()])) {
                    $complete[$this->getId()][] = $pendingChange->getId();
                }
            }
        } catch (\Exception $e) {
            // Tolerate an exception and continue
            Logger::log(
                Logger::WARN,
                'Review cleanup:' . $p4User->getUser() . ': Failed for review ' . $this->getId() . '.'
                . $e->getMessage()
            );
            $incomplete[$this->getId()][] = $e->getMessage();
            unset($e);
        }
        if (!isset($complete[$this->getId()]) && !isset($incomplete[$this->getId()])) {
            $complete = [$this->getId()];
        }
        return ['complete' => $complete, 'incomplete' => $incomplete];
    }

    /**
     * Returns the type of review we're dealing with.
     *
     * @return  string  the 'type' of this review, one of default or git
     */
    public function getType()
    {
        return $this->getRawValue('type') ?: 'default';
    }

    /**
     * Get the commit status for this code review
     *
     * @param   string|null     $field  a specific key to retrieve or null for all commit status
     *                                  if a field is specified which doesn't exist null is returned.
     * @return  string  Current state of this code review
     */
    public function getCommitStatus($field = null)
    {
        $status = (array) $this->getRawValue('commitStatus');

        // validate commit status
        // detect race-condition where commit-status is not empty, but the commit has been processed
        // if the commit is in changes and versions, we have processed it and status should be empty
        if (isset($status['change']) && in_array($status['change'], $this->getChanges())) {
            // extract commits from versions so we can look for the commit in question
            $commits = [];
            foreach ((array) $this->getRawValue('versions') as $version) {
                $version += ['change' => null, 'pending' => null];
                if (!$version['pending'] && $version['change'] >= $status['change']) {
                    $commits[] = '@=' . $version['change'];
                }
            }

            // if the commit was not renumbered the number could match exactly
            // if we don't get an exact match, we could still match the original id
            if ($commits && in_array('@=' . $status['change'], $commits)) {
                $status = [];
            } elseif ($commits) {
                try {
                    foreach ($this->getConnection()->run('changes', $commits)->getData() as $change) {
                        if (isset($change['oldChange']) && $status['change'] == $change['oldChange']) {
                            $status = [];
                        }
                    }
                } catch (\Exception $e) {
                    // not worth breaking things to possibly fix a race condition
                }
            }
        }

        if (!$field) {
            return $status;
        }

        return isset($status[$field]) ? $status[$field] : null;
    }

    /**
     * Set the commit status for this code review.
     *
     * @param   string|array    $fieldOrValues  a specific field name or an array of all new values
     * @param   mixed           $value          if a field was specified in param 1, the new value to use
     * @return  Review          to maintain a fluent interface
     */
    public function setCommitStatus($fieldOrValues, $value = null)
    {
        // if param 1 isn't a string it our new commit status
        if (!is_string($fieldOrValues)) {
            return $this->setRawValue('commitStatus', (array) $fieldOrValues);
        }

        // param 1 was a string, lets treat it as specific key to update
        $status                 = $this->getCommitStatus();
        $status[$fieldOrValues] = $value;
        return $this->setRawValue('commitStatus', $status);
    }

    /**
     * This method will determine if a commit is presently in progress based on the
     * data held in commit status.
     *
     * @return  bool    true if commit is actively in progress, false otherwise
     */
    public function isCommitting()
    {
        return $this->getCommitStatus() && !$this->getCommitStatus('error');
    }

    /**
     * Get the current state for this code review e.g. needsReview
     *
     * @return  string  Current state of this code review
     */
    public function getState()
    {
        return $this->getRawValue('state');
    }

    /**
     * Set the current state for this code review e.g. needsReview
     *
     * @param   string  $state  Current state of this code review
     * @return  Review          to maintain a fluent interface
     */
    public function setState($state)
    {
        // if we got approved:commit, simply store approved, the second
        // half is a queue to our caller that they aught to commit us.
        if ($state == 'approved:commit') {
            $state = 'approved';
        }

        return $this->setRawValue('state', $state);
    }

    /**
     * Get the participant data. Note the values are stored under the 'participants' field
     * but that accessor only exposes the IDs, this accessor exposes... _everything_.
     * The author is automatically included.
     *
     * User ids will be keys and each will have an array of properties associated to it
     * (such as vote, required, etc.).
     * If a specific 'field' is specified the user ids will be keys and each will have
     * just the specified property associated to it. Users lacking the specified field
     * will not be returned.
     *
     * @param null|string  $field   optional - limit returned data to only 'field'; users lacking the specified field
     *                              will not be included in the result.
     * @return array|mixed          participant ids as keys each associated with properties array.
     * @throws P4Exception
     * @throws RecordNotFoundException
     */
    public function getParticipantsData($field = null)
    {
        // handle upgrade to v3 (2014.2)
        //  - numerically indexed user ids become arrays keyed on user id
        //  - votes move into participant array
        if ((int) $this->get('upgrade') < 3) {
            $participants = [];
            foreach ((array) $this->getRawValue(self::FIELD_PARTICIPANTS) as $key => $value) {
                if (is_string($value)) {
                    $key   = $value;
                    $value = [];
                }

                $participants[$key] = $value;
            }

            // move votes into participant metadata
            if ($this->issetRawValue('votes')) {
                // note we only honor votes for 'reviewers' if you are not a reviewer
                // your vote would have been ignored by getVotes and should be ignored here
                $author = $this->get('author');
                foreach ((array) $this->getRawValue('votes') as $user => $vote) {
                    if (isset($participants[$user]) && $user !== $author) {
                        $participants[$user] = ['vote' => $vote];
                    }
                }
                $this->unsetRawValue('votes');
            }

            $this->setRawValue(self::FIELD_PARTICIPANTS, $participants);
        }

        // handle upgrade to v4 (2014.3)
        // - single vote values become structured arrays with version info, e.g. [value => 1, version => 3]
        if ((int) $this->get('upgrade') < 4) {
            $participants = $this->getRawValue(self::FIELD_PARTICIPANTS);
            foreach ($participants as $user => $data) {
                if (isset($data['vote'])) {
                    $participants[$user]['vote'] = $this->normalizeVote($user, $data['vote'], true);
                    if (!$participants[$user]['vote']) {
                        unset($participants[$user]['vote']);
                    }
                }
            }

            $this->setRawValue(self::FIELD_PARTICIPANTS, $participants);
        }

        $participants = $this->normalizeParticipants($this->getRawValue(self::FIELD_PARTICIPANTS));

        // if a specific field was specified, only include participants
        // that have that value and only include the one requested field
        if ($field) {
            foreach ($participants as $id => $data) {
                if (!isset($data[$field])) {
                    unset($participants[$id]);
                } else {
                    $participants[$id] = $data[$field];
                }
            }
        }

        return $participants;
    }

    /**
     * If only values is specified, updates all participant data.
     * In that usage values should appear similar to:
     *  $values => array('gnicol' => array(), 'slord' => array('required' => true))
     *
     * If both a values and field are specified, updates the specific property on the
     * participants array. Any participants not specified in the updated values array
     * will have the property removed if its already present. They will not be removed
     * as a participant though. We will then ensure a participate entry is present for
     * all specified users and that the value reflects what was passed.
     * In that usage values should appear similar to:
     *  $values => array('slord' => true), $field => 'required'
     *
     * @param   array|null      $values     the updated id/value(s) array
     * @param   null|string     $field      optional - a specific field we are updating (e.g. vote)
     * @return  Review  to maintain a fluent interface
     */
    public function setParticipantsData(array $values = null, $field = null)
    {
        $p4 = $this->getConnection();

        // if no field was specified; we're updating everything just normalize, set, return
        if ($field === null) {
            $values = $this->normalizeParticipantIds($values, $p4);
            return $this->setRawValue(self::FIELD_PARTICIPANTS, $this->normalizeParticipants($values, true));
        }

        // looks like we're just doing one specific field; make the update
        // first remove the specified field from all participants that are not listed
        $values       = (array) $values;
        $participants = $this->getParticipantsData();
        foreach (array_diff_key($participants, $values) as $id => $value) {
            unset($participants[$id][$field]);
        }

        // ensure a participant entry exists for all specified users and update value
        foreach ($values as $id => $value) {
            $participants             += [$id => []];
            $participants[$id][$field] = $value;
        }


        $participants = $this->normalizeParticipantIds($participants, $p4);
        return $this->setRawValue(self::FIELD_PARTICIPANTS, $this->normalizeParticipants($participants, true));
    }

    /**
     * Update value(s) for a specific participant.
     *
     * If no field is specified, this clobbers the existing data for the given
     * participant with the new value.
     * If a field is specified, only the specific field is updated; any other
     * fields present on the participant are unchanged.
     *
     * @param   string  $user   the user we are setting data on
     * @param   mixed   $value  an array of all values (if no field was specified) otherwise the new value for $field
     * @param   mixed   $field  optional - if specified the specific field to update
     * @return  Review  to maintain a fluent interface
     */
    public function setParticipantData($user, $value, $field = null)
    {
        $participants  = $this->getParticipantsData();
        $participants += [$user => []];

        // if a specific field was specified; maintain all other properties
        if ($field) {
            $value = [$field => $value] + $participants[ $user];
        }

        $participants[$user] = (array) $value;

        return $this->setParticipantsData($participants);
    }

    /**
     * Get list of participants associated with this review.
     * The current author is automatically included.
     *
     * @return  array   list of participants associated with this record
     */
    public function getParticipants()
    {
        $keys = array_keys($this->getParticipantsData());
        return array_map('strval', $keys);
    }

    /**
     * Set participants associated with this review record.
     * If we have existing entries for any of the specified participants we will persist
     * their properties (e.g. votes) not throw them away.
     *
     * @param   string|array    $participants   list of participants
     * @return  Review          to maintain a fluent interface
     */
    public function setParticipants($participants)
    {
        $participants = array_filter((array) $participants);
        $participants = array_fill_keys($participants, []);
        if ($this->get('author')) {
            $participants += [$this->get('author') => []];
        }
        $participants = array_intersect_key($this->getParticipantsData(), $participants) + $participants;
        $participants = $this->normalizeParticipantIds($participants, $this->getConnection());

        return $this->setRawValue(self::FIELD_PARTICIPANTS, $this->normalizeParticipants($participants, true));
    }

    /**
     * Get the description of this review.
     *
     * @return  string|null     the review's description
     */
    public function getDescription()
    {
        return $this->getRawValue('description');
    }

    /**
     * Set the description for this review.
     *
     * @param   string|null $description    the new description for this review
     * @return  Review          to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue('description', $description);
    }

    /**
     * Get list of reviewers (all participants excluding the author).
     *
     * @return  array   list of reviewers associated with this record
     */
    public function getReviewers()
    {
        return array_values(array_diff($this->getParticipants(), [$this->get('author')]));
    }

    /**
     * Add one or more participants to this review record.
     *
     * @param   string|array    $participant    participant(s) to add
     * @return  Review          to maintain a fluent interface
     */
    public function addParticipant($participant)
    {
        return $this->setParticipants(
            array_merge($this->getParticipants(), (array) $participant)
        );
    }

    /**
     * Add one or more required reviewers to this review record.
     *
     * @param   string|array    $required    required reviewer(s) to add
     * @param   integer|bool    $quorum      require X or all reviewer from group to vote
     * @throws P4Exception
     * @throws RecordNotFoundException
     * @return  Review          to maintain a fluent interface
     */
    public function addRequired($required, $quorum = true)
    {
        if (is_bool($quorum) || is_numeric($quorum)) {
            // Convert any int into string to be sorted.
            if (is_int($quorum)) {
                $quorum = strval($quorum);
            }
        } else {
            // If quorum isn't a boolean or numeric then set it to true.
            $quorum = true;
        }
        return $this->setParticipantsData(
            ArrayHelper::merge(
                array_filter($this->getParticipantsData('required')),
                array_fill_keys(
                    (array) $required,
                    $quorum
                )
            ),
            'required'
        );
    }

    /**
     * Get list of votes (including stale votes)
     *
     * @return  array   list of votes left of this record
     */
    public function getVotes()
    {
        return $this->getParticipantsData('vote');
    }

    /**
     * Set votes on this review record
     *
     * @param   array   $votes   list of votes
     * @return  Review  to maintain a fluent interface
     */
    public function setVotes($votes)
    {
        return $this->setParticipantsData($votes, 'vote');
    }

    /**
     * This method is used to ensure arrays of changes always contain integers
     *
     * It will make an attempt to cast string integers to real integers,
     * it will detect Change objects and convert them to Change IDs,
     * and failures will be eliminated.
     *
     * @param   array   $changes    the array of Changes/IDs to be normalized
     * @return  array               the normalized array of Change IDs
     */
    protected function normalizeChanges($changes)
    {
        $changes = (array) $changes;

        foreach ($changes as $key => $change) {
            if ($change instanceof Change) {
                $change = $change->getId();
            }

            if (!ctype_digit((string) $change)) {
                unset($changes[$key]);
            } else {
                $changes[$key] = (int) $change;
            }
        }

        return array_values(array_unique($changes));
    }

    /**
     * Add a user's vote to this review record
     *
     * @param   string      $user       user id of the user to add
     * @param   int         $vote       vote (-1/0/1) to associate with the user
     * @param   int|null    $version    optional - version to add vote for
     *                                  defaults to current (head) version
     * @return Review
     */
    public function addVote($user, $vote, $version = null)
    {
        $vote         = ['value' => (int)$vote, 'version' => $version];
        $currentVotes = $this->getVotes();
        return $this->setVotes(ArrayHelper::mergeSingle($currentVotes, $user, $vote));
    }

    /**
     * Returns a list of positive non-stale votes
     *
     * @return  array   list of votes
     */
    public function getUpVotes()
    {
        return array_filter(
            $this->getVotes(),
            function ($vote) {
                return $vote['value'] > 0 && !$vote['isStale'];
            }
        );
    }

    /**
     * Returns a list of negative non-stale votes
     *
     * @return  array   list of votes
     */
    public function getDownVotes()
    {
        return array_filter(
            $this->getVotes(),
            function ($vote) {
                return $vote['value'] < 0 && !$vote['isStale'];
            }
        );
    }

    /**
     * Get list of changes associated with this review.
     * This includes both pending and committed changes.
     *
     * @return  array   list of changes associated with this record
     */
    public function getChanges()
    {
        return $this->normalizeChanges($this->getRawValue('changes'));
    }

    /**
     * Set changes associated with this review record.
     *
     * @param   string|array    $changes    list of changes
     * @return  Review          to maintain a fluent interface
     */
    public function setChanges($changes)
    {
        return $this->setRawValue('changes', $this->normalizeChanges($changes));
    }

    /**
     * Add a change associated with this review record.
     *
     * @param   string  $change     the change to add
     * @return  Review  to maintain a fluent interface
     */
    public function addChange($change)
    {
        $changes   = $this->getChanges();
        $changes[] = $change;
        return $this->setChanges($changes);
    }

    /**
     * Get list of committed changes associated with this review.
     *
     * If a change contributes to this review and is later submitted
     * that won't automatically count. We only count changes which
     * were in a submitted state at the point they updated this review.
     *
     * @return  array   list of commits associated with this record
     */
    public function getCommits()
    {
        return $this->normalizeChanges($this->getRawValue('commits'));
    }

    /**
     * Set list of committed changes associated with this review.
     *
     * See @getCommits for details.
     *
     * @param   string|array    $changes    list of changes
     * @return  Review          to maintain a fluent interface
     */
    public function setCommits($changes)
    {
        $changes = $this->normalizeChanges($changes);

        // ensure all commits are also listed as being changes
        $this->setChanges(
            array_merge($this->getChanges(), $changes)
        );

        return $this->setRawValue('commits', $changes);
    }

    /**
     * Add a commit associated with this review record.
     *
     * @param   string  $change     the commit to add
     * @return  Review  to maintain a fluent interface
     */
    public function addCommit($change)
    {
        $changes   = $this->getCommits();
        $changes[] = $change;
        return $this->setCommits($changes);
    }

    /**
     * Get versions of this review (a version is created anytime files are updated).
     *
     * @return  array   a list of versions from oldest to newest
     *                  each version is an array containing change, user, time and pending
     */
    public function getVersions()
    {
        $versions = (array) $this->getRawValue('versions');

        // if there are no versions and this is an old record (level<2)
        // try fabricating versions from commits + current pending work
        // for pending work, we don't know who actually did it, so we
        // assume it was the review author.
        if (!$versions && $this->get('upgrade') < 2) {
            $versions = [];
            $changes  = [];
            if ($this->getCommits() || $this->isPending()) {
                $changes = $this->getCommits();
                sort($changes, SORT_NUMERIC);
                if ($this->isPending()) {
                    $changes[] = $this->getId();
                }
                $changes = Change::fetchAll(
                    [Change::FETCH_BY_IDS => $changes],
                    $this->getConnection()
                );
            }

            foreach ($changes as $change) {
                $versions[] = [
                    'change'  => $change->getId(),
                    'user'    => $change->isSubmitted() ? $change->getUser() : $this->get('author'),
                    'time'    => $change->getTime(),
                    'pending' => $change->isPending()
                ];
            }

            // hang on to the fabricated versions so we don't query changes again
            $this->setRawValue('versions', $versions);
        }

        // ensure head rev points to the canonical shelf, but older revs do not.
        $versions = $this->normalizeVersions($versions);

        return $versions;
    }

    /**
     * Set the list of versions. Each element must specify change, user, time and pending.
     *
     * @param   array|null  $versions   the list of versions
     * @return  KeyRecord   provides fluent interface
     * @throws  \InvalidArgumentException   if any version doesn't contain change, user, time or pending.
     */
    public function setVersions(array $versions = null)
    {
        return $this->setAllVersions($versions);
    }

    /**
     * Private method to update versions for use where other types of view do not allow
     * @param array|null $versions
     * @return KeyRecord
     */
    private function setAllVersions(array $versions = null)
    {
        $versions = (array) $versions;
        foreach ($versions as $key => $version) {
            if (!isset($version['change'], $version['user'], $version['time'], $version['pending'])) {
                throw new \InvalidArgumentException(
                    "Cannot set versions. Each version must specify a change, user, time and pending."
                );
            }

            // normalize pending to an int for consistency with the review's pending flag.
            $version['pending'] = (int) $version['pending'];
        }

        // ensure head rev points to the canonical shelf, but older revs do not.
        $versions = $this->normalizeVersions($versions);

        return $this->setRawValue('versions', $versions);
    }

    /**
     * Add a version to the list of versions.
     *
     * @param   array   $version    the version details (change, user, time, pending)
     * @return  Review  provides fluent interface
     * @throws  \InvalidArgumentException   if the version doesn't contain change, user, time or pending.
     */
    public function addVersion(array $version)
    {
        $versions   = $this->getVersions();
        $versions[] = $version;

        return $this->setVersions($versions);
    }

    /**
     * Get highest version number.
     *
     * @return  int     max version number
     */
    public function getHeadVersion()
    {
        return count($this->getVersions());
    }

    /**
     * Convenience method to get the revision number for a given change id.
     *
     * @param   int|string|Change   $change     the change to get the rev number of.
     * @return  int                 the rev number of the change or false if no such change version
     */
    public function getVersionOfChange($change)
    {
        $change        = $change instanceof Change ? $change->getId() : $change;
        $versionNumber = false;
        foreach ($this->getVersions() as $key => $version) {
            if ($change == $version['change']
                || (isset($version['archiveChange']) && $change == $version['archiveChange'])
            ) {
                $versionNumber = $key + 1;
            }
        }

        return $versionNumber;
    }

    /**
     * Convenience method to get the change number for a given version.
     *
     * @param   int     $version    the version to get the change number of.
     * @param   bool    $archive    optional - pass true to get the archive change if available
     *                              by default returns the review id for pending head versions
     * @return  int                 the change number of the given version
     * @throws  Exception           if no such version
     */
    public function getChangeOfVersion($version, $archive = false)
    {
        $versions = $this->getVersions();
        if (isset($versions[$version - 1]['change'])) {
            $version = $versions[$version - 1];
            return $archive && isset($version['archiveChange']) ? $version['archiveChange'] : $version['change'];
        }

        throw new Exception("Cannot get change of version $version. No such version.");
    }

    /**
     * Convenience method to get the change number of the latest version.
     *
     * @param   bool    $archive    optional - pass true to get the archive change if available
     *                              by default returns the review id for pending head versions
     * @return  int|null    the change id of the latest version or null if no associated changes
     */
    public function getHeadChange($archive = false)
    {
        $versions = $this->getVersions();
        $head     = end($versions);
        if (is_array($head) && isset($head['change'])) {
            return $archive && isset($head['archiveChange']) ? $head['archiveChange'] : $head['change'];
        }

        // if no versions, could be a new review that hasn't processed its change
        if ($this->getChanges()) {
            return max($this->getChanges());
        }

        return null;
    }

    /**
     * Get the test runs for the version
     * @param mixed|null $version   version to get test runs for, defaults to latest version if not provided
     * @return array of test run ids, or an empty array if no test runs are found
     * @throws \InvalidArgumentException if a version is specified that does not exist
     */
    public function getTestRuns($version = null)
    {
        $versionData = $this->getVersion($version);
        return isset($versionData[self::FIELD_TEST_RUNS]) ? $versionData[self::FIELD_TEST_RUNS] : [];
    }

    /**
     * Set the test runs for the version
     * @param array         $testRuns   array of test run ids to set
     * @param mixed|null    $version    version to set test runs for, defaults to latest version if not provided
     * @return Review the review with the test run information set
     * @throws \InvalidArgumentException if a version is specified that does not exist
     */
    public function setTestRuns(array $testRuns, $version = null)
    {
        // $this->getVersion($version) will throw an exception if the version provided but not found
        $versionData                        = $this->getVersion($version);
        $versionData[self::FIELD_TEST_RUNS] = $testRuns;
        $this->setVersionData($versionData, $version);
        return $this;
    }

    /**
     * Add a test run. If the test run is already present no changes will be made
     * @param mixed         $testRunId  test run id to add
     * @param mixed|null    $version    version to add a test run to, defaults to latest version if not provided
     * @return Review the review with the test run information set
     * @throws \InvalidArgumentException if a version is specified that does not exist
     */
    public function addTestRun($testRunId, $version = null)
    {
        $versionData = $this->getVersion($version);
        if (!isset($versionData[self::FIELD_TEST_RUNS])) {
            $versionData[self::FIELD_TEST_RUNS] = [];
        }
        if (!in_array($testRunId, $versionData[self::FIELD_TEST_RUNS])) {
            $versionData[self::FIELD_TEST_RUNS][] = $testRunId;
            $this->setVersionData($versionData, $version);
        }
        return $this;
    }

    /**
     * Set the version data for the version
     * @param mixed         $versionData    the version data
     * @param mixed|null    $version        the version, null value defaults to the latest
     * @return Review the review with the test run information set
     */
    private function setVersionData($versionData, $version = null)
    {
        $versions                                                      = $this->getVersions();
        $versions[($version ? $version : $this->getHeadVersion()) - 1] = $versionData;
        $this->setAllVersions($versions);
        return $this;
    }

    /**
     * Delete a test run.
     * @param mixed         $testRunId  test run id to delete
     * @param mixed|null    $version    version to delete the test run from, defaults to latest version if not provided
     * @return Review the review with the test run information set
     * @throws \InvalidArgumentException if a version or test run id is specified that does not exist
     */
    public function deleteTestRun($testRunId, $version = null)
    {
        $versionData = $this->getVersion($version);
        if (isset($versionData[self::FIELD_TEST_RUNS])) {
            if (($key = array_search($testRunId, $versionData[self::FIELD_TEST_RUNS])) !== false) {
                unset($versionData[self::FIELD_TEST_RUNS][$key]);
                // This has the effect of re-indexing. For example starting with [0 => 5, 1 => 10] and removing value
                // 5 would leave [1 => 10] when we want to have [0 => 10]
                $versionData[self::FIELD_TEST_RUNS] = array_values($versionData[self::FIELD_TEST_RUNS]);
            }
        }
        return $this->setVersionData($versionData, $version);
    }

    /**
     * Delete test runs.
     * @param mixed|null    $version    version to delete the test runs from, defaults to latest version if not provided
     * @return Review the review with the test run information set
     * @throws \InvalidArgumentException if a version is specified that does not exist
     */
    public function deleteTestRuns($version = null)
    {
        $versionData                        = $this->getVersion($version);
        $versionData[self::FIELD_TEST_RUNS] = [];
        return $this->setVersionData($versionData);
    }

    /**
     * Get the specific version information
     * @param mixed|null    $version    the version, defaults to latest version if not provided
     * @return mixed|null the version information
     * @throws \InvalidArgumentException if a version is specified that does not exist
     */
    public function getVersion($version = null)
    {
        $versionData = null;
        $versions    = $this->getVersions();
        if ($version) {
            if (isset($versions[$version - 1])) {
                $versionData = $versions[$version - 1];
            } else {
                throw new \InvalidArgumentException(
                    sprintf("Version [%s] is not a valid version for this review", $version)
                );
            }
        } else {
            $headVersion = $this->getHeadVersion();
            $versionData = $headVersion > 0 ? $versions[$this->getHeadVersion() - 1] : [];
        }
        return $versionData;
    }

    /**
     * Convenience method to check if a given version exists.
     *
     * @param   int     $version    the version to check for (one-based)
     * @return  bool    true if the version exists, false otherwise
     */
    public function hasVersion($version)
    {
        $hasVersion = false;
        try {
            $this->getVersion($version);
            $hasVersion = true;
        } catch (\InvalidArgumentException $e) {
            // Version not found, will return false
        }
        return $hasVersion;
    }

    /**
     * Get changes associated with this review record which were in a pending
     * state when they were associated with the review.
     *
     * This is a convenience method it calculates the result by diffing
     * the full change list and the committed list.
     *
     * Note, this is a historical representation; just because there are
     * pending changes associated doesn't mean the review 'isPending'.
     *
     * @return  array   list of changes associated with this record
     */
    public function getPending()
    {
        return array_values(
            array_diff($this->getChanges(), $this->getCommits())
        );
    }

    /**
     * Set this review to pending to indicate it has un-committed files.
     * Ensures the raw value is consistently stored as a 1 or 0.
     *
     * Note: this is not directly related to getPending().
     *
     * @param   bool    $pending    true if pending work is present false otherwise.
     * @return  Review  provides fluent interface
     */
    public function setPending($pending)
    {
        return $this->setRawValue('pending', $pending ? 1 : 0);
    }

    /**
     * This method lets you know if the review has any pending work in the
     * swarm managed change.
     *
     * Note, getPending returns a list of changes that were pending at the
     * time they were associated. It is quite possible getPending would return
     * items but 'isPending' would say no pending work presently exists.
     *
     * @return  bool    true if pending work is present false otherwise.
     */
    public function isPending()
    {
        return (bool) $this->getRawValue('pending');
    }

    /**
     * If the review has at least one committed change associated with it and
     * has no swarm managed pending work we consider it to be committed.
     *
     * @return  bool    true if review is committed false otherwise.
     */
    public function isCommitted()
    {
        return $this->getCommits() && !$this->isPending();
    }

    /**
     * Get the projects this review record is associated with.
     * Each entry in the resulting array will have the project id as the key and
     * an array of zero or more branches as the value. An empty branch array is
     * intended to indicate the project is affected but not a specific branch.
     *
     * @return  array   the projects set on this record.
     * @throws P4Exception
     */
    public function getProjects()
    {
        $projects   = (array) $this->getRawValue('projects');
        $projectDAO = ServicesModelTrait::getProjectDao();
        // remove deleted projects
        foreach ($projects as $project => $branches) {
            if (!$projectDAO->exists($project, $this->getConnection())) {
                unset($projects[$project]);
            }
        }

        return $projects;
    }

    /**
     * Set the projects (and their associated branches) that are impacted by this review.
     * @see ProjectListFilter for details on input format.
     *
     * @param   array|string    $projects   the projects to associate with this review.
     * @return  Review          provides fluent interface
     * @throws  \InvalidArgumentException   if input is not correctly formatted.
     */
    public function setProjects($projects)
    {
        $filter = new ProjectListFilter;
        return $this->setRawValue('projects', $filter->filter($projects));
    }

    /**
     * Add one or more projects (and optionally associated branches)
     *
     * @param   string|array    $projects   one or more projects
     * @return  Review          provides fluent interface
     */
    public function addProjects($projects)
    {
        $filter = new ProjectListFilter;
        return $this->setRawValue('projects', $filter->merge($this->getRawValue('projects'), $projects));
    }

    /**
     * Get API token associated with this review and the latest version.
     * Note: A token is automatically created on save if one isn't already present.
     *
     * The token is intended to provide authorization when performing
     * unauthenticated updates to reviews (e.g. setting test status).
     * It also ensures that updates pertain to the latest version.
     *
     * @return  array   the token for this review with a version suffix
     */
    public function getToken()
    {
        return $this->getRawValue('token') . '.v' . $this->getHeadVersion();
    }

    /**
     * Set API token associated with this review. This method would not
     * normally be used; On save a token will automatically be created if
     * one isn't already set on the review.
     *
     * @param   string|null     $token  the token for this review
     * @return  Review          provides fluent interface
     * @throws  \InvalidArgumentException   if token is not a valid type
     */
    public function setToken($token)
    {
        if (!is_null($token) && !is_string($token)) {
            throw new \InvalidArgumentException(
                'Tokens must be a string or null'
            );
        }

        return $this->setRawValue('token', $token);
    }

    /**
     * Get groups this review record is associated with.
     *
     * @return  array   the groups set on this record.
     */
    public function getGroups()
    {
        $groups = (array) $this->getRawValue('groups');
        return array_values(array_unique(array_filter($groups, 'strlen')));
    }
    /**
     * Set the groups that are impacted by this review.
     *
     * @param   array|string    $groups     the groups to associate with this review.
     * @return  mixed           provides fluent interface
     */
    public function setGroups($groups)
    {
        $groups = array_values(array_unique(array_filter($groups, 'strlen')));
        return $this->setRawValue('groups', $groups);
    }
    /**
     * Add one or more groups.
     *
     * @param   string|array    $groups   one or more groups
     * @return  Review          provides fluent interface
     */
    public function addGroups($groups)
    {
        return $this->setGroups(array_merge($this->getGroups(), (array) $groups));
    }

    /**
     * Get the test details for this code review.
     *
     * @param   bool    $normalize  optional - flag to denote whether we normalize details
     *                              to include version and duration keys, false by default
     * @return  array               test details for this code review
     */
    public function getTestDetails($normalize = false)
    {
        $raw = (array) $this->getRawValue('testDetails');
        return $normalize
            ? $raw + ['version' => null, 'startTimes' => [], 'endTimes' => [], 'averageLapse' => null]
            : $raw;
    }

    /**
     * Set the test details for this code review.
     *
     * @param   array|null   $details    test details to set
     */
    public function setTestDetails($details = null)
    {
        return $this->setRawValue('testDetails', (array) $details);
    }

    /**
     * Get the deploy details for this code review.
     *
     * @return  array   test details for this code review
     */
    public function getDeployDetails()
    {
        return (array) $this->getRawValue('deployDetails');
    }

    /**
     * Set the deploy details for this code review.
     *
     * @param   array|null  $details    test details to set
     * @return  Review      to maintain a fluent interface
     */
    public function setDeployDetails($details = null)
    {
        return $this->setRawValue('deployDetails', (array) $details);
    }

    /**
     * Extends the basic save behavior to also:
     * - update hasReviewer value based on presence of 'reviewers'
     * - set create timestamp to current time if no value was provided
     * - create an api token if we don't already have one
     * - set update timestamp to current time
     *
     * @param array $options options for saving. For example to exclude the updated
     * date. By default all fields are saved.
     * @return mixed to maintain a fluent interface
     * @throws Exception if the review upgrade level is greater that the current
     * system upgrade level.
     */
    public function save($options = [])
    {
        // if upgrade level is higher than anticipated, throw hard!
        // if we were to proceed we could do some damage.
        if ((int) $this->get('upgrade') > static::UPGRADE_LEVEL) {
            throw new Exception('Cannot save. Upgrade level is too high.');
        }

        // add author to the list of participants
        $this->addParticipant($this->get('author'));

        // set hasReviewer flag
        $this->set('hasReviewer', $this->getReviewers() ? 1 : 0);

        // if no create time is already set, use now as a default
        $this->set('created', $this->get('created') ?: time());

        // create a token if we don't already have any
        $this->set('token', $this->getRawValue('token') ?: strtoupper(new Uuid));

        if (!isset($options[Review::EXCLUDE_UPDATED_DATE])) {
            $this->set('updated', time());
        }
        return parent::save();
    }

    /**
     * Gets the original state.
     * @return the original state or null if none is found
     */
    public function getOriginalState()
    {
        $originalState = null;
        if ($this->original && isset($this->original[self::FIELD_STATE])) {
            $originalState = $this->original[self::FIELD_STATE];
        }
        return $originalState;
    }

    /**
     * Get the current upgrade level of this record.
     *
     * @return  int|null    the upgrade level when this record was created or last saved
     */
    public function getUpgrade()
    {
        // if this record did not come from a perforce key (ie. storage)
        // assume it was just made and default to the current upgrade level.
        if (!$this->isFromKey && $this->getRawValue('upgrade') === null) {
            return static::UPGRADE_LEVEL;
        }

        return $this->getRawValue('upgrade');
    }

    /**
     * Upgrade this record on save.
     *
     * @param   KeyRecord|null  $stored     an instance of the old record from storage or null if adding
     */
    protected function upgrade(KeyRecord $stored = null)
    {
        // if record is new, default to latest upgrade level
        if (!$stored) {
            $this->set('upgrade', $this->getRawValue('upgrade') ?: static::UPGRADE_LEVEL);
            return;
        }

        // if record is already at the latest upgrade level, nothing to do
        if ((int) $stored->get('upgrade') >= static::UPGRADE_LEVEL) {
            return;
        }

        // looks like we're upgrading - clear 'original' values so all fields get written
        // @todo move this down to abstract key when/if it gets smart enough to detect upgrades
        $this->original = null;

        // upgrade from 0/unset to 1:
        //  - the 'reviewer' field has been removed
        //  - the 'assigned' field has been renamed to 'hasReviewers' and is now a bool of count(reviewers)
        //  - words in the description field are now indexed in lowercase (for case-insensitive matches)
        //    with leading/trailing punctuation removed and using a slightly different split pattern.
        if ((int) $stored->get('upgrade') === 0) {
            unset($this->values['reviewer']);
            unset($this->values['assigned']);

            // need to de-index old 'assigned' field (can only have two possible values 0/1)
            $this->getConnection()->run(
                self::INDEX,
                ['-a', 1305, '-d', $this->id],
                '30 31'
            );
            $stored->set('hasReviewer', null);

            // need to de-index description the old way
            $words = array_unique(array_filter(preg_split('/[\s,]+/', $stored->get('description')), 'strlen'));
            if ($words) {
                $this->getConnection()->run(
                    self::INDEX,
                    ['-a', 1306, '-d', $this->id],
                    implode(' ', array_map('strtoupper', array_map('bin2hex', $words))) ?: 'EMPTY'
                );

                // clear old value to force re-indexing of non-empty descriptions.
                $stored->set('description', null);
            }
            $this->set('upgrade', 1);
        }

        // upgrade to 2
        //  - versions field has been introduced, get/set it to tickle upgrade code
        if ((int) $stored->get('upgrade') < 2) {
            $this->setVersions($this->getVersions());
            $this->set('upgrade', 2);
        }

        // upgrade to 3
        //  - votes merged into participants field, get/set it to tickle upgrade
        if ((int) $stored->get('upgrade') < 3) {
            $this->setParticipantsData($this->getParticipantsData());
            $this->set('upgrade', 3);
        }

        // upgrade to 4
        //  - votes expanded to array with 'value' and 'version' keys, get/set it to tickle upgrade
        if ((int) $stored->get('upgrade') < 4) {
            $this->setVotes($this->getVotes());
            $this->set('upgrade', 4);
        }

        // upgrade to 5
        // - normalization of participant ids added, get/set it to tickle upgrade
        if ((int) $stored->get('upgrade') < 5) {
            $this->setParticipants($this->getParticipants());
            $this->set('upgrade', 5);
        }
    }

    /**
     * Get topic for this review (used for comments).
     *
     * @return  string  topic for this review
     * @todo    add a getTopics which includes the associated change topics
     */
    public function getTopic()
    {
        return 'reviews/' . $this->getId();
    }

    /**
     * Try to fetch the associated author user as a user spec object.
     *
     * @return  User    the associated author user object
     * @throws  NotFoundException   if user does not exist
     */
    public function getAuthorObject()
    {
        return $this->getUserObject('author');
    }

    /**
     * Check if the associated author user is valid (exists).
     *
     * @return  bool    true if the author user exists, false otherwise.
     */
    public function isValidAuthor()
    {
        return $this->isValidUser('author');
    }

    /**
     * Get the existing approval data for this review, and optional user.
     * @return mixed either null or an map
     */
    public function getApprovals($userid = null)
    {
        $approvals = (array)$this->getRawValue(Review::FIELD_APPROVALS);
        if ($userid) {
            return isset($approvals[$userid]) ? $approvals[$userid] : [];
        }
        return $approvals;
    }

    /**
     * Set a new approvals value. When a userid is provided, the values for that user
     * will be modified with the approvals for other users being preserved.
     * @param array $newApprovals
     * @param null $userid
     */
    public function setApprovals($newApprovals, $userid = null)
    {
        $approvals = (array)$this->getApprovals();
        if ($userid) {
            $approvals[$userid] = $newApprovals;
        } else {
            $approvals = $newApprovals;
        }
        return $this->setRawValue(Review::FIELD_APPROVALS, $approvals);
    }

    /**
     * Set the approval value for a user/version
     * @param $userid
     * @param $version
     * @param $immediate true = save immediately, false(default) = only set the approval
     */
    public function approve($userid, $version, $immediate = false)
    {
        $myApprovals = $this->getApprovals($userid);
        if ($version !== $this->getHeadVersion()) {
            throw new \InvalidArgumentException(
                "$userid cannot approve an out of date version($version) of review ",
                $this->getId()
            );
        }
        // Only set if not already
        isset($myApprovals[$version])?: ($myApprovals[] = $version);
        $this->setApprovals($myApprovals, $userid);
        return $immediate ? $this->save() : $this;
    }

    /**
     * Get a human-friendly label for the current state.
     *
     * @return string
     */
    public function getStateLabel()
    {
        $state = $this->get('state');
        return ucfirst(preg_replace('/([A-Z])/', ' \\1', $state));
    }

    /**
     * Get a list of valid transitions for this review.
     *
     * @return  array   a list with target states as keys and transition labels as values
     */
    public function getTransitions()  : array
    {
        $translator  = ServicesModelTrait::getTranslatorService();
        $transitions = [
            static::STATE_NEEDS_REVIEW         => $translator->t('Needs Review'),
            static::STATE_NEEDS_REVISION       => $translator->t('Needs Revision'),
            static::STATE_APPROVED             => $translator->t('Approve'),
            static::STATE_APPROVED_COMMIT      => $translator->t('Approve and Commit'),
            static::STATE_REJECTED             => $translator->t('Reject'),
            static::STATE_ARCHIVED             => $translator->t('Archive')
        ];

        // exclude current state
        unset($transitions[$this->get('state')]);

        $isCommitting = $this->isCommitting();
        $isPending    = $this->isPending();
        // exclude approve and commit if we lack pending work or are already committing
        if (!$isPending || $isCommitting) {
            unset($transitions[static::STATE_APPROVED_COMMIT]);
        }

        // If we are pending and not currently committing but already approved tweak the approve
        // and commit wording to just say 'Commit'
        if (!$isCommitting && $isPending && $this->getState() == static::STATE_APPROVED) {
            $transitions[static::STATE_APPROVED_COMMIT] = 'Commit';
        }

        if ($this->isProcessing()) {
            unset($transitions[static::STATE_APPROVED]);
            unset($transitions[static::STATE_APPROVED_COMMIT]);
        }

        return $transitions;
    }

    /**
     * Check to see if the review is still processing. We check the versions to see if there is at least one present,
     * if there is then the review is classed as processed. This is to distinguish between an initial review creation
     * before any worker threads have executed and one where the workers have processed it enough to create versions,
     * and link to project(s) etc.
     * @return bool true if the review is still processing.
     */
    public function isProcessing() : bool
    {
        return sizeof($this->getVersions()) === 0;
    }

    /**
     * Deletes the current review and attempts to remove indexes.
     * Extends parent to also delete the swarm managed shelf.
     *
     * @return  Review      to maintain a fluent interface
     * @throws  Exception   if no id is set
     * @throws  \Exception  re-throws any exceptions caused during delete
     * @todo    remove archive changes as well as canonical change
     */
    public function delete()
    {
        if (!$this->getId()) {
            throw new Exception(
                'Cannot delete review, no ID has been set.'
            );
        }

        // attempt to get the associated shelved change we manage
        // if no such change exists, just let parent delete this record
        $p4 = $this->getConnection();
        try {
            $shelf = Change::fetchById($this->getId(), $p4);
        } catch (NotFoundException $e) {
            return parent::delete();
        }

        if ($shelf->isSubmitted()) {
            throw new Exception(
                'Cannot delete review; the shelved change we manage is unexpectedly committed.'
            );
        }

        // we'll need a valid client for this next bit.
        $p4->getService('clients')->grab();
        try {
            $this->deletePendingChangelist($p4, $shelf, $this->getId());

            // now that the shelved files are gone try and delete the actual change
            $p4->run("change", ["-d", "-f", $this->getId()]);
        } catch (\Exception $e) {
        }
        $p4->getService('clients')->release();

        if (isset($e)) {
            throw $e;
        }

        // let parent wrap up by deleting the key record and indexes
        return parent::delete();
    }

    /**
     * Delete the shelved files from the changelist.
     *
     * @param $p4
     * @param $changeList
     * @param $change
     *
     * @throws CommandException
     */
    private function deletePendingChangelist($p4, $changeList, $change)
    {
        // try and hard reset the client to ensure a clean environment
        $p4->getService('clients')->reset(true, $this->getHeadStream());
        // If the shelf associated with this review isn't already on
        // the right client, likely won't be, swap it over and save.
        if ($changeList->getClient() != $p4->getClient() || $changeList->getUser() != $p4->getUser()) {
            $changeList->setClient($p4->getClient())->setUser($p4->getUser())->save(true);
        }
        try {
            // First we attempt to delete any shelved files and changelist.
            $p4->run('shelve', ['-d', '-f', '-c', $change]);
        } catch (CommandException $e) {
            if (strpos($e->getMessage(), 'No shelved files in changelist to delete.') === false) {
                // log that we couldn't find any shelved files
                Logger::log(
                    Logger::DEBUG,
                    self::REVIEW_OBLITERATE .' No shelved files in changelist to delete.'
                );
                throw $e;
            } else {
                // log that we attempted to remove files.
                Logger::log(
                    Logger::DEBUG,
                    self::REVIEW_OBLITERATE .' Attempted to remove shelve files from changelist [' . $change
                    . ']!'
                );
            }
            unset($e);
        }
    }

    /**
     * Obliterate the current review and attempts to remove indexes.
     * Extends parent to also delete the swarm key and index data.
     *
     * @param   bool    $removeChangelist  Should we remove the pending changelist for swarm.
     * @return  array                      to maintain a fluent interface
     * @throws  Exception                  if no id is set
     * @throws  \Exception  re-throws any exceptions caused during delete
     */
    public function obliterate($removeChangelist = true)
    {
        $messages = [];
        if (!$this->getId()) {
            $message    = 'Cannot obliterate review, no ID has been set.';
            $messages[] = $message;
            throw new Exception($message);
        }

        // attempt to get the associated shelved change we manage
        // if no such change exists, just let parent delete this record
        try {
            if ($removeChangelist === true) {
                $reviewRevisions = $this->getVersions();
                foreach ($reviewRevisions as $change) {
                    // Get the change that needs to be removed.
                    if (isset($change['archiveChange'])) {
                        $changeID = $change['archiveChange'];
                    } else {
                        $changeID = $change['change'];
                    }

                    try {
                        // call each change to be removed.
                        $this->obliterateRevision($changeID);
                    } catch (\Exception $e) {
                        // This will be check with in the later error check.
                        Logger::log(
                            Logger::TRACE,
                            self::REVIEW_OBLITERATE . "Review " . $changeID
                            . " found an issue trying to obliterate change " . $changeID . " error: " . $e->getMessage()
                        );
                    }
                }
                // Finally remove the review changelist
                $this->obliterateRevision($this->getId());
            }
        } catch (NotFoundException $e) {
            $messages[] = $e->getMessage();
            Logger::log(Logger::TRACE, self::REVIEW_OBLITERATE .' detected an error ' .$e->getMessage());
            unset($e);
        }

        // let parent wrap up by deleting the key record and indexes
        parent::delete();
        return $messages;
    }

    /**
     * Obliterate the current revision.
     * Extends parent to also delete the swarm managed shelf.
     *
     * @param   int $change  The revision of the review to be removed.
     * @throws  \Exception   re-throws any exceptions caused during delete
     */
    private function obliterateRevision($change)
    {
        $p4 = $this->getConnection();
        try {
            $changeList = Change::fetchById($change, $p4);
            if ($changeList->isSubmitted()) {
                return;
            }
            // we'll need a valid client for this next bit.
            $p4->getService('clients')->grab();
            try {
                $this->deletePendingChangelist($p4, $changeList, $change);
                try {
                    // If the deletion of the changelist was unsuccessful from the shelve command try just a standard
                    // changelist deletion.
                    $p4->run("change", ["-d", "-f", $change]);
                } catch (CommandException $ex) {
                    Logger::log(Logger::DEBUG, self::REVIEW_OBLITERATE .' Unable to delete changelist ' . $change);
                    Logger::log(
                        Logger::TRACE,
                        self::REVIEW_OBLITERATE .' Unable to delete changelist ' . $change . ' Error:'
                        . $ex->getMessage()
                    );
                    unset($ex);
                }
                // Remove all the meta data about this change from the review.
                $this->removeChangeFromReviewMetaData($change);
            } catch (\Exception $e) {
                // This will be check with in the later error check.
            }
            // ensure we release the clients.
            $p4->getService('clients')->release();
        } catch (NotFoundException $e) {
            // let the end deal with the logging of output
        }

        // Check if we have any errors at the end. Report these in trace only.
        if (isset($e)) {
            Logger::log(Logger::TRACE, self::REVIEW_OBLITERATE .' Detected an error ' .$e->getMessage());
            throw $e;
        }
    }

    /**
     * Remove all meta data from the review model for a given change.
     *
     * @param $change
     * @throws Exception
     */
    private function removeChangeFromReviewMetaData($change)
    {
        // First remove the version information
        $this->removeChangeFromReviewVersion($change);
        // Then remove the changelist from the changes list.
        $this->removeChangeFromReviewChanges($change);
    }

    /**
     * Remove the change from the Review changes meta data
     *
     * @param $change
     * @throws Exception
     */
    private function removeChangeFromReviewChanges($change)
    {
        // First get the changes for this review and find position of the changelist in the array.
        $reviewChanges = $this->getChanges();
        $pos           = array_search($change, $reviewChanges);
        // Then unset that change from the list and save the new amended list.
        unset($reviewChanges[$pos]);
        $this->setChanges($reviewChanges);
        $this->save([self::EXCLUDE_UPDATED_DATE]);
    }

    /**
     * Remove the change from the Review version meta data
     *
     * @param $change
     * @throws Exception
     */
    private function removeChangeFromReviewVersion($change)
    {
        // First get the version of this review and unset that one that is the given changelist.
        $reviewVersions = $this->getVersions();
        foreach ($reviewVersions as $key => $version) {
            if ($version['change'] === $change) {
                unset($reviewVersions[$key]);
            }
        }
        // Then set the new version list back and save back to the review.
        $this->setVersions($reviewVersions);
        $this->save([self::EXCLUDE_UPDATED_DATE]);
    }

    /**
     * Fetch an epoch value trimmed to midnight
     *
     * @return mixed
     */
    public function getEpochDay($value)
    {
        return $value-($value%86400);
    }


    /**
     * Attempts to figure out what stream (if any) the head version of this review
     * is against. Useful for committing the work as you'll need to be on said stream.
     *
     * @return null|string  the streams path as a string, if we can identify one, otherwise null
     */
    protected function getHeadStream()
    {
        // try to determine the stream we aught to use from the version history
        $versions = $this->getVersions();
        $version  = end($versions);
        if (is_array($version) && array_key_exists('stream', $version)) {
            return $version['stream'];
        }

        // if its not recorded and the head version is a pending change
        // we can try to guess the stream from the shelved file paths.
        if (isset($version['change'], $version['pending']) && $version['pending']) {
            return $this->guessStreamFromChange($version['change']);
        }

        // looks like we don't have a clue; lets assume not a stream
        return null;
    }

    /**
     * Checks the first file in a change to see if it points to a streams depot.
     * Note, this check may not work reliably on streams with writable imports.
     *
     * @param   int|string|Change   $change     the change to look at for our guess
     * @return  null|string         the streams path as a string, if we can identify one, otherwise null
     */
    protected function guessStreamFromChange($change)
    {
        $p4     = $this->getConnection();
        $change = $change instanceof Change ? $change : Change::fetchById($change, $p4);
        $id     = $change->getId();
        $flags  = $change->isPending() ? ['-Rs'] : [];
        $flags  = array_merge($flags, ['-e', $id, '-m1', '-T', 'depotFile', '//...@=' . $id]);
        $result = $p4->run('fstat', $flags);
        $file   = $result->getData(0, 'depotFile');

        // if the change is empty, we can't do the check
        if ($file === false) {
            return null;
        }

        // grab the depot off the first file and check if it points to a stream depot
        // if so, return the //<depot> followed by path components equal to stream depth (this
        // field is present only on new servers, on older ones we take just the first one)
        $pathComponents = array_filter(explode('/', $file));
        $depot          = Depot::fetchById(current($pathComponents), $p4);
        if ($depot->get('Type') == 'stream') {
            $depth = $depot->hasField('StreamDepth') ? $depot->getStreamDepth() : 1;
            return count($pathComponents) > $depth
                ? '//' . implode('/', array_slice($pathComponents, 0, $depth + 1))
                : null;
        }

        return null;
    }

    /**
     * Synchronizes the current review's description as well as the descriptions of any associated changes.
     *
     * @param   string           $reviewDescription  the description to use for the review (review keywords stripped)
     * @param   string           $changeDescription  the description to use for the change (review keywords intact)
     * @param   Connection|null  $connection         the perforce connection to use - should be p4 admin, since the
     *                                               current user may not own all the associated changes
     * @return  bool             true if the review description was modified, false otherwise
     */
    public function syncDescription($reviewDescription, $changeDescription, $connection = null)
    {
        $wasModified = false;

        // update the review with the new review description, if needed
        if ($this->getDescription() != $reviewDescription) {
            $this->setDescription($reviewDescription)->save();

            // since we changed the description, we've modified this review
            $wasModified = true;
        }

        // update descriptions for all changes associated with the review
        try {
            $connection = $connection ?: $this->getConnection();
            $connection->getService('clients')->grab();
            foreach ($this->getChanges() as $changeId) {
                $change = Change::fetchById($changeId, $connection);

                // note: we only want to save the change if the description was changed, since this will trigger
                // an infinite number of changesave events otherwise
                if ($change->getDescription() != $changeDescription) {
                    $change->setDescription($changeDescription)
                           ->save(true);
                }
            }
        } catch (\Exception $e) {
            Logger::log(Logger::ERR, $e);
        }

        $connection->getService('clients')->release();
        return $wasModified;
    }

    /**
     * Try to fetch the associated user (for given field) as a user spec object.
     *
     * @param   string  $userField  name of the field to get user object for
     * @return  User    the associated user object
     * @throws  NotFoundException   if user does not exist
     */
    protected function getUserObject($userField)
    {
        $userDao = self::getUserDao();
        if (!isset($this->userObjects[$userField])) {
            $this->userObjects[$userField] = $userDao->fetchById(
                $this->get($userField),
                $this->getConnection()
            );
        }

        return $this->userObjects[$userField];
    }

    /**
     * Check if the associated user (for given field) is valid (exists).
     *
     * @param   string  $userField  name of the field to check user for
     * @return  bool    true if the author user exists, false otherwise.
     */
    protected function isValidUser($userField)
    {
        try {
            $this->getUserObject($userField);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * Override parent to prepare 'project' field values for indexing.
     *
     * @param   int                 $code   the index code/number of the field
     * @param   string              $name   the field/name of the index
     * @param   string|array|null   $value  one or more values to index
     * @param   string|array|null   $remove one or more old values that need to be de-indexed
     * @return  Review              provides fluent interface
     */
    public function index($code, $name, $value, $remove)
    {
        // convert 'projects' field values into the form suitable for indexing
        // we index projects by project-id, but also by project-id:branch-id.
        if ($name === 'projects') {
            $value  = array_merge(array_keys((array) $value),  static::flattenForIndex((array) $value));
            $remove = array_merge(array_keys((array) $remove), static::flattenForIndex((array) $remove));
        }

        return parent::index($code, $name, $value, $remove);
    }

    /**
     * Called when an auto-generated ID is required for an entry.
     *
     * Extends parent to create a new changelist and use its change id
     * as the identifier for the review record.
     *
     * @return  string      a new auto-generated id. the id will be 'encoded'.
     * @throws  \Exception  re-throws any errors which occur during change save operation
     */
    protected function makeId()
    {
        $p4    = $this->getConnection();
        $shelf = new Change($p4);
        $shelf->setDescription($this->get('description'));

        // we grab the client tightly around save to avoid
        // locking it for any longer than we have to.
        $p4->getService('clients')->grab();
        try {
            $shelf->save();
        } catch (\Exception $e) {
        }
        $p4->getService('clients')->release();

        if (isset($e)) {
            throw $e;
        }

        return $this->encodeId($shelf->getId());
    }

    /**
     * Extends parent to undo our flip logic and hex decode.
     *
     * @param   string  $id     the stored id used by p4 key
     * @return  string|int      the user facing id
     */
    public static function decodeId($id)
    {
        // nothing to do if the id is null
        if ($id === null) {
            return null;
        }

        // strip off our key prefix
        $id = substr($id, strlen(static::KEY_PREFIX));

        // hex decode it and subtract from 32 bit int to undo our sorting trick
        return (int) (0xFFFFFFFF - hexdec($id));
    }

    /**
     * Produces a 'p4 search' expression for the given field/value pairs.
     *
     * Extends parent to allow including pending status in the state filter.
     * The syntax is <state>:(isPending|notPending) e.g.:
     * approved:notPending
     *
     * @param   array   $conditions     field/value pairs to search for
     * @return  string  a query expression suitable for use with p4 search
     */
    protected static function makeSearchExpression($conditions)
    {
        // normalize conditions and pull out the 'states' for us to deal with
        $conditions += [static::FETCH_BY_STATE => ''];
        $states      = $conditions[static::FETCH_BY_STATE];

        // start by letting parent handle all other fields
        unset($conditions[static::FETCH_BY_STATE]);
        $expression = parent::makeSearchExpression($conditions);

        // go over all state(s) and utilize parent to build expression for the state and
        // optional isPending/notPending field. We do them one at a time to allow us to
        // bracket the output when the expression has both state and pending.
        $expressions = [];
        foreach ((array) $states as $state) {
            $conditions = [];
            $parts      = explode(':', $state);

            // if state appears to contain an isPending or notPending component split it
            // into separate state and pending conditions, otherwise simply keep it as is.
            if (count($parts) == 2 && ($parts[1] == 'isPending' || $parts[1] == 'notPending')) {
                $conditions[static::FETCH_BY_STATE] = $parts[0];
                $conditions['pending']              = $parts[1] == 'isPending' ? 1 : 0;
            } else {
                $conditions[static::FETCH_BY_STATE] = $state;
            }

            // use parent to make the state's expression then add it to the pile and
            // bracket it if we asked for both the state and pending filter
            $state         = parent::makeSearchExpression($conditions);
            $expressions[] = count($conditions) > 1 ? '(' . $state . ')' : $state;
        }

        // now that we've collected up all the state expressions, implode and bracket
        // the whole thing if more than one state's involved
        $states      = implode(' | ', $expressions);
        $expression .= ' ' . (count($expressions) > 1 ? '(' . $states . ')' : $states);

        return trim($expression);
    }

    /**
     * Turn the passed key into a record.
     * Extends parent to detect review type and create the appropriate review class.
     *
     * @param   Key             $key        the key to record'ize
     * @param   string|callable $className  optional - class name to use, static by default
     * @return  Review  the record based on the passed key's data
     */
    protected static function keyToModel($key, $className = null)
    {
        return parent::keyToModel(
            $key,
            $className ?: function ($data) {
                // if the data includes a type of git; make a git model
                // otherwise create a standard review.
                return isset($data['type']) && $data['type'] === 'git'
                    ? '\Reviews\Model\GitReview'
                    : '\Reviews\Model\Review';
            }
        );
    }

    /**
     * This is going to create a new changelist that contains no files. This is to show that
     * all the files from the review have been cleaned up.
     *
     * @param   Change  $change             the change that contained the delete
     * @param   array   $versionDetails     extra details to include in version entry, e.g. difference => true
     * @param   string  $user               This is for the delete incase the change doesn't exist.
     * @return  Review  provides fluent interface
     */
    protected function createEmptyChangelist(Change $change, $versionDetails, $user = null)
    {
        // make a new change matching the shelf's type and description.
        $p4        = $this->getConnection();
        $newChange = new Change($p4);
        $newChange->setType($change->getType())
            ->setDescription($change->getDescription())
            ->save();

        // to avoid any ambiguity when the shelve-commit trigger fires we add the
        // new archive change/version to the review record before we shelve
        $version = $versionDetails + [
                'change'     => $newChange->getId(),
                'user'       => $user !== null ? $user: $change->getUser(),
                'time'       => time(),
                'pending'    => true
            ];
        $this->addVersion($version)
            ->addChange($newChange->getId())
            ->save();

        return $this;
    }

    /**
     * Copy files and description to a new shelved change and add a version entry.
     * We use shelved changes for versioning so that users can un-shelve old versions
     * and so that the rest of our diff/etc. code works with them seamlessly.
     * This method is only intended to be called from updateFromChange().
     *
     * @param Change  $shelf          the shelved change to archive files from
     * @param array   $versionDetails extra details to include in version entry, e.g. difference => true
     * @param boolean $append         Whether to append or replace an existing review
     * @param string  $user           This is for the delete incase the change doesn't exist.
     * @return  Review  provides fluent interface
     * @throws CommandException
     * @throws Exception
     * @throws P4Exception
     * @throws \P4\Spec\Exception\UnopenedException
     */
    protected function archiveShelf(Change $shelf, $versionDetails, $append, $user = null)
    {
        // make a new change matching the shelf's type and description.
        $p4     = $this->getConnection();
        $change = new Change($p4);
        $change->setType($shelf->getType())
               ->setDescription($shelf->getDescription())
               ->save();

        // to avoid any ambiguity when the shelve-commit trigger fires we add the
        // new archive change/version to the review record before we shelve
        $version = $versionDetails + [
            'change'                => $change->getId(),
            'user'                  => $user !== null ? $user: $shelf->getUser(),
            'time'                  => time(),
            'pending'               => true,
            Review::ADD_CHANGE_MODE => $append ? Review::APPEND_MODE : Review::REPLACE_MODE
            ];
        $this->addVersion($version)
             ->addChange($change->getId())
             ->save();

        $changeService   = self::getChangeService();
        $defaultFlags    = [];
        $streamSpecFlags = array_merge(['-Si'], $defaultFlags);
        // If we have a stream spec diff we need to reopen with the -Si else just do normal reopen
        $flags = isset($versionDetails[self::STREAMSPECDIFF]) && $versionDetails[self::STREAMSPECDIFF]
            ? $streamSpecFlags
            : $defaultFlags;
        try {
            // now we can move the files into our archive change and shelve them.
            $changeService->reopen(
                $p4,
                [P4Command::COMMAND_FLAGS => $flags, P4Command::INPUT => ['//...']],
                $change
            );
            //reopen(Connection $connection, array $options, P4Change $change)
        } catch (CommandException $e) {
            // Failed to run reopen if stream spec diff fall back to old flags.
            if ($versionDetails[self::STREAMSPECDIFF]) {
                $changeService->reopen(
                    $p4,
                    [P4Command::COMMAND_FLAGS => $flags, P4Command::INPUT => ['//...']],
                    $change
                );
            }
        }
        $changeService->shelve($p4, [], $change);
        return $this;
    }

    protected function deleteFileFromShelf(Change $shelf, $files)
    {
        // make a new change matching the shelf's type and description.
        $p4 = $this->getConnection();

        $shelfId = $shelf->getId();

        // p4 shelve -f -d -c 2439 //projects/alpha/main/docs/release_notes.txt //projects/alpha/main/readme.md
        // //projects/alpha/README.txt
        $flags = ['-f', '-d', '-c', $shelfId];

        foreach ($files as $file) {
            $flags[] = $file;
        }
        try {
            $results = $p4->run('shelve', $flags);
        } catch (P4Exception $e) {
            Logger::log(Logger::WARN, $e->getMessage());
            return false;
        }

        if (isset($results)) {
            $message  = $results->getData(0);
            $warnings = $results->getWarnings(0);
            Logger::log(Logger::TRACE, "Review/ShelfDelete:: " . $message);
            foreach ($warnings as $warning) {
                Logger::log(Logger::TRACE, "Review/ShelfDelete::WARNING: " . $warning);
            }
        }


        return true;
    }

    /**
     * Rescue files from a pre-versioning review (upgrade scenario).
     *
     * Copy files and description to a new shelved change and update the latest
     * version in our versions metadata to point to the new change.
     *
     * This method is only intended to be called from updateFromChange().
     *
     * @param   Change  $shelf  the canonical shelved change to archive files from
     * @return  Review  provides fluent interface
     * @todo    centralize this more robust unshelve logic and use it elsewhere
     */
    protected function retroactiveArchive(Change $shelf)
    {
        // determine if we have any files to archive
        // we expect some files may fail to unshelve (this happens on <13.1
        // servers with added files that are now submitted) we capture these
        // files and sync/edit/print them manually to save the file contents
        $p4      = $this->getConnection();
        $result  = $p4->run('unshelve', ['-s', $shelf->getId()]);
        $opened  = 0;
        $failed  = [];
        $pattern = "/^Can't unshelve (.*) to open for [a-z\/]+: file already exists.$/";
        foreach ($result->getData() as $data) {
            if (is_array($data)) {
                $opened++;
            } elseif (preg_match($pattern, $data, $matches)) {
                $failed[] = $matches[1];
            }
        }

        // if there were no files to unshelve, exit early.
        if (!$opened && !$failed) {
            return $this;
        }

        // emulate unshelve for out-dated adds on <13.1 servers
        if ($failed) {
            $p4->run('sync', array_merge(['-k'], $failed));
            $p4->run('edit', array_merge(['-k'], $failed));
            foreach ($failed as $file) {
                $local = $p4->run('where', $file)->getData(0, 'path');
                $p4->run('print', ['-o', $local, $file . '@=' . $shelf->getId()]);
            }
        }

        // now that we know we have files to rescue - make a new change for them.
        $change = new Change($p4);
        $change->setType($shelf->getType())
               ->setDescription($shelf->getDescription())
               ->save();

        // to avoid any ambiguity when the shelve-commit trigger fires we add the
        // new archive change/version to the review record before we shelve
        $versions                                        = $this->getVersions();
        $versions[count($versions) - 1]['archiveChange'] = $change->getId();
        $this->setVersions($versions)
             ->addChange($change->getId())
             ->save();

        // now we can move the files into our archive change and shelve them.
        $p4->run('reopen', ['-c', $change->getId(), '//...']);
        $p4->run('shelve', ['-c', $change->getId()]);

        // shelving leaves files open in the workspace, we need to clean those up
        // otherwise they will interfere with updating the canonical shelf later
        $p4->getService('clients')->clearFiles();

        return $this;
    }

    /**
     * Pending head revisions are stored twice, once in the canonical shelf and again in an archive shelf.
     * This method ensures the head version points to the canonical shelf, but older versions do not.
     *
     * @param   array   $versions   the list of versions to normalize
     * @return  array   the normalized versions with head/non-head change issues sorted
     */
    protected function normalizeVersions(array $versions)
    {
        $versionKeys = array_keys($versions);
        $last        = end($versionKeys);
        foreach ($versions as $key => $version) {
            // if we see a pending head rev that does not point to the canonical shelf,
            // update it to point there and capture the archive change for later use.
            if ($version['pending'] && $version['change'] != $this->getId() && $key == $last) {
                $versions[$key]['archiveChange'] = $version['change'];
                $versions[$key]['change']        = $this->getId();
            }

            // if we find a non-head rev that points to the canonical shelf, update it
            // to reference the archive change or drop it if it has no archive change
            // if it has no archive change, it is most likely cruft from the upgrade code
            if ($version['change'] == $this->getId() && $key != $last) {
                if (isset($version['archiveChange'])) {
                    $versions[$key]['change'] = $version['archiveChange'];
                    unset($versions[$key]['archiveChange']);
                } else {
                    unset($versions[$key]);
                }
            }
        }

        return array_values($versions);
    }

    /**
     * General normalization of participants data.
     *
     * @param   array|null  $participants   the participants array to normalize
     * @param   bool        $forStorage     optional - flag to denote whether we normalize for storage
     *                                      passed to normalizeVote(), false by default
     * @return  array       normalized participants data
     */
    protected function normalizeParticipants($participants, $forStorage = false)
    {
        // - ensure value is an array
        // - ensure each entry is an array
        // - ensure the author is always present
        // - ensure we're sorted by user id
        // - ensure properties are sorted by key
        // - drop empty properties, at present we only store votes/required and
        //   its a waste of space (and less normalized) to store empty versions
        $participants = array_filter((array) $participants, 'is_array');
        if ($this->get('author')) {
            $participants += [$this->get('author') => []];
        }
        uksort($participants, 'strnatcasecmp');

        foreach ($participants as $id => $participant) {
            $participant        += ['vote' => []];
            $participant['vote'] = $this->normalizeVote($id, $participant['vote'], $forStorage);
            // Leave the vote filtering well alone for safety but if we have a minimum requirement make
            // sure it gets added in after any filtering regardless of its value (we do not want '0' to
            // be discarded)
            $minimumRequirement = null;
            if (isset($participant[Review::FIELD_MINIMUM_REQUIRED])) {
                $minimumRequirement = $participant[Review::FIELD_MINIMUM_REQUIRED];
            }
            $participants[$id] = array_filter($participant);
            if ($minimumRequirement !== null) {
                $participants[$id][Review::FIELD_MINIMUM_REQUIRED] = $minimumRequirement;
            }

            uksort($participants[$id], 'strnatcasecmp');
        }

        // Forcing the keys into string format.
        if (!empty($participants)) {
            $keys         = array_keys($participants);
            $values       = array_values($participants);
            $stringKeys   = array_map('strval', $keys);
            $participants = array_combine($stringKeys, $values);
        }
        return $participants;
    }

    /**
     * Normalization of user id casing.
     * If normalization reveals more than one entry for a user, we eliminate duplicates and combine their values.
     *
     * @param   array           $participants      the participants array to normalize
     * @param   Connection      $p4                the perforce connection to use
     * @return  array                              the normalized result
     */
    protected function normalizeParticipantIds($participants, Connection $p4)
    {
        // early exit for case sensitive servers
        if ($p4->isCaseSensitive()) {
            return $participants;
        }

        $normalized = [];
        $userDao    = self::getUserDao();
        foreach ($participants as $id => $values) {
            if ($userDao->exists($id, $p4)) {
                $normalizedId = $userDao->fetchById($id, $p4)->getId();

                if (!isset($normalized[$normalizedId])) {
                    $normalized[$normalizedId] = [];
                }

                // merge properties for duplicate entries
                // values taken from entry with correct casing win
                $normalized[$normalizedId] = $id === $normalizedId
                    ? $normalized[$normalizedId] + $values
                    : $values + $normalized[$normalizedId];
            } else {
                // we don't know which casing is correct so just
                // preserve the one in the first occurrence
                $normalizedId   = $id;
                $normalizedKeys = array_keys($normalized);
                $match          = array_search(
                    parent::lowercase($normalizedId),
                    array_map('parent::lowercase', $normalizedKeys)
                );
                $normalizedId   = $match !== false ? $normalizedKeys[$match] : $normalizedId;

                if (!isset($normalized[$normalizedId])) {
                    $normalized[$normalizedId] = [];
                }

                $normalized[$normalizedId] = $normalized[$normalizedId] + $values;
            }
        }

        return $normalized;
    }

    /**
     * If we were passed vote with valid 'value', we will ensure 'version' and 'isStale' is also present
     * ('isStale' is always recalculated).
     * If a non-array is passed, we will move the passed value under the 'value' key.
     * If no version is present, we will set the version to head.
     *
     * @param   string          $user           user of the vote
     * @param   array|string    $vote           vote to normalize
     * @param   bool            $forStorage     flag to denote whether we normalize for storage or not
     *                                          false by default; if true, then 'isStale' property will
     *                                          not be included
     * @return  array|false     normalized vote as array with 'value', 'version' and optionally
     *                          'isStale' keys or false if 'value' was invalid or user is the author
     */
    protected function normalizeVote($user, $vote, $forStorage)
    {
        // for non-array, shift the input under the 'value' key
        $vote = is_array($vote) ? $vote : ['value' => $vote];

        // if the user is the author or the vote is missing/invalid bail
        if ((string)$user === $this->get('author') ||
            !isset($vote['value']) ||
            !in_array($vote['value'], [1, -1])
        ) {
            return false;
        }

        if (!isset($vote['version']) || !ctype_digit((string) $vote['version'])) {
            $vote['version'] = $this->getHeadVersion();
        }
        $vote['version'] = (int) $vote['version'];

        if ($forStorage) {
            unset($vote['isStale']);
        } else {
            $vote['isStale'] = $this->isStaleVote($vote);
        }

        return $vote;
    }

    /**
     * If the vote is out-dated and a newer version of the review has file changes, the vote is stale.
     * Otherwise you have voted on the same files as the latest version, so the vote is not stale.
     *
     * @param   array   $vote   vote to check
     * @return  boolean         true if vote is stale, false otherwise
     */
    protected function isStaleVote(array $vote)
    {
        // loop over the versions, oldest to newest
        $votedOn = isset($vote['version']) ? (int) $vote['version'] : 0;
        foreach ($this->getVersions() as $key => $version) {
            // skip old versions and the version voted on
            // note key starts at zero, votedOn starts at 1
            if ($key < $votedOn) {
                continue;
            }

            // if 'difference' isn't present or its invalid, assume its different and return stale
            if (!isset($version['difference'])
                || !ctype_digit((string) $version['difference'])
                || !in_array($version['difference'], [0, 1, 2])
            ) {
                return true;
            }

            // return stale if significant change occurred, otherwise keep scanning
            // 0 - no changes, 1 - modified name, type or digest, 2 - modified only insignificant fields
            if ($version['difference'] == 1) {
                return true;
            }
        }

        // the vote is not stale
        return false;
    }

    /**
     * Check for files that cannot be opened because they are already exclusively open.
     * We need an explicit check for this because it is not reported as an error or a warning.
     *
     * @param   CommandResult  $result  the command output to examine
     * @throws  Exception      if any of the files are already open exclusively elsewhere
     */
    protected function exclusiveOpenCheck(CommandResult $result)
    {
        $output = array_merge($result->getData(), $result->getWarnings());
        foreach ($output as $block) {
            if (is_string($block) && strpos($block, 'exclusive file already opened')) {
                throw new Exception(
                    'Cannot unshelve review (' . $this->getId() . '). ' .
                    'One or more files are exclusively open. ' .
                    'Ensure you have Perforce Server version 2014.2/1073410+ ' .
                    'with the filetype.bypasslock configurable enabled.'
                );
            }
        }
    }

    /**
     * Check if the server we are talking to supports bypassing +l
     *
     * @return  bool  true if the server is newer than 2014.2/1073410
     */
    protected function canBypassLocks()
    {
        $p4       = $this->getConnection();
        $identity = $p4->getServerIdentity();

        return $p4->isServerMinVersion('2014.2') && $identity['build'] >= 1073410;
    }

    /**
     * Gets whether the participant has a non stale up or down vote.
     * @param $participant a user name or 'swarm-group-' + group name
     * @return bool
     * @throws P4Exception
     */
    public function hasParticipantVoted($participant)
    {
        return $this->hasParticipantVotedUp($participant) || $this->hasParticipantVotedDown($participant);
    }

    /**
     * Gets whether the participant has a non stale up vote.
     * @param mixed $participant a user name or 'swarm-group-' + group name
     * @return bool
     * @throws P4Exception
     */
    public function hasParticipantVotedUp($participant)
    {
        return $this->getVote($participant, $this->getUpVotes()) !== null;
    }

    /**
     * Gets whether the participant has a non stale down vote.
     * @param $participant a user name or 'swarm-group-' + group name
     * @return bool
     * @throws P4Exception
     */
    public function hasParticipantVotedDown($participant)
    {
        return $this->getVote($participant, $this->getDownVotes()) !== null;
    }

    /**
     * Gets whether the review has any non stale up votes.
     * @return bool
     */
    public function hasAnyUpVotes()
    {
        $upVotes = $this->getUpVotes();
        return $upVotes && !empty($upVotes);
    }

    /**
     * Gets vote for the participant
     * @param $participant a user name or 'swarm-group-' + group name
     * @param $votes
     * @return null
     * @throws P4Exception
     */
    private function getVote($participant, $votes)
    {
        if ($votes) {
            $caseSensitive = $this->getConnection()->isCaseSensitive();

            foreach ($votes as $key => $vote) {
                if (($caseSensitive && $key === $participant) || strcasecmp($key, $participant) === 0) {
                    return $vote;
                }
            }
        }
        return null;
    }

    /**
     * Checks if the participant is required.
     * True if a group and require 1 or require all
     * True if an individual and required OR individual is in a required group
     * (either all required or quorum) (required wins if in multiple groups)
     * @param ConnectionInterface $p4 the connection
     * @param $participantToFind the participant to look for
     * @return bool
     */
    public function isParticipantRequired(Connection $p4, $participantToFind)
    {
        return $this->isParticipantDirectlyRequired($p4, $participantToFind) ||
               $this->isParticipantRequiredAsPartOfGroup($p4, $participantToFind);
    }

    /**
     * Checks to see if the participant is directly required.
     * True if the participant is an individual or a group that has the required property
     * @param ConnectionInterface $p4 the connection
     * @param $participantToFind the participant to look for
     * @return bool
     */
    public function isParticipantDirectlyRequired(Connection $p4, $participantToFind)
    {
        foreach ($this->getParticipantsData('required') as $participant => $participantData) {
            if ($participantToFind === $participant) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks to see if the user is required because of group membership.
     * True if an individual and required OR individual is in a required group
     * (either all required or quorum) (required wins if in multiple groups).
     * @param ConnectionInterface $p4 the connection
     * @param $user the user
     * @return bool
     */
    public function isParticipantRequiredAsPartOfGroup(Connection $p4, $user)
    {
        if (!Group::isGroupName($user)) {
            $groupDAO = ServicesModelTrait::getGroupDao();
            // Check individuals group membership to work out requirement
            foreach ($this->getParticipantsData('required') as $participant => $participantData) {
                if (Group::isGroupName($participant) && $groupDAO->exists(Group::getGroupName($participant))) {
                    if ($groupDAO->isMember($user, Group::getGroupName($participant), true, $p4)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Tests if the review is ready for approval taking into account required reviewers and any minimum vote
     * settings on projects and branches. This function also takes into account any blocking tests if workflows
     * are enabled. The first check made is to see if the review is new and still being processed, if it is then
     * no approval can be allowed and this short circuits the other checks.
     *
     * @param mixed               $includeExtraUpVotes      optional up votes to include when assessing. The votes will
     *                                                      not actually be added to the review but the determination as
     *                                                      to whether there are any outstanding votes will include
     *                                                      these. Should be a list of participant ids that we will
     *                                                      assume have voted up.
     * @param mixed               $impacted                 impacted projects to check workflows
     * @param mixed               $workflowsEnabled         whether workflows are enabled. If not enabled we do not need
     *                                                      to get the branch rule for counted votes
     * @param mixed               $branchRules              Array reference to branch rules already found
     *
     * @return bool if the review is processed and the review is ready for approval taking into account required
     * reviewers and any minimum vote settings on projects and branches and any workflow rules if enabled. If enabled
     * workflows will be examined for blocking tests that prevent approval. Return true if the review can be approved,
     * false otherwise
     * @throws NotFoundException
     * @throws P4Exception
     * @throws RecordNotFoundException
     */
    public function canApprove($includeExtraUpVotes, $impacted, $workflowsEnabled = true, &$branchRules = []) : bool
    {
        $p4               = static::getServices()->get(ConnectionFactory::P4_ADMIN);
        $isBlockedByTests = false;
        $canApprove       = !$this->isProcessing();
        if ($canApprove) {
            if ($workflowsEnabled) {
                $workflowManager  = ServicesModelTrait::getWorkflowManager();
                $isBlockedByTests = $workflowManager->isBlockedByTests($this, $impacted, IReview::STATE_APPROVED);
            }
            $canApprove =
                !$isBlockedByTests &&
                !$this->hasOutstandingVotes($p4, $includeExtraUpVotes) &&
                !$this->hasOutstandingMinVotes($p4, $includeExtraUpVotes, $impacted, $workflowsEnabled, $branchRules);
        }
        return $canApprove;
    }

    /**
     * Checks to see if a review has outstanding votes.
     * @param ConnectionInterface   $p4                     p4 connection
     * @param array                 $includeExtraUpVotes    optional up votes to include when assessing. The votes will
     *                                                      not actually be added to the review but the determination as
     *                                                      to whether there are any outstanding votes will include
     *                                                      these. Should be a list of participant ids that we will
     *                                                      assume have voted up.
     * @return bool
     * @throws NotFoundException
     * @throws P4Exception
     * @throws RecordNotFoundException
     */
    public function hasOutstandingVotes(Connection $p4, $includeExtraUpVotes = []) : bool
    {
        $caseSensitive = $this->getConnection()->isCaseSensitive();
        $groupDAO      = ServicesModelTrait::getGroupDao();
        foreach ($this->getParticipantsData() as $participant => $participantData) {
            $outStanding = false;
            if (isset($participantData['required'])) {
                if (Group::isGroupName($participant) && $groupDAO->exists(Group::getGroupName($participant))) {
                    $group   = $groupDAO->fetchById(Group::getGroupName($participant), $p4);
                    $members = $groupDAO->fetchMembers(
                        $group->getId(),
                        [
                            Group::FETCH_INDIRECT     => true
                        ],
                        $p4
                    );
                    if ($participantData['required'] === true) {
                        // All members required
                        foreach ($members as $member) {
                            if (!$this->hasParticipantVotedUp($member)) {
                                if (!$this->isExtraVote($member, $includeExtraUpVotes, $caseSensitive)) {
                                    $outStanding = true;
                                    break;
                                }
                            }
                        }
                    } else {
                        // Assume a numeric value
                        $upVotes   = 0;
                        $downVotes = 0;
                        foreach ($members as $member) {
                            // For a require 'n' group do not count the author vote even if in $includeExtraUpVotes.
                            // If we were to count it then a require 1 group with the author as part of that group and
                            // mentioned in $includeExtraUpVotes would make the group satisfied which is not what we
                            // want.
                            $isAuthor = $this->isValidAuthor() && $member === $this->getAuthorObject()->getId();
                            if (!$isAuthor) {
                                if ($this->hasParticipantVotedUp($member) ||
                                    $this->isExtraVote($member, $includeExtraUpVotes, $caseSensitive)) {
                                    $upVotes++;
                                }
                                if ($this->hasParticipantVotedDown($member)) {
                                    $downVotes++;
                                }
                            }
                        }
                        // At the moment we support only 1 as a value with any down vote cancelling
                        $outStanding = ($upVotes === 0 || $downVotes > 0);
                    }
                } else {
                    $outStanding = !$this->hasParticipantVotedUp($participant)
                        && !$this->isExtraVote($participant, $includeExtraUpVotes, $caseSensitive);
                }
                if ($outStanding) {
                    return true;
                }
            }
        }
        $outStanding = !$this->hasAnyUpVotes();
        if ($outStanding) {
            $outStanding = empty($includeExtraUpVotes);
        }
        return $outStanding;
    }

    /**
     * Work out if a participant is in the extra up votes taking case into account
     * @param string        $participant            the participant
     * @param array|null    $includeExtraUpVotes    the extra up votes
     * @param bool          $caseSensitive          whether we are case sensitive
     * @return bool true if the participant is in the extra up votes taking case into account
     */
    private function isExtraVote($participant, $includeExtraUpVotes, $caseSensitive)
    {
        if ($caseSensitive) {
            return in_array($participant, $includeExtraUpVotes);
        } else {
            return in_array(
                ArrayHelper::lowerCase($participant),
                ArrayHelper::lowerCase($includeExtraUpVotes)
            );
        }
    }

    /**
     * Has the branch or project met its minimum up votes based on its workflow. If no workflow is set on
     * the branch fall back to the project workflow. If the project workflow is missing then we fall back
     * to the default in the config.
     *
     * @param ConnectionInterface $p4                       p4 connection
     * @param mixed               $includeExtraUpVotes      optional up votes to include when assessing. The votes will
     *                                                      not actually be added to the review but the determination as
     *                                                      to whether there are any outstanding votes will include
     *                                                      these. Should be a list of participant ids that we will
     *                                                      assume have voted up.
     * @param mixed               $impacted                 This is the list of project and branches.
     * @param bool                $workflowsEnabled         whether workflows are enabled. If not enabled we do not need
     *                                                      to get the branch rule for counted votes
     * @param array               $branchRules              Array reference to branch rules already found
     *
     * @return bool
     * @throws NotFoundException
     * @throws P4Exception
     */
    public function hasOutstandingMinVotes(
        Connection $p4,
        $includeExtraUpVotes,
        $impacted,
        bool $workflowsEnabled,
        &$branchRules = []
    ) : bool {
        $caseSensitive = $this->getConnection()->isCaseSensitive();
        // find the author of this review and then remove them from the includeUpVotes
        $author  = [$this->isValidAuthor() ? $this->getAuthorObject()->getId() : $this->get('author')];
        $upVotes = array_keys($this->getUpVotes());
        if (!$caseSensitive) {
            $author              = ArrayHelper::lowerCase($author);
            $includeExtraUpVotes = ArrayHelper::lowerCase($includeExtraUpVotes);
            $upVotes             = ArrayHelper::lowerCase($upVotes);
        }
        $includeExtraUpVotes = array_diff($includeExtraUpVotes, $author);
        $haveVoted           = array_unique(array_merge($upVotes, $includeExtraUpVotes));
        $projectDAO          = ServicesModelTrait::getProjectDao();
        $workflowManager     = ServicesModelTrait::getWorkflowManager();
        foreach ($impacted as $projectId => $branches) {
            try {
                $project = $projectDAO->fetchById($projectId, $p4);
            } catch (RecordNotFoundException $projectError) {
                Logger::log(Logger::TRACE, Review::MINVOTES . "Couldn't fetch project $projectId");
                continue;
            }
            $members = $project->getAllMembers();
            if (!$caseSensitive) {
                $members   = ArrayHelper::lowerCase($members === null ? [] : $members);
                $haveVoted = ArrayHelper::lowerCase($haveVoted === null ? [] : $haveVoted);
            }
            // Get the populated branches once per project so they are not built every time minimum votes are assessed
            $populatedBranches = $project->getBranches();
            // Now go though each of the branches
            foreach ($branches as $branch) {
                $minVotes = $project->getMinimumUpVotes($branch, $populatedBranches);
                $rule     = null;
                if ($workflowsEnabled) {
                    $ruleKey = sprintf("%s:%s", $projectId, $branch);
                    if (isset($branchRules[$ruleKey])) {
                        $rule = $branchRules[$ruleKey];
                    } else {
                        $rule                  = $workflowManager->getBranchRule(
                            IWorkflow::COUNTED_VOTES,
                            [$project->getId() => [$branch]],
                            [$project->getId() => $project]
                        );
                        $branchRules[$ruleKey] = $rule;
                    }
                }
                // Now check if the branch has met its minimum up votes.
                if (!$this->hasReachedMinVotes($minVotes, $rule, $members, $haveVoted)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Function that checks if the branch or project based on data provide has met the minimum up
     * votes.
     *
     * @param int          $minVotes      The minimum up votes needed
     * @param string       $rule          The rule as to whos votes we will count.
     * @param array        $members       The project members.
     * @param array        $haveVoted     The users that have voted already.
     * @return bool
     */
    private function hasReachedMinVotes($minVotes, $rule, $members, $haveVoted)
    {
        $listOfUsers = $rule === null || $rule === IWorkflow::ANYONE
            ? $haveVoted
            : array_intersect($members, $haveVoted);
        // Now compare the project members with the list of user that have voted and return only the members
        // that have a valid up vote.

        if ((int)$minVotes  !== 0 && (int)$minVotes > count($listOfUsers)) {
            return false; // Have not met the minimum votes
        }
        return true; // Have met the minimum votes.
    }

    /**
     * Get only Group participants on this review.
     *
     * @return array    list of groups
     */
    public function getParticipantGroups()
    {
        return array_filter(
            $this->getParticipants(),
            function ($participant) {
                return Group::isGroupName($participant);
            }
        );
    }

    /**
     * Get reviewer groups that this review record is associated with.
     *
     * @return  array   the reviewerGroups set on this record.
     */
    public function getReviewerGroups()
    {
        $raw = (array) $this->getRawValue(self::REVIEWER_GROUPS);
        return array_values(array_unique(array_filter($raw, 'strlen')));
    }

    /**
     * Work out whether a review can become the given state. So far the only actual condition is that:
     *   - In 'each' moderators is configured, a review must have branches approved separately to become approved
     *
     * @param $state          - the review state being verified
     * @param array $options  - options affecting the logic
     *
     * @return bool           - whether this state is appropriate for this revie
     *
     * @throws P4Exception
     */
    public function isStateAllowed($state, $options = [])
    {
        $isAllowed = true;
        switch ($state) {
            case Review::STATE_APPROVED:
            case Review::STATE_APPROVED_COMMIT:
                // Only appropriate when moderator approval mode is 'each'
                if (isset($options[ConfigManager::MODERATOR_APPROVAL]) &&
                    ConfigManager::VALUE_EACH === $options[ConfigManager::MODERATOR_APPROVAL]) {
                    // Work out how many projects/branches still need moderator approval
                    $thisReview          = $this;
                    $p4                  = $this->getConnection();
                    $latestVersionNumber = $this->getHeadVersion();
                    $projectDAO          = ServicesModelTrait::getProjectDao();
                    $isAllowed           = 0 === count(
                        array_filter(
                            array_keys($this->getProjects()),
                            function ($projectId) use ($thisReview, $p4, $latestVersionNumber, $projectDAO) {
                                $project  = $projectDAO->fetch($projectId, $p4);
                                $branches = array_filter(
                                    $project->getBranches(),
                                    function ($branch) use ($thisReview, $project) {
                                        $impacted = $thisReview->getProjects();
                                        return in_array($branch['id'], $impacted[$project->getId()])
                                            ? (count($branch['moderators']) + count($branch['moderators-groups'])) > 0
                                            : false;
                                    }
                                );

                                $need = count($branches);
                                foreach ($branches as $branch) {
                                    foreach ($project->getModerators($branch) as $moderator) {
                                        $moderatorApprovals = $thisReview->getApprovals($moderator);
                                        if ($moderatorApprovals && in_array(
                                            $latestVersionNumber,
                                            $moderatorApprovals,
                                            true
                                        )) {
                                            $need--;
                                            break;
                                        }
                                    }
                                }
                                // No approval for the latest version from any of the moderators of this branch
                                return $need>0;
                            }
                        )
                    );
                }
                break;

            default: // There are no preconditions for this state
                break;
        }
        return $isAllowed;
    }

    /**
     * Work out whether to use append or replace for this review.
     * @param  $append - whether append mode is requested
     * @return boolean - true/false meaning append/replace
     */
    private function isAppendMode($append)
    {
        if (null===$append) {
            // Need to persist the latest mode
            $versions     = $this->getVersions();
            $versionCount = count($versions);
            if (0 !== $versionCount) {
                $lastVersion = $versions[$versionCount-1];
                $append      = isset($lastVersion[Review::ADD_CHANGE_MODE])
                    ? Review::APPEND_MODE === $lastVersion[Review::ADD_CHANGE_MODE] : false;
            }
        }
        return $append;
    }

    /**
     * Get test status
     * @return mixed
     */
    public function getTestStatus()
    {
        return $this->getRawValue(self::FIELD_TEST_STATUS);
    }

    /**
     * Set test status
     * @param string $status    status to set
     * @return KeyRecord
     */
    public function setTestStatus(string $status)
    {
        return $this->setRawValue(self::FIELD_TEST_STATUS, $status);
    }

    /**
     * Get previous test status, defaults to empty string if tests have not previously run
     * @return string
     */
    public function getPreviousTestStatus() : string
    {
        return $this->getRawValue(self::FIELD_PREVIOUS_TEST_STATUS) ?: '';
    }

    /**
     * Set previous test status
     * @param string $status    status to set
     * @return KeyRecord
     */
    public function setPreviousTestStatus(string $status) : KeyRecord
    {
        return $this->setRawValue(self::FIELD_PREVIOUS_TEST_STATUS, $status);
    }

    /**
     * Get the complexity of the review. Complexity is based on files and changes
     * @return array|null null if no complexity has been set, or an array in the form
     * [
     *      'files_modified' => <int>,
     *      'lines_added'    => <int>,
     *      'lines_edited'   => <int>,
     *      'lines_deleted'  => <int>
     * ]
     */
    public function getComplexity()
    {
        return $this->getRawValue(self::FIELD_COMPLEXITY);
    }

    /**
     * Set the complexity for the review. Complexity is based on files and changes and should be an array in the form:
     *
     * [
     *      'files_modified' => <int>,
     *      'lines_added'    => <int>,
     *      'lines_edited'   => <int>,
     *      'lines_deleted'  => <int>
     * ]
     * @param $complexity
     * @return KeyRecord
     */
    public function setComplexity($complexity)
    {
        return $this->setRawValue(self::FIELD_COMPLEXITY, $complexity);
    }

    /**
     * Determine the projects affected by the given change, or the head change for this review if no
     * change is provided, and set/save the review with the currently affected projects and branches.
     *
     * @param Change|Null $change The changelist to check for affected projects
     * @return mixed
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ConfigException
     * @throws P4Exception
     */
    public function refreshProjects($change = null)
    {
        $findAffectedProjects = ServicesModelTrait::getAffectedProjectsService();
        // get the change if not provided.
        $changeDao = self::getChangeDao();
        return $this->setProjects(
            $findAffectedProjects->findByChange(
                $this->getConnection(),
                $change ?? $changeDao->fetch($this->getHeadChange(), $this->getConnection())
            )
        )->save();
    }
}
