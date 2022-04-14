<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews;

use P4\Log\Logger;
use Reviews\Model\IReview;

/**
 * Trait ReviewTrait. Useful functions for dealing with a review
 * @package Reviews
 */
trait ReviewTrait
{
    /**
     * Work out if the change in test status is notable. A notable change is a transition between overall pass and
     * overall fail and vice-versa
     * @param mixed     $status         current test status
     * @param mixed     $newStatus      new test status
     * @return bool if the change of status is considered notable
     */
    public static function isNotableTestStatusChange($status, $newStatus) : bool
    {
        Logger::log(
            Logger::TRACE,
            sprintf("%s: Status is [%s], newStatus is [%s]", ReviewTrait::class, $status, $newStatus)
        );
        $notable = $newStatus ?? false;
        if ($notable) {
            $notable = ($newStatus === IReview::TEST_STATUS_FAIL && $status !== IReview::TEST_STATUS_FAIL) ||
                       ($newStatus === IReview::TEST_STATUS_PASS && $status === IReview::TEST_STATUS_FAIL);
        }
        Logger::log(
            Logger::TRACE,
            sprintf("%s: Value of notable test change is [%s]", ReviewTrait::class, $notable)
        );
        return $notable;
    }
}
