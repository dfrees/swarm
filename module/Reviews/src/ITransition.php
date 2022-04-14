<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews;

use Reviews\Model\Review;

/**
 * Interface ITransition. Some common fields related to review state changes
 * @package Reviews
 */
interface ITransition
{
    const TRANSITION                 = 'transition';
    const JOBS                       = 'jobs';
    const FIX_STATUS                 = 'fixStatus';
    const WAIT                       = 'wait';
    const CLEAN_UP                   = 'cleanup';
    const TEXT                       = 'text';
    const STATE_APPROVED_PENDING     = Review::STATE_APPROVED . ":isPending";
    const STATE_APPROVED_NOT_PENDING = Review::STATE_APPROVED . ":notPending";
    // All the possible transitions allowed to be specified to move to
    const ALL_VALID_TRANSITIONS = [
        Review::STATE_NEEDS_REVIEW,
        Review::STATE_NEEDS_REVISION,
        Review::STATE_APPROVED,
        Review::STATE_APPROVED_COMMIT,
        Review::STATE_REJECTED,
        Review::STATE_ARCHIVED
    ];
    // Transitions that are not valid end states but are used in fetch queries
    const SPECIAL_TRANSITIONS = [self::STATE_APPROVED_PENDING, self::STATE_APPROVED_NOT_PENDING];
}
