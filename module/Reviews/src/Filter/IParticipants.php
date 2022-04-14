<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\Factory\InvokableService;

/**
 * Interface IParticipants. Fields related to Participants ADD/UPDATE/DELETE
 * @package Reviews\Filter
 */
interface IParticipants extends InvokableService
{
    const VALIDATE_IDS       = 'validateIds';
    const COMBINED_REVIEWERS = 'combinedReviewers';
    const REVIEW             = 'review';
    const PARTICIPANTS       = 'participants';
    const USERS              = 'users';
    const GROUPS             = 'groups';
    const REQUIRED           = 'required';
    const YES                = 'yes';
    const NO                 = 'no';
    const ALL                = 'all';
    const ONE                = 'one';
    const NONE               = 'none';
}
