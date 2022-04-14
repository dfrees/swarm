<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Laminas\Filter\AbstractFilter;

/**
 * Class Vote. Filter for votes to convert 'up', 'down' 'clear' to '1', '-1' and '0'
 * held against a review
 * @package Reviews\Filter
 */
class Vote extends AbstractFilter
{
    const VOTE_UP    = '1';
    const VOTE_DOWN  = '-1';
    const VOTE_CLEAR = '0';

    /**
     * Run the filter
     * @param mixed $value
     * @return mixed|string
     */
    public function filter($value)
    {
        $retVal = $value;
        switch ($value) {
            case VoteValidator::VOTE_UP:
                $retVal = self::VOTE_UP;
                break;
            case VoteValidator::VOTE_DOWN:
                $retVal = self::VOTE_DOWN;
                break;
            case VoteValidator::VOTE_CLEAR:
                $retVal = self::VOTE_CLEAR;
                break;
            default:
                break;
        }
        return $retVal;
    }
}
