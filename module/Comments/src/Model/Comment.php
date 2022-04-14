<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Model;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\I18n\TranslatorFactory;
use Application\Model\ServicesModelTrait;
use Application\Permissions\Protections;
use Application\Permissions\ConfigCheck;
use Attachments\Model\Attachment;
use Comments\Filter\ContextAttributes;
use Comments\Validator\TaskState;
use Files\View\Helper\DecodeSpec;
use P4\Connection\ConnectionInterface as Connection;
use P4\Model\Fielded\Iterator;
use P4\Model\Fielded\Iterator as ModelIterator;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Exception\Exception;
use Record\Key\AbstractKey as KeyRecord;
use Reviews\Model\Review;
use P4\Spec\Change;
use Users\Model\User;

/**
 * Provides persistent storage and indexing of comments.
 */
class Comment extends KeyRecord implements IComment
{
    use ServicesModelTrait;
    const KEY_PREFIX = 'swarm-comment-';
    const KEY_COUNT  = 'swarm-comment:count';

    const COUNT_INDEX = 1101;

    protected $userObject = null;
    protected $fields     = [
        self::TOPIC     => [               // object being commented on (e.g. changes/1)
                                       'index'     => 1102             // note we index by topic for later retrieval
        ],
        self::CONTEXT   => [               // specific context e.g.:
                                       'accessor'  => 'getContext',// {file: '//depot/foo', leftLine: 85,
                                                                   // rightLine: null}
                                       'mutator'   => 'setContext'
        ],
        self::ATTACHMENTS => [             // optional file attachments
                                       'accessor' => 'getAttachments',
                                       'mutator'  => 'setAttachments'
        ],
        self::FLAGS     => [               // list of flags
                                       'accessor'  => 'getFlags',
                                       'mutator'   => 'setFlags'
        ],
        self::TASK_STATE     => [
            'accessor' => 'getTaskState',
            'mutator'  => 'setTaskState'
        ],
        self::LIKES     => [               // list of users who like this comment
                                       'accessor' => 'getLikes',
                                       'mutator'  => 'setLikes'
        ],
        self::USER,                             // user making the comment (e.g. 'jdoe')
        self::TIME,                             // timestamp when the comment was created
        self::UPDATED,                          // timestamp when the comment was last updated
        self::EDITED,                           // timestamp when the comment body or attachments were last edited
        self::BODY,                             // the actual message
        self::BATCHED => [                // true if a comment has been created as a part of a batch
                                      'hidden'   => true,
        ],
        self::READ_BY  => [
            'accessor' => 'getReadBy',
            'mutator'  => 'setReadBy'
        ]
    ];

    public function getContext() : array
    {
        return (array) $this->getRawValue(self::CONTEXT);
    }

    /**
     * Gets whether this comment is on a description
     * @return bool true if a comment on a description
     */
    public function isDescriptionComment() : bool
    {
        return $this->hasAttribute(ContextAttributes::DESCRIPTION_CONTEXT);
    }

    /**
     * Gets whether the context contains the value in the 'attribute'
     * @param mixed $attribute the attribute
     * @return bool true if the context has the attribute
     */
    public function hasAttribute($attribute) : bool
    {
        $context = $this->getContext();
        return isset($context[ContextAttributes::ATTRIBUTE]) && $context[ContextAttributes::ATTRIBUTE] === $attribute;
    }

    public function setContext(array $context = null) : KeyRecord
    {
        return $this->setRawValue(self::CONTEXT, $context);
    }

    /**
     * Get the list of attachments to this comment.
     *
     * @return array    An array of attachment IDs
     */
    public function getAttachments() : array
    {
        return $this->normalizeAttachments($this->getRawValue(self::ATTACHMENTS));
    }

    /**
     * Store attachment IDs.
     *
     * @param   mixed   $attachments    Store the list of attachments on this comment
     * @return  KeyRecord               to maintain a fluent interface
     */
    public function setAttachments($attachments) : KeyRecord
    {
        return $this->setRawValue(self::ATTACHMENTS, $this->normalizeAttachments($attachments));
    }

    protected function normalizeAttachments($attachments) : array
    {
        $attachments = (array) $attachments;
        foreach ($attachments as $key => $value) {
            if (!ctype_digit((string)$value)) {
                unset($attachments[$key]);
                continue;
            }

            $attachments[$key] = (int)$value;
        }

        return array_values($attachments ?: []);
    }

    /**
     * Returns an array of flags set on this comment.
     *
     * @return  array   flag names for all flags current set on this comment
     */
    public function getFlags() : array
    {
        return (array) $this->getRawValue(self::FLAGS);
    }

    /**
     * Set an array of active flag names on this comment.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  Comment     to maintain a fluent interface
     */
    public function setFlags(array $flags = null) : KeyRecord
    {
        // only grab unique flags, and reset the index
        $flags = array_values(array_unique((array) $flags));

        return $this->setRawValue(self::FLAGS, $flags);
    }

