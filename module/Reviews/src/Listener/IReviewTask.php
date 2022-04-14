<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Listener;

/**
 * Interface IReviewTask. Some common definitions for queue tasks related for reviews
 * @package Reviews\Listener
 */
interface IReviewTask
{
    const IS_STATE_CHANGE       = 'isStateChange';
    const IS_AUTHOR_CHANGE      = 'isAuthorChange';
    const IS_REVIEWERS_CHANGE   = 'isReviewersChange';
    const IS_DESCRIPTION_CHANGE = 'isDescriptionChange';
    const IS_VOTE               = 'isVote';
    const PREVIOUS              = 'previous';
    const QUIET                 = 'quiet';
}
