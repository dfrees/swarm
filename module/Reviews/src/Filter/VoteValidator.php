<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\Validator\ArrayValuesValidator;

/**
 * Class VoteValidator. Test if a vote is valid. Single values and arrays of values are supported.
 * @package Reviews\Filter
 */
class VoteValidator extends ArrayValuesValidator
{
    const VOTE_UP       = 'up';
    const VOTE_DOWN     = 'down';
    const VOTE_CLEAR    = 'clear';
    const VOTE_NONE     = 'none';
    const VALID         = [
        self::VOTE_UP, self::VOTE_DOWN, self::VOTE_CLEAR, Vote::VOTE_UP, Vote::VOTE_DOWN, Vote::VOTE_CLEAR
    ];
    const VALID_FILTERS = [self::VOTE_UP, self::VOTE_DOWN, self::VOTE_NONE];
    const INVALID_VOTE  = 'invalidVote';

    /**
     * VoteValidator constructor.
     * @param $translator
     * @param array $valid_values
     * @param string $invalid_vote
     * @param string $field
     */
    public function __construct(
        $translator,
        $valid_values = self::VALID,
        $invalid_vote = self::INVALID_VOTE,
        $field = 'vote'
    ) {

        parent::__construct(
            $translator,
            $valid_values,
            $invalid_vote,
            $field,
            [
                self::CASE_SENSITIVE => true,
                self::SUPPORT_ARRAYS => true
            ]
        );
    }
}