    /**
     * Adds the flags names in the passed array to the existing flags on this comment
     *
     * @param   array|string|null  $flags  an array of flags to add, an individual flag string or null
     * @return  Comment            to maintain a fluent interface
     */
    public function addFlags($flags = null)
    {
        $flags = array_merge($this->getFlags(), (array) $flags);
        return $this->setFlags($flags);
    }

    /**
     * Removes the flags names in the passed array from the existing flags on this comment
     *
     * @param   array|null  $flags  an array of flags to remove or null
     * @return  Comment     to maintain a fluent interface
     */
    public function removeFlags(array $flags = null)
    {
        $flags = array_diff($this->getFlags(), (array) $flags);
        return $this->setFlags($flags);
    }

    /**
     * Get list of users who like this comment.
     *
     * @return  array   list of users who like this comment
     */
    public function getLikes() : array
    {
        $likes = (array) $this->getRawValue(self::LIKES);
        return array_values(array_unique(array_filter($likes, 'strlen')));
    }

    /**
     * Set list of users that like this comment.
     *
     * @param   array       $users  list with users that like this comment
     * @return  Comment     to maintain a fluent interface
     */
    public function setLikes(array $users) : KeyRecord
    {
        $users = array_values(array_unique(array_filter($users, 'strlen')));
        return $this->setRawValue(self::LIKES, $users);
    }

    /**
     * Add like for the given user for this comment.
     *
     * @param   mixed       $user   user to add like for
     * @return  Comment     to maintain a fluent interface
     */
    public function addLike($user)
    {
        return $this->setLikes(array_merge($this->getLikes(), [$user]));
    }

    /**
     * Remove like for the given user for this comment.
     *
     * @param   mixed       $user   user to remove like for
     * @return  Comment     to maintain a fluent interface
     */
    public function removeLike($user)
    {
        return $this->setLikes(array_diff($this->getLikes(), [$user]));
    }

    /**
     * Returns the current state of this comment.
     *
     * @return  string  current state of this comment
     */
    public function getTaskState() : string
    {
        return $this->getRawValue(self::TASK_STATE) ?: static::TASK_COMMENT;
    }

    /**
     * Returns a list of state transitions that are allowed from the current state.
     *
     * @return  array       list of allowed state transitions from the current state if $state is false
     * @throws \P4\Exception
     */
    public function getTaskTransitions() : array
    {
        $translator  = $this->getConnection()->getService(TranslatorFactory::SERVICE);
        $transitions = [
            static::TASK_COMMENT         => [
                static::TASK_OPEN                   => $translator->t('Flag as Task ')
            ],
            static::TASK_OPEN            => [
                static::TASK_ADDRESSED              => $translator->t('Task Addressed'),
                static::TASK_COMMENT                => $translator->t('Not a Task')
            ],
            static::TASK_ADDRESSED           => [
                static::TASK_VERIFIED               => $translator->t('Verify Task'),
                static::TASK_VERIFIED_ARCHIVE       => $translator->t('Verify and Archive'),
                static::TASK_OPEN                   => $translator->t('Reopen Task')
            ],
            static::TASK_VERIFIED        => [
                static::TASK_OPEN                   => $translator->t('Reopen Task')
            ]
        ];

        $state       = $this->getTaskState();
        $transitions = $transitions[$state] ?? [];

        // ensure if we're already archived, that transitions involving archiving are removed
        if (in_array(static::FLAG_CLOSED, $this->getFlags())) {
            unset($transitions[static::TASK_VERIFIED_ARCHIVE]);
        }

        return $transitions;
    }

    /**
     * Sets the current state of this comment.
     *
     * @param   mixed       $state  the new state for this comment
     * @return  Comment     to maintain a fluent interface
     * @throws  Exception   if an invalid task state is passed
     */
    public function setTaskState($state) : KeyRecord
    {
        // ensure we're being passed a valid task state
        $states = [
            static::TASK_COMMENT,
            static::TASK_OPEN,
            static::TASK_ADDRESSED,
            static::TASK_VERIFIED,
            static::TASK_VERIFIED_ARCHIVE
        ];

        // a null state is equivalent to the initial comment state
        $state = strlen($state) ? $state : static::TASK_COMMENT;
        if (!in_array($state, $states)) {
            throw new Exception('Invalid task state: ' . $state . '. Valid states: ' . implode(', ', $states));
        }

        // remove the pseudo-flag for archiving a comment, so it just gets set to verified
        if ($state == static::TASK_VERIFIED_ARCHIVE) {
            $state = static::TASK_VERIFIED;
        }

        return $this->setRawValue(self::TASK_STATE, $state);
    }

