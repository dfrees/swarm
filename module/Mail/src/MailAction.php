<?php

/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Mail;

/**
 * Defines actions that get set when a mail will be sent
 * @package Mail
 */
class MailAction
{
    const COMMENT_ADDED              = 'commented on';
    const COMMENT_REPLY              = 'replied to a comment on';
    const COMMENT_EDITED             = 'edited a comment on';
    const COMMENT_LIKED              = 'liked a comment on';
    const COMMENT_ARCHIVED           = 'archived comment on';
    const COMMENT_UNARCHIVED         = 'unarchived comment on';
    const DESCRIPTION_COMMENT_ADDED  = 'commented on the description for';
    const DESCRIPTION_COMMENT_EDITED = 'edited a description comment on';
    const DESCRIPTION_COMMENT_LIKED  = 'liked a description comment on';
    const CHANGE_COMMITTED           = 'committed';

    const REVIEW_REQUESTED      = 'requested';
    const REVIEW_REJECTED       = 'rejected';
    const REVIEW_NEEDS_REVIEW   = 'requested further review of';
    const REVIEW_NEEDS_REVISION = 'requested revisions to';
    const REVIEW_APPROVED       = 'approved';
    const REVIEW_ARCHIVED       = 'archived';
    const REVIEW_UPDATED_FILES  = 'updated files in';
    const REVIEW_VOTED_UP       = 'voted up';
    const REVIEW_VOTED_DOWN     = 'voted down';
    const REVIEW_CLEARED_VOTE   = 'cleared their vote on';
    const REVIEW_LEFT           = 'left';
    const REVIEW_JOINED         = 'joined';
    const REVIEW_TESTS          = 'reported';
    const REVIEW_TESTS_NO_AUTH  = 'Automated tests reported';

    const REVIEW_EDITED_REVIEWERS   = 'edited reviewers on';
    const REVIEW_OPENED_ISSUE       = 'opened an issue on';
    const REVIEW_MAKE_REQUIRED_VOTE = 'made their vote required on';
    const REVIEW_MAKE_OPTIONAL_VOTE = 'made their vote optional on';
}
