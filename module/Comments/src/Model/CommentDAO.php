<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Model;

use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Api\Exception\ConflictException;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\AbstractDAO;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\IpProtects;
use Application\Permissions\RestrictedChanges;
use Comments\Controller\ICommentApi;
use Comments\Filter\ContextAttributes;
use Comments\Validator\Notify;
use Events\Listener\ListenerFactory;
use InvalidArgumentException;
use P4\Connection\ConnectionInterface;
use P4\Model\Fielded\Iterator;
use Queue\Manager;
use Record\Key\AbstractKey;
use Reviews\Model\Review;
use P4\Model\Connected\Iterator as ConnectedIterator;
use Exception;

/**
 * Class CommentDAO to handle access to Perforce comment data
 * @package Changes\Model
 */
class CommentDAO extends AbstractDAO implements IComment
{
    // The Perforce class that handles comments
    const MODEL = Comment::class;

    /**
     * As the fetchAll function in the model takes extra param of protections we have to write
     * this wrapper to handle that.
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by topic.
     *
     * @param array           $options      an optional array of search conditions and/or options
     *                                      supported options are:
     *                                      FETCH_MAXIMUM - set to integer value to limit to the first
     *                                      max' number of entries.
     *                                      FETCH_AFTER - set to an id _after_ which we start collecting
     *                                      FETCH_BY_TOPIC - set to a 'topic' id to limit results
     *                                      FETCH_BY_IDS - provide an array of ids to fetch.
     *                                      not compatible with FETCH_SEARCH or FETCH_AFTER.
     *                                      FETCH_BY_READ_BY - includes comments that have been read by the users
     *                                      FETCH_BY_UNREAD_BY - includes comments that have not been read by the
     *                                      users
     * @param ConnectionInterface|null $connection the connection to set on the filer
     * @param null            $protects            the users protections list.
     * @return  Iterator                           the list of zero or more matching comment objects
     */
    public function fetchAllWithProtection(
        array $options = [],
        ConnectionInterface $connection = null,
        $protects = null
    ): Iterator {
        // By default this simply passes on to model, with extra option of protections.
        $iter = call_user_func(
            static::MODEL  . '::fetchAll',
            $options,
            $this->getConnection($connection),
            $protects
        );
        return $this->decorateComments($iter);
    }

