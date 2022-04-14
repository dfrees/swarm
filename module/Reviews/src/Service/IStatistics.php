<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Service;

/**
 * Interface IStatistics. Describes values and responsibilites for a statistics service
 * @package Reviews\Service
 */
interface IStatistics
{
    const COMPLEXITY_SERVICE = 'reviewComplexity';

    /**
     * Calculate the complexity for the review and save. If access is not permitted to the review
     * for the admin connection then complexity cannot be calculated.
     * The complexity for a review takes the form:
     * [
     *      'files_modified' => <int>,
     *      'lines_added'    => <int>,
     *      'lines_edited'   => <int>,
     *      'lines_deleted'  => <int>
     * ]
     * @param mixed     $reviewId       the review id
     */
    public function calculateComplexity($reviewId);

    /**
     * Builds a default array to describe complexity
     * @return array
     */
    public function buildComplexity();
}
