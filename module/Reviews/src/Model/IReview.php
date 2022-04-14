<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Model;

/**
 * Interface IReview. Define values and responsibilities for a review
 * @package Reviews\Model
 */
interface IReview
{
    // Custom field for returning markdown format of description
    const FIELD_DESCRIPTION_MARKDOWN = 'description-markdown';
    // Defines some options that can be used when saving a review
    const EXCLUDE_UPDATED_DATE = 'excludeUpdatedDate';
    const LOCK_CHANGE_PREFIX   = 'change-review-';
    // Define a mode of operation for adding a change to a review
    const ADD_CHANGE_MODE = 'addChangeMode';
    const APPEND_MODE     = 'append';
    const REPLACE_MODE    = 'replace';
    // Define some constants to support reviewers as groups
    const REVIEWER_GROUPS    = 'reviewerGroups';
    const REVIEWERS          = 'reviewers';
    const REVIEWER           = 'reviewer';
    const REQUIRED_REVIEWERS = 'requiredReviewers';
    const REVIEWER_QUORUMS   = 'reviewerQuorum';
    const QUORUM             = 'quorum';

    const FIELD_VOTE                 = 'vote';
    const FIELD_ID                   = 'id';
    const FIELD_AUTHOR               = 'author';
    const FIELD_APPROVALS            = 'approvals';
    const FIELD_DESCRIPTION          = 'description';
    const FIELD_STATE                = 'state';
    const FIELD_STATE_LABEL          = 'stateLabel';
    const FIELD_PARTICIPANTS         = 'participants';
    const FIELD_PARTICIPANTS_DATA    = 'participantsData';
    const FIELD_UP_VOTES             = 'upVotes';
    const FIELD_DOWN_VOTES           = 'downVotes';
    const FIELD_ROLES                = 'roles';
    const FIELD_UPDATE_DATE          = 'updateDate';
    const FIELD_UPDATED              = 'updated';
    const FIELD_CREATED              = 'created';
    const FIELD_MINIMUM_REQUIRED     = 'minimumRequired';
    const FIELD_TEST_RUNS            = 'testRuns';
    const FIELD_TEST_STATUS          = 'testStatus';
    const FIELD_PREVIOUS_TEST_STATUS = 'previousTestStatus';
    const FIELD_DEPLOY_STATUS        = 'deployStatus';
    const FIELD_COMPLEXITY           = 'complexity';
    const FIELD_COMMENTS             = 'comments';
    const FIELD_PROJECTS             = 'projects';

    const ROLE_AUTHOR            = 'author';
    const ROLE_REVIEWER          = 'reviewer';
    const ROLE_REQUIRED_REVIEWER = 'required_reviewer';
    const ROLE_MODERATOR         = 'moderator';

    const FETCH_BY_AUTHOR              = 'author';
    const FETCH_BY_CHANGE              = 'change';
    const FETCH_BY_PARTICIPANTS        = 'participants';
    const FETCH_BY_AUTHOR_PARTICIPANTS = 'authorparticipants';
    const FETCH_BY_DIRECT_PARTICIPANTS = 'participantsindividual';
    const FETCH_BY_HAS_REVIEWER        = 'hasReviewer';
    const FETCH_BY_PROJECT             = 'project';
    const FETCH_BY_STATE               = 'state';
    const FETCH_BY_GROUP               = 'group';
    const FETCH_BY_TEST_STATUS         = 'testStatus';
    const FETCH_BY_NOT_UPDATED_SINCE   = 'notUpdatedSince';
    const FETCH_BY_UPDATED_SINCE       = 'updated';
    const FETCH_BY_HAS_VOTED           = 'hasVoted';
    const FETCH_BY_USER_CONTEXT        = 'user';
    const FETCH_BY_MY_COMMENTS         = 'myComments';
    const FETCH_MAX                    = 'max';
    const FETCH_AFTER_SORTED           = 'lastSorted';

    const ORDER_BY_UPDATED = 'updated';

    const STATE_NEEDS_REVIEW    = 'needsReview';
    const STATE_NEEDS_REVISION  = 'needsRevision';
    const STATE_APPROVED        = 'approved';
    const STATE_APPROVED_COMMIT = 'approved:commit';
    const STATE_REJECTED        = 'rejected';
    const STATE_ARCHIVED        = 'archived';

    const COMMIT_CREDIT_AUTHOR = 'creditAuthor';
    const COMMIT_DESCRIPTION   = 'description';
    const COMMIT_JOBS          = 'jobs';
    const COMMIT_FIX_STATUS    = 'fixStatus';

    const TEST_STATUS_PASS    = 'pass';
    const TEST_STATUS_FAIL    = 'fail';
    const TEST_STATUS_STARTED = 'started';

    const MY_COMMENTS_HAVE = 'true';
    const MY_COMMENTS_NOT  = 'false';

    const STREAMSPECDIFF = 'streamSpecDifference';

    const SHELVEDEL     = 'Shelvedel:: ';
    const MINVOTES      = 'MinVotes:: ';
    const CLIENT        = 'client';
    const CWD           = 'cwd';
    const USER          = 'user';
    const FILES         = 'files';
    const DELFROMCHANGE = 'deleteFromChange';

    const REVIEW_OBLITERATE = "Review-Obliterate:";
    // Values for complexity
    const FILES_MODIFIED = 'files_modified';
    const LINES_ADDED    = 'lines_added';
    const LINES_EDITED   = 'lines_edited';
    const LINES_DELETED  = 'lines_deleted';
}