    /**
     * Retrieves all records that match the passed options.
     * Extends parent to compose a search query when fetching by topic.
     *
     * @param   array               $options       an optional array of search conditions and/or options
     *                                             supported options are:
     *                                             FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                             'max' number of entries.
     *                                             FETCH_AFTER - set to an id _after_ which we start collecting
     *                                             FETCH_BY_TOPIC - set to a 'topic' id to limit results
     *                                             FETCH_BY_IDS - provide an array of ids to fetch.
     *                                                            not compatible with FETCH_SEARCH or FETCH_AFTER.
     *                                             FETCH_BY_READ_BY - includes comments that have been read by the users
     *                                             FETCH_BY UNREAD_BY - includes comments that have not been read by the
     *                                                                  users
     * @param   Connection          $p4            the perforce connection to use
     * @param   Protections|null    $protections   optional - if set, comments associated with files the user cannot
     *                                             read according to given protections will be removed before returning
     * @return  ModelIterator                      the list of zero or more matching activity objects
     * @throws \Exception
     */
    public static function fetchAll(array $options, Connection $p4, Protections $protections = null)
    {
        // normalize options
        $options += [
            static::FETCH_BY_TOPIC        => null,
            static::FETCH_BY_CONTEXT      => null,
            static::FETCH_BY_TASK_STATE   => null,
            static::FETCH_BY_USER         => null,
            static::FETCH_IGNORE_ARCHIVED => null,
            static::FETCH_BY_READ_BY      => null,
            static::FETCH_BY_UNREAD_BY    => null
        ];

        // build a search expression for topic.
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            [self::TOPIC => $options[ static::FETCH_BY_TOPIC]]
        );

        $comments = parent::fetchAll($options, $p4);

        // handle FETCH_BY_TASK_STATE, FETCH_BY_USER and FETCH_IGNORE_ARCHIVED
        $taskStates     = $options[static::FETCH_BY_TASK_STATE];
        $user           = $options[static::FETCH_BY_USER];
        $ignoreArchived = $options[static::FETCH_IGNORE_ARCHIVED];
        $readBy         = $options[static::FETCH_BY_READ_BY];
        $unReadBy       = $options[static::FETCH_BY_UNREAD_BY];
        if ($taskStates || $user || $ignoreArchived || $readBy || $unReadBy) {
            $comments->filterByCallback(
                function (Comment $comment) use ($taskStates, $user, $ignoreArchived, $readBy, $unReadBy) {
                    $retVal = true;
                    if ($taskStates) {
                        $retVal = in_array($comment->getTaskState(), (array) $taskStates);
                    }
                    if ($retVal && $readBy) {
                        $intersect = array_intersect($comment->getReadBy(), (array) $readBy);
                        $retVal    = sizeof($intersect) > 0;
                    }
                    if ($retVal && $unReadBy) {
                        $intersect = array_intersect($comment->getReadBy(), (array) $unReadBy);
                        $retVal    = sizeof($intersect) === 0;
                    }
                    if ($retVal && $user) {
                        $retVal = $comment->getRawValue('user') == $user;
                    }
                    if ($retVal && $ignoreArchived) {
                        $retVal = !in_array(static::FLAG_CLOSED, $comment->getFlags());
                    }
                    return $retVal;
                }
            );
        }