    /**
     * @inheritDoc
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        $iter = call_user_func(static::MODEL  . '::' . __FUNCTION__, $options, $this->getConnection($connection));
        return $this->decorateComments($iter);
    }

    /**
     * Fetch all the comments for a given Review Id.
     * @param mixed $reviewId the review ID
     * @param mixed $options fetch options
     * @return Iterator
     * @throws ForbiddenException
     */
    public function fetchByReview($reviewId, $options = []) : Iterator
    {
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $topic      = self::TOPIC_REVIEWS.'/'.$reviewId;
        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);
        // To check access.
        $canAccess = $this->checkRestrictedAccess($topic);
        if ($canAccess) {
            $options += [
                IComment::TOPIC => [$topic],
                AbstractKey::FETCH_TOTAL_COUNT => true
            ];
        }
        return $this->fetchAllWithProtection($options, $p4Admin, $ipProtects);
    }

    /**
     * Retrieves the open/closed comment counts stored by p4 index. This can
     * be lower than the actual comment counts due to the potential for race
     * conditions when saving, but should generally be correct.
     *
     * @param  string|array          $topics     one or more topics to count open/closed comments in.
     * @param  ConnectionInterface   $p4         the perforce connection to use
     * @return array        a single array with open/closed count if a string topic is given,
     *                      otherwise an array of open/closed count arrays keyed by topic.
     */
    public function countByTopic($topics, ConnectionInterface $p4): array
    {
        $counts = [];
        $topics = array_filter((array)$topics, 'strlen');
        if ($topics) {
            // encode the topics for searching.
            $counts = array_fill_keys($topics, [0, 0]);
            foreach ($topics as $key => $topic) {
                $topics[$key] = Comment::encodeIndexValue($topic);
            }
            $query  = Comment::COUNT_INDEX . '=' . implode('|' . Comment::COUNT_INDEX . '=', $topics);
            $result = $p4->run('search', $query)->getData();
            // search should return one (possibly more) results for each topic
            // results take the form of 'encodedTopic-openedCount-closedCount'
            // take the highest total count we find for each topic.
            foreach ($result as $count) {
                if (!strpos($count, '-')) {
                    continue;
                }
                // pre 2015.2 counts did not include closed comments, so default that to 0
                $parts  = explode('-', $count);
                $topic  = Comment::decodeIndexValue($parts[0]);
                $opened = (int)$parts[1];
                $closed = isset($parts[2]) ? (int)$parts[2] : 0;

                if (isset($counts[$topic]) && array_sum($counts[$topic]) < $opened + $closed) {
                    $counts[$topic] = [$opened, $closed];
                }
            }
        }
        return $counts;
    }

    /**
     * fetch comments data according topic and topic id
     * @param string $topic topic string (changes, reviews, jobs)
     * @param string $id topic id
     * @param array $options options to filter result
     * @throws ForbiddenException
     */
    public function fetchByTopic(string $topic, string $id, array $options = [])
    {
        $comments = "";
        switch ($topic) {
            case IComment::TOPIC_REVIEWS:
                $comments = $this->fetchByReview($id,  $options);
                break;
            case IComment::TOPIC_CHANGES:
                $comments = $this->fetchByChange($id, $options);
                break;
            case IComment::TOPIC_JOBS:
                $comments = $this->fetchByJob($id, $options);
                break;
        }
        return $comments;
    }

    /**
     * Fetch all the comments for a given Change Id.
     * @param mixed $changeId the change ID
     * @param array $options fetch options
     * @return Iterator
     * @throws ForbiddenException
     */
    public function fetchByChange($changeId, array $options = []) : Iterator
    {
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $topic      = IComment::TOPIC_CHANGES.'/'.$changeId;
        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);
        // To check access.
        $canAccess = $this->checkRestrictedAccess($topic);
        if ($canAccess) {
            $options += [
                IComment::TOPIC => [$topic],
                AbstractKey::FETCH_TOTAL_COUNT => true
            ];
        }
        return $this->fetchAllWithProtection($options, $p4Admin, $ipProtects);
    }

    /**
     * Fetch all the comments for a given Job Id.
     * @param mixed $jobId          the job ID
     * @param mixed $options        fetch options
     * @return Iterator
     */
    public function fetchByJob($jobId, $options = []) : Iterator
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $jobDao  = $this->services->get(IDao::JOB_DAO);
        // Fetch the job here to make sure the id is valid, and exception is thrown if not
        $jobDao->fetchById($jobId, $p4Admin);
        $topic      = IComment::TOPIC_JOBS.'/'.$jobId;
        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);
        $options   += [
            IComment::TOPIC => [$topic],
            AbstractKey::FETCH_TOTAL_COUNT => true
        ];
        return $this->fetchAllWithProtection($options, $p4Admin, $ipProtects);
    }

    /**
     * @inheritDoc
     * @throws ForbiddenException
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        $comment = parent::fetchById($id, $connection);
        // To check access.
        $this->checkRestrictedAccess($comment->get(IComment::TOPIC));
        return $this->decorateComment($comment);
    }

    /**
     * check whether user has access to review or change
     * @throws ForbiddenException
     */
    protected function checkRestrictedAccess($topicString): bool
    {
        if (preg_match(
            "#(".IComment::TOPIC_CHANGES."|".IComment::TOPIC_REVIEWS.")/([0-9a-zA-z]+)#",
            $topicString,
            $matches
        )
        ) {
            $topic      = $matches[1];
            $id         = $matches[2];
            $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            // To check access.
            if ($topic === IComment::TOPIC_REVIEWS) {
                $reviewDAO = $this->services->get(IModelDAO::REVIEW_DAO);
                $reviewDAO->fetch($id, $p4Admin);
            } else {
                $changeDAO = $this->services->get(IModelDAO::CHANGE_DAO);
                $change    = $changeDAO->fetchById($id, $p4Admin);
                $change    = ((int) $id === (int) $change->getOriginalId()) ? $change->getId() : false;
                if ($change === false || !$this->services->get(RestrictedChanges::class)->canAccess($change)) {
                    throw new ForbiddenException(
                        $translator->t(ucfirst($topic)." does not exist, or you do not have permission to view it.")
                    );
                }
            }
        }
        return true;
    }

    /**
     * Validate and save the comment. This function can be called to save the comment when it is already determined
     * that the topic is not restricted. Restriction is currently checked on fetch so 'edit' can save the comment
     * without running the restriction check again
     * Validation currently done:
     *  - Validate the parent comment
     *  - Validate the topic is valid.
     *     - If review topic also validate version exist and set.
     *
     * @param mixed     $model  The comment model.
     * @param string    $notify If we should send comment immediately, delayed or silent.
     * @return Comment the saved comment
     * @throws ForbiddenException
     */
    private function saveComment($model, string $notify = Notify::DELAYED) : Comment
    {
        // Check if we are a threaded comment.
        $context = $model->getContext();
        if (isset($context[IComment::COMMENT])) {
            $this->validateParentComment($model, $context[IComment::COMMENT]);
        }
        $silent  = $notify === Notify::SILENT;
        $delayed = $notify === Notify::DELAYED;
        // First get the original comment before we do any more setting or saving.
        $originalComment = $model->getOriginal();
        // Validated that the Topic ID is valid. Throw InvalidArgumentException if not correct
        $this->validatedTopic($model);
        $user = $this->services->get(self::USER);
        // for content edits, handle delayed notifications
        // this means we delay email notifications when instructed to do so
        // and collect delayed comments for sending when ending a batch
        $sendComments = null;
        // This is for edited comments.
        $isContentEdit = $originalComment && $model->hasBodyChanged();
        if (!$silent && $isContentEdit) {
            $sendComments = $this->handleDelayedComments($model, $delayed);
        }
        $comment = parent::save($model);
        // this is for new comments that might have delayed. As we require the comment to exist to get id and time.
        if (!$silent && $sendComments === null) {
            $sendComments = $this->handleDelayedComments($model, $delayed);
        }
        $this->createCommentTask($model, $user, $originalComment, $sendComments, $delayed, $silent);
        return $comment;
    }

    /**
     * Validate and save the comment
     * Validation currently done:
     *  - Validate the parent comment
     *  - Validate that the topic is not restricted
     *  - Validate the topic is valid.
     *     - If review topic also validate version exist and set.
     *
     * @param Comment $model  The comment model.
     * @param string  $notify If we should send comment immediately, delayed or silent.
     * @return Comment the saved comment
     * @throws ForbiddenException
     */
    public function save($model, string $notify = Notify::DELAYED) : Comment
    {
        $this->checkRestrictedAccess($model->get(self::TOPIC));
        return $this->saveComment($model, $notify);
    }

    /**
     * Validate that the Review Version is set,
     *  - If is set verify its valid
     *  - If not set get the head version.
     *
     * @param Review  $review  The Review model.
     * @param Comment $comment The Comment model.
     */
    protected function validateReviewVersion(Review $review, Comment $comment)
    {
        $context = $comment->getContext();
        // If there is no context->version get latest version and apply to context
        if (!isset($context[ContextAttributes::REVIEW_VERSION])) {
            $versions                                   = $review->getVersions();
            $head                                       = count($versions);
            $context[ContextAttributes::REVIEW_VERSION] = $head;
            $comment->setContext($context);
        } else {
            // verify the version exist. and if not return InvalidArgumentException
            $review->getVersion($context[ContextAttributes::REVIEW_VERSION]);
        }
    }

    /**
     * We need to validate that if we are a threaded comment and that the parent exist.
     *  - If topic for this comment doesn't exist copy the parent topic.
     *  - Check if parent comment exist.
     *
     * @param Comment $model     The comment model.
     * @param int     $parentId  The parent comment ID.
     * @throws ForbiddenException
     */
    protected function validateParentComment(Comment $model, int $parentId)
    {
        $p4admin       = $this->services->get(ConnectionFactory::P4_ADMIN);
        $parentComment = $this->fetch($parentId, $p4admin);
        $topic         = $model->get(IComment::TOPIC);
        if (!$topic) {
            $parentTopic = $parentComment->get(IComment::TOPIC);
            $model->set(IComment::TOPIC, $parentTopic);
        }
    }

    /**
     * A function to create comment tasks.
     *
     * @param Comment $model           The comment model
     * @param mixed   $user            The user connection.
     * @param mixed   $originalComment The original comment data.
     * @param mixed   $sendComments    If we should send comments or not.
     * @param bool    $delayed         If the comment should be delayed.
     * @param bool    $silent          If the comment is silenced.
     */
    protected function createCommentTask(
        Comment $model,
        $user,
        $originalComment,
        $sendComments,
        bool $delayed,
        bool $silent
    ) {
        // push comment update into queue for further processing
        $queue = $this->services->get(Manager::SERVICE);
        $queue->addTask(
            self::COMMENT,
            $model->getId(),
            [
                self::USER     => $user->getId(),
                self::PREVIOUS => $originalComment,
                self::CURRENT  => $model->get(),
                // 'quiet' is a little odd it may be set to true to try and silence everything,
                // or an array with for example the words 'mail' or 'activity' to
                // try and silence a particular action. We want to silence the mail if it
                // is being delayed; the activity still must be created so we do not want quiet
                // to be set to true
                ICommentApi::QUIET => $delayed ? ['mail'] : null,
                ICommentApi::SEND => $sendComments,
                ICommentApi::SILENCE_NOTIFICATION_PARAM => $silent
            ]
        );
    }

    /**
     * Validate that the topic is real and can be fetched by the user.
     *
     * @param Comment $model  The comment model.
     * @return string
     */
    protected function validatedTopic(Comment $model): string
    {
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $rawTopic = $model->get(self::TOPIC);
        if (strpos($rawTopic, self::TOPIC_REVIEWS . '/') === 0) {
            $reviewID  = explode('/', $rawTopic)[1];
            $reviewDAO = $this->services->get(IModelDAO::REVIEW_DAO);
            $review    = $reviewDAO->fetch($reviewID, $p4Admin);
            // As we know we are a review, validated the context -> version for review.
            $this->validateReviewVersion($review, $model);
            return self::TOPIC_REVIEWS;
        } elseif (strpos($rawTopic, self::TOPIC_CHANGES . '/') === 0) {
            $changeID  = explode('/', $rawTopic)[1];
            $changeDAO = $this->services->get(IModelDAO::CHANGE_DAO);
            $changeDAO->fetch($changeID, $p4Admin);
            return self::TOPIC_CHANGES;
        } elseif (strpos($rawTopic, self::TOPIC_JOBS . '/') === 0) {
            $jobID  = explode('/', $rawTopic)[1];
            $jobDAO = $this->services->get(IModelDAO::JOB_DAO);
            $jobDAO->fetch($jobID, $p4Admin);
            return self::TOPIC_JOBS;
        }
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        throw new InvalidArgumentException(
            $translator->t("Please provide a valid Comment topic 'changes, reviews or jobs'.")
        );
    }

    /**
     * Delay notification for the given comment or collect delayed
     * comments and close the batch if we are sending (delay is false).
     *
     * @param   Comment  $comment    comment to process
     * @param   bool     $delay      delay this comment, false to close the batch
     * @return  array|null  delayed comment data if sending, null otherwise
     */
    protected function handleDelayedComments(Comment $comment, bool $delay): ?array
    {
        $topic           = $comment->get(self::TOPIC);
        $userConfig      = $this->services->get(self::USER)->getConfig();
        $delayedComments = $userConfig->getDelayedComments($topic);

        // nothing to do if we are sending but there are no delayed comments
        if (!$delay && !count($delayedComments)) {
            return null;
        }

        // if not already present, add the comment to delayed comments; in the case of an add,
        // the comment batch time should match the time of the first comment - this should avoid
        // later concluding that the comment was created before the batch. Or if the comment is
        // edited then we should update the time in the delayedComments.
        if (!array_key_exists($comment->getId(), $delayedComments) || $comment->get(self::EDITED)) {
            $delayedComments[$comment->getId()] = $comment->get(self::EDITED)
                ? time()
                : $comment->get(self::TIME);
        }

        //make sure that the comment ending the batch has the 'batched' flag set to true
        $comment->set(self::BATCHED, true);

        $userConfig->setDelayedComments($topic, $delay ? $delayedComments : null)->save();
        return $delay ? null : $delayedComments;
    }

    /**
     * Edit fields of a comment specified by id, this is currently limited to body and taskState
     * When a field is updated, the 'readBy' field is reset to an empty array (from save function)
     * Editing the body requires the editor to be the author of the comment
     * Editing the body also updates the 'edited' field of the comment to the current time
     *
     * @param   string                  $id         The id of the comment
     * @param   array                   $data       Fields  of the commit that should be edited (body or taskState)
     * @return  AbstractKey             If no exceptions are thrown, returns the comment that has been edited
     * @throws  ForbiddenException
     */
    public function edit(string $id, array $data): AbstractKey
    {
        $required     = [IComment::BODY, IComment::TASK_STATE];
        $requiredData = array_intersect_key($data, array_flip($required));
        if (count($requiredData) === 0) {
            throw new InvalidArgumentException(ICommentApi::INVALID_PARAMETERS);
        }

        $p4      = $this->services->get(ConnectionFactory::P4);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $comment = $this->fetch($id, $p4Admin);

        $user   = $p4->getUser();
        $author = $comment->get(IComment::USER);

        if ($user !== $author && isset($data[IComment::BODY])) {
            throw new ForbiddenException(ICommentApi::INVALID_PERMISSION);
        }

        if (isset($data[IComment::BODY])) {
            $comment->set(IComment::BODY, $data[IComment::BODY]);
            $comment->set(IComment::EDITED, time());
        }

        if (isset($data[IComment::TASK_STATE])) {
            $comment->set(IComment::TASK_STATE, $data[IComment::TASK_STATE]);
        }

        $notify = $data[Notify::NOTIFY_FIELD] ?? Notify::DELAYED;

        $this->saveComment($comment, $notify);

        return $comment;
    }

    /**
     * Send delayed comment for a given topic.
     *
     * @throws ForbiddenException
     */
    public function sendDelayedComments($topic): int
    {
        $logger  = $this->services->get(SwarmLogger::SERVICE);
        $queue   = $this->services->get(Manager::SERVICE);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $p4User  = $this->services->get(ConnectionFactory::P4_USER);

        // check early invalid review or user permission to access topic
        $this->checkRestrictedAccess($topic);

        // Now get the users' config.
        $userConfig      = $this->services->get(self::USER)->getConfig();
        $delayedComments = $userConfig->getDelayedComments($topic);
        // Now we have the user config we can see how many comments this user has delayed for this given topic.
        $totalComments = count($delayedComments);

        // If there are delayed comments for the given topic than send them.
        if ($totalComments > 0) {
            $logger->trace(
                sprintf(
                    "%s: User %s has %s delayed comments, sending them",
                    self::MODEL,
                    $p4User->getUser(),
                    $totalComments
                )
            );
            // Set the array pointer to the last comment and get that comment data.
            end($delayedComments);
            $commentID = key($delayedComments);
            $comment   = $this->fetchById($commentID, $p4Admin);
            // Now save the comment batched field to false.
            $comment->set(IComment::BATCHED, false);
            // We need to save the model but don't want to send a notification as we are able
            // to send full batch of comments.
            parent::save($comment);
            $logger->trace(
                sprintf(
                    "%s: Set comment with id %s batched status to false",
                    self::MODEL,
                    $commentID
                )
            );
            // Now remove the delayed comments record for this user.
            $userConfig->setDelayedComments($topic, null)->save();
            $logger->trace(
                sprintf(
                    "%s: Removed delayed comments for user %s",
                    self::MODEL,
                    $p4User->getUser()
                )
            );

            // Pass the processing back to the batch comments queue.
            $queue->addTask(
                ListenerFactory::COMMENT_BATCH,
                $topic,
                [ICommentApi::SEND => $delayedComments]
            );
            $logger->debug(
                sprintf(
                    "%s: Created the batched queued task for user %s, sending a total of %s comments",
                    self::MODEL,
                    $p4User->getUser(),
                    $totalComments
                )
            );
        } else {
            $logger->debug(
                sprintf(
                    "%s: No comments delayed for topic %s",
                    self::MODEL,
                    $topic
                )
            );
        }

        return $totalComments;
    }

    /**
     * When we want to mark a comment as unread.
     *
     * @param string $id          The id of the comment.
     * @return Comment
     * @throws ForbiddenException
     */
    public function markCommentAsUnread(string $id): Comment
    {
        $p4User  = $this->services->get(ConnectionFactory::P4_USER);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $comment = $this->fetchById($id, $p4Admin);
        $comment->removeReadBy($p4User->getUser());
        return parent::save($comment);
    }

    /**
     * When we want to mark a comment as read.
     *
     * @param string    $id         The id of the comment.
     * @param array     $options    options for marking as read. Supports an 'edited' field that if present
     *                              will be used to validate whether the marking is valid. The 'edited' time passed in
     *                              options must be later than any edited/created date on the comment.
     * @return Comment
     * @throws ForbiddenException
     * @throws ConflictException if the comment has changed since the request to mark it as read
     */
    public function markCommentAsRead(string $id, array $options = []): Comment
    {
        $options += [
            IComment::EDITED => null
        ];

        $p4User  = $this->services->get(ConnectionFactory::P4_USER);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $comment = $this->fetchById($id, $p4Admin);
        if ($options[IComment::EDITED]) {
            $compareTo = $comment->get(IComment::EDITED)
                ? $comment->get(IComment::EDITED)
                : $comment->get(IComment::TIME);
            if ($compareTo > $options[IComment::EDITED]) {
                throw new ConflictException(
                    $this->services->get(TranslatorFactory::SERVICE)->t("Comment has been updated"),
                    $comment
                );
            }
        }
        $comment->addReadBy($p4User->getUser());
        return parent::save($comment);
    }

    /**
     * Decorate a comment with extra data not stored in the model
     * @param Comment $comment the model
     * @return AbstractKey the comment
     */
    private function decorateComment(Comment $comment) : AbstractKey
    {
        $context = $comment->getContext();
        // If context and file are present add in extra attributes line, name, and md5
        if ($context && isset($context[ContextAttributes::FILE_PATH])) {
            $comment->setContext($comment->getFileContext());
        }
        return $comment;
    }

    /**
     * Decorate comments with extra data not stored in the model
     * @param Iterator $iterator comments iterator
     * @return Iterator comments iterator
     */
    private function decorateComments(Iterator $iterator) : Iterator
    {
        foreach ($iterator as $comment) {
            $this->decorateComment($comment);
        }
        return $iterator;
    }

    /**
     * Archive all comments starting with the comment with the given id. This comment and all of its nested
     * replies will be archived
     * @param mixed         $id     the starting comment
     * @return Iterator iterator of comments that were archived
     * @throws Exception
     */
    public function archiveComment($id) : Iterator
    {
        $comments = $this->fetchCommentAndReplies($id);
        foreach ($comments as $comment) {
            $comment->addFlags(IComment::FLAG_CLOSED);
            $context = $comment->getContext();
            if (isset($context['comment'])) {
                // Call the parent save on replies as we do not need to process activity etc
                parent::save($comment);
            } else {
                $this->saveComment($comment);
            }
        }
        return $comments;
    }

    /**
     * UnArchive all comments starting with the comment with the given id. This comment and all of its nested
     * replies will be unarchived
     * @param mixed         $id     the starting comment
     * @return Iterator iterator of comments that were unarchived
     * @throws Exception
     */
    public function unArchiveComment($id) : Iterator
    {
        $comments = $this->fetchCommentAndReplies($id);
        foreach ($comments as $comment) {
            $comment->removeFlags([IComment::FLAG_CLOSED]);
            $context = $comment->getContext();
            if (isset($context['comment'])) {
                // Call the parent save on replies as we do not need to process activity etc
                parent::save($comment);
            } else {
                $this->saveComment($comment);
            }
        }
        return $comments;
    }

    /**
     * Fetch the comment with the given id along with all replies that are associated with it. Traverses all
     * the nested replies.
     * @param mixed                     $id         the id of the comment to fetch. This comment and any of its nested
     *                                              replies will be returned
     * @return Iterator iterator of matching comments
     * @throws Exception
     */
    public function fetchCommentAndReplies($id) : Iterator
    {
        $logger        = $this->services->get(SwarmLogger::class);
        $connection    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $comment       = $this->fetch($id, $connection);
        $topicComments =
            $this->fetchAll([IComment::FETCH_BY_TOPIC => $comment->get(self::TOPIC)], $connection);
        // Filter out comments that do not have a comment parent set
        $topicComments->filterByCallback(
            function ($comment) use ($id) {
                $context = $comment->getContext();
                return $context && isset($context[self::COMMENT]);
            }
        );
        // We always return the comment for the id we have been asked for
        $comments = [$comment];
        $recurse  = function ($id, $connection) use (&$recurse, &$comments, $topicComments, $logger) {
            $logger->debug(sprintf("%s: Fetching comments and replies for comment id %s", self::MODEL, $id));
            // Look through all the comments for replies matching the id taking a copy so as not to affect the
            // topic comments
            $replies = $topicComments->filterByCallback(
                function ($item) use ($id) {
                    $context = $item->getContext();
                    return $context
                        && isset($context[self::COMMENT])
                        && (string)$context[self::COMMENT] === (string)$id;
                },
                null,
                [ConnectedIterator::FILTER_COPY]
            );

            $repliesCount = $replies->count();
            $logger->debug(sprintf("%s: Found %d replies for parent id %s", self::MODEL, $repliesCount, $id));
            if ($repliesCount) {
                foreach ($replies as $commentReply) {
                    $comments[] = $commentReply;
                    $recurse($commentReply->getId(), $connection);
                }
            }
        };
        $recurse($id, $connection);
        $logger->debug(sprintf("%s: Found %d comments in total when fetching replies", self::MODEL, sizeof($comments)));
        return new Iterator($comments);
    }
}
