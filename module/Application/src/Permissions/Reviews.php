<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Reviews\Model\Review;

/**
 * Class Reviews
 *
 * @package Application\Permissions
 */
class Reviews implements InvokableService
{
    const REVIEWS_FILTER = 'reviews_filter';

    protected $services = null;

    /**
     * @inheritdoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }


    /**
     * Check if the user can access the head change of the review and also the projects on the review.
     *
     * @param Review $review This is a review
     *
     * @return bool
     */
    public function canAccessChangesAndProjects(Review $review)
    {
        $change  = $this->services->get(RestrictedChanges::class)->canAccess($review->getHeadChange());
        $project = $this->services->get(PrivateProjects::PROJECTS_FILTER)->canAccess($review);

        if ($change && $project) {
            return true;
        }
        return false;
    }
}