        // filter comments according to given protections (if given)
        if ($protections) {
            $comments->filterByCallback(
                function (Comment $comment) use ($protections) {
                    $context = $comment->getContext();
                    $file    = $context[ContextAttributes::FILE_PATH] ?? null;
                    return !$file || $protections->filterPaths($file, Protections::MODE_READ);
                }
            );
        }
        return $comments;
    }

    /**
     * Advanced filtering on fetch results.
     *
     * This will still fetch all comments for specific topic, but furthermore it will find any with matching
     * file context if any has been set.
     *
     * @param mixed Topic to fetch by
     *
     * @return Iterator previous comment for this topic
     * @throws \Exception
     */
    public static function fetchAdvanced(array $options, Connection $p4) : Iterator
    {
        // normalize options
        $options += [
            static::FETCH_BY_TOPIC        => null,
            static::FETCH_BY_CONTEXT      => [],
            static::FETCH_BY_TASK_STATE   => null,
            static::FETCH_BY_USER         => null,
            static::FETCH_IGNORE_ARCHIVED => null,
            static::FETCH_BATCHED         => null
        ];


        // build a search expression for topic, batch and context.
        $options[static::FETCH_SEARCH] = static::makeSearchExpression(
            [
                self::TOPIC => $options[static::FETCH_BY_TOPIC]
            ]
        );

        $comments = parent::fetchAll($options, $p4);

        // make sure all comments have the same context
        $currentFileContext = $options[static::FETCH_BY_CONTEXT];
        $comments->filterByCallback(
            function (Comment $comment) use ($currentFileContext) {
                // check for the same context
                $commentContext = $comment->getFileContext();
                return isset($commentContext[ContextAttributes::FILE_PATH])
                    == isset($currentFileContext[ContextAttributes::FILE_PATH]);
            }
        );

        if (!empty($options[static::FETCH_BY_CONTEXT])) {
            // get only the comments with context
            $comments->filterByCallback(
                function (Comment $comment) {
                    $context = $comment->getContext();
                    return !empty($context);
                }
            );
            // pass down context to the callback
            $contextOptions = $options[static::FETCH_BY_CONTEXT];

            // filter comments by the content
            $comments->filterByCallback(
                function (Comment $comment) use ($contextOptions) {
                    $context = $comment->getContext();
                    foreach (array_keys($contextOptions) as $key) {
                        if (isset($context[$key]) && $context[$key] == $contextOptions[$key]) {
                            continue;
                        } else {
                            return false;
                        }
                    }
                    return true;
                }
            );
        }

        return $comments;
    }

    /**
     * Gets whether we should limit mentions. Firstly mentions are only limited if
     * the mentions mode is 'projects'. For reviews/changes we also only want to limit
     * if the review or change is associated with one or more project, otherwise we
     * want to display all users as candidates for mentions.
     * @param mixed         $topic      topic with id string - for example 'reviews/123'
     * @param mixed         $config     config settings
     * @param Connection    $p4
     * @return bool
     * @throws SpecNotFoundException
     * @throws \P4\Exception
     * @throws RecordNotFoundException
     */
    public static function shouldLimitMentions($topic, $config, Connection $p4) : bool
    {
        $limit = $config['mentions']['mode'] == 'projects';
        if (strpos($topic, self::TOPIC_REVIEWS) === 0) {
            $reviewID = explode('/', $topic);
            $review   = Review::fetch(end($reviewID), $p4);
            if (!$review->getProjects() || sizeof($review->getProjects()) == 0) {
                $limit = false;
            }
        } elseif (strpos($topic, self::TOPIC_CHANGES) === 0) {
            $changeID = explode('/', $topic);
            Change::fetchById(end($changeID), $p4);
            $reviews       = Review::fetchAll([Review::FETCH_BY_CHANGE => $changeID], $p4);
            $foundProjects = false;
            foreach ($reviews as $review) {
                if ($review->getProjects() && sizeof($review->getProjects()) > 0) {
                    $foundProjects = true;
                    break;
                }
            }
            $limit = $limit && $foundProjects;
        } elseif (strpos($topic, self::TOPIC_JOBS) === 0) {
            // Never limit for jobs
            $limit = false;
        }
        return $limit;
    }

    /**
     * Return the list of possible mentions for the comment parent review project.
     * @param mixed         $topic      topic with id string - for example 'reviews/123'
     * @param mixed         $config     config settings
     * @param Connection    $p4
     * @return array the mentions
     * @throws SpecNotFoundException
     * @throws \P4\Exception
     * @throws RecordNotFoundException|ConfigException
     */
    public static function getPossibleMentions($topic, $config, Connection $p4) : array
    {
        if (!self::shouldLimitMentions($topic, $config, $p4)) {
            return [];
        }

        // add only users and group if topic is provided and review is a part of a project
        $mentions = ['users' => [], 'groups' => []];

        // check config options for blacklists
        $usersBlacklist  = isset($config['mentions'][ConfigManager::USERS_EXCLUDE_LIST])
            ? ConfigManager::getValue($config, ConfigManager::MENTIONS_USERS_EXCLUDE_LIST)
            : [];
        $groupsBlacklist = isset($config['mentions'][ConfigManager::GROUPS_EXCLUDE_LIST])
            ? ConfigManager::getValue($config, ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST)
            : [];


        if (strpos($topic, self::TOPIC_REVIEWS) === 0) {
            self::getPossibleMentionsForReview(
                $mentions,
                $usersBlacklist,
                $groupsBlacklist,
                $topic,
                $p4
            );
        } elseif (strpos($topic, self::TOPIC_CHANGES) === 0) {
            $changeID = explode('/', $topic);
            $reviews  = Review::fetchAll([Review::FETCH_BY_CHANGE => $changeID], $p4);
            foreach ($reviews as $review) {
                self::getPossibleMentionsForReview(
                    $mentions,
                    $usersBlacklist,
                    $groupsBlacklist,
                    self::TOPIC_REVIEWS . '/' . $review->getId(),
                    $p4
                );
            }
        }
        // and now remove duplicates
        $mentions['users']  = array_unique($mentions['users'], SORT_REGULAR);
        $mentions['groups'] = array_unique($mentions['groups'], SORT_REGULAR);
        return $mentions;
    }

    /**
     * Get the mentions for a review and add to any mentions.
     *
     * @param $mentions
     * @param $usersBlacklist
     * @param $groupsBlacklist
     * @param $topic
     * @param Connection $p4

     * @throws RecordNotFoundException
     */
    private static function getPossibleMentionsForReview(
        &$mentions,
        $usersBlacklist,
        $groupsBlacklist,
        $topic,
        Connection $p4
    ) {
        $userDao            = self::getUserDao();
        $reviewID           = explode('/', $topic);
        $review             = Review::fetch(end($reviewID), $p4);
        $reviewParticipants = $review->getParticipants();
        $caseSensitive      = $p4->isCaseSensitive();

        // add user participants - except current user
        foreach ($reviewParticipants as $reviewer) {
            try {
                $reviewUser = $userDao->fetchById($reviewer, $p4);
            } catch (\Exception $e) {
                continue;
            }

            if (ConfigCheck::isExcluded($reviewer, $usersBlacklist, $caseSensitive)) {
                continue;
            }
            $mentions['users'][] = ['User' => $reviewer, 'FullName' => $reviewUser->getFullName()];
        }
        $projectDAO = self::getProjectDao();
        $projects   = $review->getProjects();
        // add all project members for all projects
        foreach ($projects as $project => $branches) {
            $project = $projectDAO->fetch($project, $p4);
            $users   = $project->getAllMembers();
            $groups  = $project->getSubgroups();

            foreach ($users as $userId) {
                try {
                    $projectUser = $userDao->fetchById($userId, $p4);
                } catch (\Exception $e) {
                    continue;
                }
                if (ConfigCheck::isExcluded($userId, $usersBlacklist, $caseSensitive)) {
                    continue;
                }
                $mentions['users'][] = ['User' => $userId, 'FullName' => $projectUser->getFullName()];
            }
            foreach ($groups as $group) {
                if (ConfigCheck::isExcluded($group, $groupsBlacklist, $caseSensitive)) {
                    continue;
                }
                $mentions['groups'][] = ["Group" => $group];
            }
        }
    }

    /**
     * Given a full comment context, return an array of minimal keys that have to match in order for the comment
     * to find it's predecessor
     *
     * @param mixed context comment context that we are trying to find previous for
     * @return array array of options to match against
     */
    public function createMinimalMatchingContext($context) : array
    {
        if (isset($context[ContextAttributes::FILE_PATH])) {
            // we are dealing with a context for a file comment
            $options = [
                ContextAttributes::FILE_PATH => $context[ContextAttributes::FILE_PATH],
                ContextAttributes::REVIEW_VERSION => $context[ContextAttributes::REVIEW_VERSION]
            ];
            if (isset($context[ContextAttributes::RIGHT_LINE])) {
                $options[ContextAttributes::RIGHT_LINE] = $context[ContextAttributes::RIGHT_LINE];
            }
            if (isset($context[ContextAttributes::LEFT_LINE])) {
                $options[ContextAttributes::LEFT_LINE] = $context[ContextAttributes::LEFT_LINE];
            }
            return $options;
        } else {
            // this is a normal comment with a topic
            // it should not have file in it's context
            return [];
        }
    }

    /**
     * Create appropriate message ID for this comment.
     * This should follow the pattern of:
     *  comment-([file]-[md5]-(line-[lineNumber]))-ID
     */
    public function createMessageId() : string
    {
        $messageID = self::COMMENT;
        $context   = $this->getFileContext();
        if (isset($context[ContextAttributes::FILE_PATH])) {
            $pattern   = "%s-%s-%s";
            $messageID = sprintf(
                $pattern,
                $messageID,
                ContextAttributes::FILE_PATH,
                $context[ContextAttributes::MD5]
            );
            if (isset($context[ContextAttributes::LINE])) {
                $messageID = sprintf($pattern, $messageID, ContextAttributes::LINE, $context[ContextAttributes::LINE]);
            }
        }
        return sprintf("%s-%s", $messageID, $this->getId());
    }


    /**
     * Get the previous comment for given topic and context.
     *
     * @return mixed the previous comment
     * @throws \Exception
     */
    public function getPreviousComment(Connection $p4, $strict = false)
    {
        // create options
        if ($strict) {
            $options = [
                static::FETCH_BY_TOPIC        => $this->get(self::TOPIC),
                static::FETCH_BY_CONTEXT      => $this->getFileContext(),
                static::FETCH_BY_TASK_STATE   => null,
                static::FETCH_BY_USER         => null,
                static::FETCH_IGNORE_ARCHIVED => null
            ];
        } else {
            $options = [
                static::FETCH_BY_TOPIC        => $this->get(self::TOPIC),
                static::FETCH_BY_CONTEXT      => $this->createMinimalMatchingContext($this->getFileContext()),
                static::FETCH_BY_TASK_STATE   => null,
                static::FETCH_BY_USER         => null,
                static::FETCH_IGNORE_ARCHIVED => null
            ];
        }


        $comments = $this->fetchAdvanced($options, $p4);

        // filter comments with non-matching batched flag
        $currentBatched = $this->get(self::BATCHED);
        $comments->filterByCallback(
            function (Comment $comment) use ($currentBatched) {
                return $comment->get(self::BATCHED) == $currentBatched;
            }
        );

        $currentID = $this->getId();
        $lastItem  = $comments->last();
        if ($lastItem && $currentID == $lastItem->getId() && $comments->count() > 1) {
            $position = $comments->count();
            // we - 2 as count starts at 1 where as position start at 0.
            return $comments->nth($position - 2);
        }

        return null;
    }

    /**
     * Checks if the 'readBy' should be reset. Conditions for reset are
     * - the body changes
     * - a task is opened/reopened on the comment
     * - the comment moves from archived to unarchived
     * - attachments change
     * @return bool
     */
    private function shouldMarkAsUnread() : bool
    {
        return $this->hasAttachmentChanged() || $this->hasBodyChanged()
            || ($this->getTaskState() === TaskState::OPEN
                   && isset($this->original[self::TASK_STATE])
                   && $this->original[self::TASK_STATE] !== TaskState::OPEN) ||
               (isset($this->original[self::FLAGS])
                   && in_array(static::FLAG_CLOSED, $this->original[self::FLAGS])
                   && !in_array(static::FLAG_CLOSED, $this->getFlags()));
    }

    /**
     * Check if the 'likes' should be reset. Conditions for reset are
     * - the body has changed
     * - attachment has changed
     * @return bool
     */
    private function shouldClearLikes() : bool
    {
        return $this->hasBodyChanged() || $this->hasAttachmentChanged();
    }

    /**
     * Check if the attachment have changed.
     *
     * @return bool
     */
    private function hasAttachmentChanged() : bool
    {
        $attachments         = $this->getAttachments();
        $originalAttachments = isset($this->original[self::ATTACHMENTS])
            ? (array) $this->original[self::ATTACHMENTS] : [];
        return sizeof(array_diff($originalAttachments, $attachments)) > 0;
    }

    /**
     * Check if the body has changed.
     *
     * @return bool
     */
    public function hasBodyChanged(): bool
    {
        $originalBody = null;
        if ($this->original && isset($this->original[self::BODY])) {
            $originalBody = $this->original[self::BODY];
        }
        return $this->get(self::BODY) !== $originalBody;
    }

    /**
     * Get the Original data before the state.
     * @return null
     */
    public function getOriginal()
    {
        return $this->original;
    }

    /**
     * Saves the records values and updates indexes as needed.
     * Extends the basic save behavior to also:
     * - set timestamp to current time if one isn't already set
     * - remove existing count indices for this topic and add a current one
     *
     * @return  Comment     to maintain a fluent interface
     * @throws Exception if no topic is set or an id is present but the record was not fetched
     * @throws \P4\Exception
     */
    public function save()
    {
        if (!strlen($this->get(self::TOPIC))) {
            throw new Exception('Cannot save, no topic has been set.');
        }

        if ($this->shouldMarkAsUnread()) {
            $this->setReadBy([]);
        }
        // Check we need to clear likes before saving.
        if ($this->shouldClearLikes()) {
            $this->setLikes([]);
        }

        // always set update time to now
        $this->set(self::UPDATED, time());

        // if no time is already set, use now as a default
        $this->set(self::TIME, $this->get(self::TIME) ?: $this->get(self::UPDATED));

        // set 'batched' to the value of delayNotification or batched field if set
        $this->set(self::BATCHED, $this->get('delayNotification') || $this->get(self::BATCHED));
        // now unset delayNotification as we will not need it anymore
        $this->unsetRawValue('delayNotification');

        // scan for new attachments
        $attachments = $this->getAttachments();
        $original    = isset($this->original[self::ATTACHMENTS]) ? (array) $this->original[self::ATTACHMENTS] : [];
        $attachments = array_diff($attachments, $original);

        // let parent actually save before we go about indexing
        parent::save();

        $this->updateCountIndex();

        // update attachment references
        if ($attachments) {
            $attachments = Attachment::fetchAll(
                [Attachment::FETCH_BY_IDS => $attachments],
                $this->getConnection()
            );

            foreach ($attachments as $attachment) {
                $attachment->addReference(self::COMMENT, $this->getId());
                $attachment->save();
            }
        }
        return $this;
    }

    /**
     * Delete this comment record.
     * Extends parent to update topic count index.
     *
     * @return Comment  provides fluent interface
     */
    public function delete() : Comment
    {
        parent::delete();
        $this->updateCountIndex();

        return $this;
    }

    /**
     * Try to fetch the associated user as a user spec object.
     *
     * @return User     the associated user object
     * @throws SpecNotFoundException|\P4\Exception    if user does not exist
     */
    public function getUserObject() : User
    {
        $userDao = self::getUserDao();
        if (!$this->userObject) {
            $this->userObject = $userDao->fetchById($this->get('user'), $this->getConnection());
        }

        return $this->userObject;
    }

    /**
     * Check if the associated user is valid (exists)
     *
     * @return bool     true if the user exists, false otherwise.
     * @throws \P4\Exception
     */
    public function isValidUser() : bool
    {
        try {
            $this->getUserObject();
        } catch (SpecNotFoundException $e) {
            return false;
        }
        return true;
    }

    /**
     * Extract file information from context.
     *
     * @return  array  array of file info - values may be null.
     */
    public function getFileContext() : array
    {
        $context = $this->get(self::CONTEXT) + [
            ContextAttributes::FILE_PATH => null,
            ContextAttributes::CHANGE => null,
            ContextAttributes::REVIEW => null,
            ContextAttributes::LEFT_LINE => null,
            ContextAttributes::RIGHT_LINE => null,
            ContextAttributes::FILE_CONTENT => null,
            ContextAttributes::REVIEW_VERSION => null,
            ];

        $file                             = $context[ContextAttributes::FILE_PATH];
        $context[ContextAttributes::MD5]  = $file ? md5($file) : null;
        $context[ContextAttributes::NAME] = basename($file);
        $context[ContextAttributes::LINE] =
            $context[ContextAttributes::RIGHT_LINE] ?: $context[ContextAttributes::LEFT_LINE];

        return $context;
    }

    /**
     * Returns the iterator with attachment models for given comments. Fetching is done
     * efficiently by collecting all attachment ids from all given comments and then
     * doing a single fetch query on Attachments.
     *
     * @param   ModelIterator   $comments   comments to fetch attachments for
     * @param   Connection      $p4         Perforce connection to use
     * @return  ModelIterator               iterator with attachments for given comments
     */
    public static function fetchAttachmentsByComments(ModelIterator $comments, Connection $p4)
    {
        $attachments = $comments->invoke('getAttachments');
        $attachments = $attachments ? call_user_func_array('array_merge', $attachments) : null;
        return $attachments
            ? Attachment::fetchAll([Attachment::FETCH_BY_IDS => $attachments], $p4)
            : new ModelIterator;
    }

    /**
     * Retrieves the open/closed comment counts stored by p4 index. This can
     * be lower than the actual comment counts due to the potential for race
     * conditions when saving, but should generally be correct.
     *
     * @param  string|array $topics     one or more topics to count open/closed comments in.
     * @param  Connection   $p4         the perforce connection to use
     * @return array        a single array with open/closed count if a string topic is given,
     *                      otherwise an array of open/closed count arrays keyed by topic.
     * @deprecated Use CommentDao->countByTopic in preference
     */
    public static function countByTopic($topics, Connection $p4) : array
    {
        $dao = self::getCommentDao();
        return $dao->countByTopic($topics, $p4);
    }

    /**
     * Update the open/closed comment count index for this comment's topic.
     *
     * For each topic we maintain an index of the number of open and closed
     * comments in that topic. This makes it easy to fetch the number of
     * comments in arbitrary topics with one call to p4 search.
     *
     * @return Comment  provides fluent interface
     * @throws \Exception
     */
    protected function updateCountIndex() : Comment
    {
        // retrieve all existing indices for this topic count and delete them
        $p4      = $this->getConnection();
        $topic   = static::encodeIndexValue($this->get(self::TOPIC));
        $query   = static::COUNT_INDEX . '=' . $topic;
        $indices = $p4->run('search', $query)->getData();
        foreach ($indices as $index) {
            $p4->run(
                'index',
                ['-a', static::COUNT_INDEX, '-d', $index],
                $topic
            );
        }

        // read out all comments for this topic so we can get the new counts
        // we try and do this as close to writing out the index to improve
        // our chances of getting an accurate count.
        $comments = static::fetchAll(
            [static::FETCH_BY_TOPIC => $this->get(self::TOPIC)],
            $p4
        );

        // early exit if no comments (zero count)
        if (!count($comments)) {
            return $this;
        }

        // count open/closed (aka 'archived') comments separately
        $opened = 0;
        $closed = 0;
        foreach ($comments as $comment) {
            in_array(static::FLAG_CLOSED, $comment->getFlags()) ? $closed++ : $opened++;
        }

        // write out our current count to the index.
        // we include the encoded topic id in the key so that we can tell
        // which topic each count is for when searching for multiple topics.
        $p4->run(
            'index',
            ['-a', static::COUNT_INDEX, $topic . '-' . $opened . '-' . $closed],
            $topic
        );

        return $this;
    }

    /**
     * Attempts to derive the action (add, edit, state-change) from the provided Comments
     *
     * @param   Comment|array       $new    the "newer" incarnation of the comment
     * @param   Comment|null|array  $old    the "older" incarnation of the comment
     *                                      (null or empty array will short circuit to an "Add" action)
     * @return  string  A string corresponding to one of the action constants:
     *                      Comment::ACTION_ADD
     *                      Comment::ACTION_EDIT
     *                      Comment::ACTION_STATE_CHANGE
     *                      Comment::ACTION_LIKE
     *                      Comment::ACTION_UNLIKE
     *                      Comment::ACTION_ARCHIVE
     *                      Comment::ACTION_UNARCHIVE
     *                      Comment::ACTION_NONE
     */
    public static function deriveAction($new, $old = null)
    {
        $old = $old instanceof self ? $old->get() : $old;
        $new = $new instanceof self ? $new->get() : $new;

        if (!is_array($new) || (!is_array($old) && !is_null($old))) {
            throw new \InvalidArgumentException(
                'Cannot derive action: New and Old must be comment instances or arrays. '
                . 'Old may be null to indicate an add.'
            );
        }

        if ($new && !$old) {
            return static::ACTION_ADD;
        }

        // normalize arrays to avoid key lookup failures
        $old += [self::BODY => null, self::ATTACHMENTS => null, self::TASK_STATE => null, self::LIKES => null];
        $new += [self::BODY => null, self::ATTACHMENTS => null, self::TASK_STATE => null, self::LIKES => null];

        if ($old[self::BODY] != $new[self::BODY] || $old[self::ATTACHMENTS] != $new[self::ATTACHMENTS]) {
            return static::ACTION_EDIT;
        }

        if ($old[self::TASK_STATE] != $new[self::TASK_STATE]) {
            return static::ACTION_STATE_CHANGE;
        }

        $newLikes = count((array) $new[self::LIKES]);
        $oldLikes = count((array) $old[self::LIKES]);
        if ($newLikes !== $oldLikes) {
            return $newLikes > $oldLikes ? static::ACTION_LIKE : static::ACTION_UNLIKE;
        }

        if (isset($new[self::FLAGS]) && in_array(static::FLAG_CLOSED, $new[self::FLAGS]) &&
            (!isset($old[self::FLAGS])
                || isset($old[self::FLAGS]) && !in_array(static::FLAG_CLOSED, $old[self::FLAGS]))) {
                return static::ACTION_ARCHIVE;
        }

        if (isset($old[self::FLAGS]) && in_array(static::FLAG_CLOSED, $old[self::FLAGS]) && isset($new[self::FLAGS])
            && !in_array(static::FLAG_CLOSED, $new[self::FLAGS])) {
            return static::ACTION_UNARCHIVE;
        }

        return static::ACTION_NONE;
    }

    /**
     * Get list of users who have read this comment.
     *
     * @return  array   list of users who have read this comment
     */
    public function getReadBy() : array
    {
        $readBy = (array) $this->getRawValue(self::READ_BY);
        return array_values(array_unique(array_filter($readBy, 'strlen')));
    }

    /**
     * Set list of users that have read this comment.
     *
     * @param   array       $users  list with users that have read this comment
     * @return  KeyRecord   to maintain a fluent interface
     */
    public function setReadBy(array $users) : KeyRecord
    {
        $users = array_values(array_unique(array_filter($users, 'strlen')));
        return $this->setRawValue(self::READ_BY, $users);
    }

    /**
     * Add 'read by' for the given user for this comment.
     *
     * @param   string      $user   user to add 'read by' for
     * @return  KeyRecord   to maintain a fluent interface
     */
    public function addReadBy($user) : KeyRecord
    {
        return $this->setReadBy(array_merge($this->getReadBy(), [$user]));
    }

    /**
     * Remove 'read by' for the given user for this comment.
     *
     * @param   mixed       $user   user to remove 'read by' for
     * @return  KeyRecord   to maintain a fluent interface
     */
    public function removeReadBy($user) : KeyRecord
    {
        return $this->setReadBy(array_diff($this->getReadBy(), [$user]));
    }

    /**
     * Is this comment a reply
     *
     * @return bool
     */
    public function isReply() : bool
    {
        $context = $this->getContext();
        return isset($context[ContextAttributes::COMMENT]) && is_int($context[ContextAttributes::COMMENT]);
    }

    /**
     * Get the route that this comment is linked to. If it is plural we remove the tailing
     * 's' from the name as most routes are single.
     *
     * @return string
     */
    public function getRoute() : string
    {
        $exploded = explode('/', $this->get(self::TOPIC));
        return trim($exploded[0], 's') ?: 'home';
    }

    /**
     * Builds a target based on the context
     * @param array $context the context
     * @return string|null
     */
    public static function getFileTarget(array $context)
    {
        $target = null;
        if (isset($context[ContextAttributes::FILE_PATH])) {
            $line   = isset($context[ContextAttributes::LINE]) ? ", line " .  $context[ContextAttributes::LINE] : '';
            $target = DecodeSpec::decode(
                $context,
                (DecodeSpec::isStream($context) ? ContextAttributes::FILE_PATH : ContextAttributes::NAME)
            ) . $line;
        }
        return $target;
    }

    /**
     * @inheritDoc
     * Override for public access
     */
    public static function encodeIndexValue($value) : string
    {
        return parent::encodeIndexValue($value);
    }

    /**
     * @inheritDoc
     * Override for public access
     */
    public static function decodeIndexValue($value) : string
    {
        return parent::decodeIndexValue($value);
    }

    /**
     * Fetch a comment by its id
     * @param mixed         $id     the id
     * @param Connection    $p4     the connection
     * @return KeyRecord
     * @throws RecordNotFoundException
     */
    public static function fetchById($id, Connection $p4) : KeyRecord
    {
        return Comment::fetch($id, $p4);
    }
}
