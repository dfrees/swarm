<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews;

use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Model\ServicesModelTrait;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Reviews\Model\Review;
use Projects\Model\Project as ProjectModel;
use P4\Spec\Change;
use P4\Connection\ConnectionInterface as Connection;
use Groups\Model\Group;

/**
 * Service methods to assess a review when it is updated
 * @package Reviews
 */
class UpdateService
{
    use ServicesModelTrait;

    const AFFECTED_PROJECTS = 'affected_projects';
    const NEW_PROJECTS      = 'new_projects';
    // constants for options
    const FORCE_REQUIREMENT  = 'force_requirement';
    const ALWAYS_ADD_DEFAULT = 'add_defaults';
    const FORCE_RETAINED     = 'force_retained';

    /**
     * Checks the projects/branches the given review affects by examining the depot paths
     * for the branches relating to the most recent revision of the review.
     * @param ServiceLocator    $services   application services
     * @param Review            $review     the review
     * @return array an array with the currently affected project and branches and an array of any changes
     * from the projects/branches currently associated with the review.
     * @throws \P4\Exception
     * @throws \P4\Spec\Exception\NotFoundException
     */
    public static function checkAffectedProjects(ServiceLocator $services, Review $review)
    {
        $p4Admin      = $services->get(ConnectionFactory::P4_ADMIN);
        $findAffected = $services->get(Services::AFFECTED_PROJECTS);
        $headChange   = Change::fetchById((string)$review->getHeadChange(), $p4Admin);
        $affected     = $findAffected->findByChange($p4Admin, $headChange);
        return [
            UpdateService::AFFECTED_PROJECTS => $affected,
            UpdateService::NEW_PROJECTS      => UpdateService::getNewBranches($affected, $review->getProjects())
        ];
    }

    /**
     * Gets a project/branch array of any new projects that were in affected by not in current
     * @param array     $affected   the affected projects
     * @param array     $current    the current projects
     * @return array the difference between affected and current or an empty array if there are no differences
     */
    public static function getNewBranches(array $affected, array $current)
    {
        $new = [];
        foreach ($affected as $project => $branches) {
            if (isset($current[$project])) {
                foreach ($branches as $branch) {
                    $branchFound = false;
                    foreach ($current[$project] as $currentBranch) {
                        $branchFound = $branch === $currentBranch;
                        if ($branchFound) {
                            break;
                        }
                    }
                    if (!$branchFound) {
                        $new[$project][] = $branch;
                    }
                }
            } else {
                $new[$project] = $branches;
            }
        }
        return $new;
    }

    /**
     * Work out what the list of default reviewers for a given change is.
     *
     * @param Change        $change             The changelist id that this review is being requested for.
     * @param array         $reviewers          The existing reviewer list to be merged into. A reference is required
     *                                          in case new reviewers are added.
     * @param Connection    $p4                 A perforce connection for collating data
     * @param array         $options            optional, valid values are
     *                                          UpdateService::FORCE_REQUIREMENT  => true|false
     *                                          Defaults to false to update any participants requirement to
     *                                          be in line with the default on the project or branch regardless of
     *                                          retention
     *                                          UpdateService::FORCE_RETAINED  => true|false
     *                                          Defaults to true to update any participants requirement to
     *                                          be in line with the default on the project or branch when retained
     *                                          UpdateService::ALWAYS_ADD_DEFAULT => true|false
     *                                          Defaults to true to which results in default reviewers being re-applied
     *                                          even if not retained. Set to false to only re-apply retained
     * @param array         $affectedProjects   the affected projects for the change if determined already, otherwise
     *                                          getAffectedByChange will be called to determine the projects
     * @return array The list(may be empty) of reviewers and their voting requirements
     * @throws \Application\Config\ConfigException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Record\Exception\NotFoundException
     */
    public static function mergeDefaultReviewersForChange(
        Change $change,
        array &$reviewers,
        Connection $p4,
        $options = [],
        $affectedProjects = null
    ) {
        $findAffected = ServicesModelTrait::getAffectedProjectsService();
        return UpdateService::mergeDefaultReviewersForProjects(
            $affectedProjects === null ? $findAffected->findByChange($p4, $change) : $affectedProjects,
            $reviewers,
            $p4,
            $options
        );
    }

    /**
     * Work out what the list of default reviewers for a given array of affected projects is.
     *
     * @param array         $affected           the array of affected projects/branches
     * @param array         $reviewers          The existing reviewer list to be merged into. A reference is required
     *                                          in case new reviewers are added.
     * @param Connection    $p4                 A perforce connection for collating data
     * @param array         $options            optional, valid values are
     *                                          UpdateService::FORCE_REQUIREMENT  => true|false
     *                                          Defaults to false to update any participants requirement to
     *                                          be in line with the default on the project or branch regardless of
     *                                          retention
     *                                          UpdateService::FORCE_RETAINED  => true|false
     *                                          Defaults to true to update any participants requirement to
     *                                          be in line with the default on the project or branch when retained
     *                                          UpdateService::ALWAYS_ADD_DEFAULT => true|false
     *                                          Defaults to true to which results in default reviewers being re-applied
     *                                          even if not retained. Set to false to only re-apply retained
     * @return array The list(may be empty) of reviewers and their voting requirements
     * @throws \Record\Exception\NotFoundException
     */
    public static function mergeDefaultReviewersForProjects(
        array $affected,
        array &$reviewers,
        Connection $p4,
        $options = []
    ) {
        $projectDAO = ServicesModelTrait::getProjectDao();
        foreach ($affected as $projectId => $branchIds) {
            // Get the project defaults
            $project         = $projectDAO->fetch($projectId, $p4);
            $retained        = $project->areDefaultReviewersRetained();
            $projectDefaults = $project->getDefaults();
            // Add project defaults to the reviewer list
            foreach ($projectDefaults['reviewers'] as $reviewerId => $requirement) {
                if ($retained) {
                    $requirement[Review::FIELD_MINIMUM_REQUIRED] =
                        isset($requirement['required']) ? $requirement['required'] : '0';
                }
                UpdateService::addRequirement($reviewers, $reviewerId, $requirement, $p4, $options, $retained);
            }
            $branches = array_filter(
                $project->getBranches(),
                function ($branch) use ($branchIds) {
                    return in_array($branch['id'], $branchIds);
                }
            );
            foreach ($branches as $branchIndex => $branch) {
                $retained       = isset($branch[ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS]) &&
                    $branch[ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS] === true;
                $branchDefaults = $branch['defaults']['reviewers'];
                // Apply rules of inheritance
                foreach ($branchDefaults as $reviewerId => $requirement) {
                    if ($retained) {
                        $requirement[Review::FIELD_MINIMUM_REQUIRED] =
                            isset($requirement['required']) ? $requirement['required'] : '0';
                    }
                    UpdateService::addRequirement($reviewers, $reviewerId, $requirement, $p4, $options, $retained);
                }
            }
        }
        return $reviewers;
    }

    /**
     * Update a list of reviewers with this reviewer if appropriate. The rules applied are that
     * a level of requirement for a given reviewer takes precedence; i.e. required > require 1 > optional.
     * Also adds in a similar retainRequired field if the reviewer has a default minimum retention level.
     * @param array         $reviewers          the currently effective list of reviewers
     * @param string        $reviewer           the id of the reviewer being added
     * @param mixed         $requirement        the level of the current reviewer
     * @param mixed         $p4                 a perforce connection
     * @param array         $options            optional, valid values are
     *                                          UpdateService::FORCE_REQUIREMENT  => true|false
     *                                          Defaults to false to update any participants requirement to
     *                                          be in line with the default on the project or branch regardless of
     *                                          retention
     *                                          UpdateService::FORCE_RETAINED  => true|false
     *                                          Defaults to true to update any participants requirement to
     *                                          be in line with the default on the project or branch when retained
     *                                          UpdateService::ALWAYS_ADD_DEFAULT => true|false
     *                                          Defaults to true to which results in default reviewers being re-applied
     *                                          even if not retained. Set to false to only re-apply retained
     * @param bool          $retained           whether the reviewer is retained
     */
    private static function addRequirement(&$reviewers, $reviewer, $requirement, $p4, $options, $retained)
    {
        // Just incase the options is not array cast it to array.
        $options  = (array) $options;
        $options += [
            self::FORCE_REQUIREMENT  => false,
            self::ALWAYS_ADD_DEFAULT => true,
            self::FORCE_RETAINED     => true
        ];

        $validReviewer = Group::isGroupName($reviewer)
            ? ServicesModelTrait::getGroupDao()->exists(Group::getGroupName($reviewer), $p4)
            : ServicesModelTrait::getUserDao()->exists($reviewer, $p4);

        if ($validReviewer) {
            if (!isset($reviewers[$reviewer])
                && ($options[self::ALWAYS_ADD_DEFAULT] === true
                    || isset($requirement[Review::FIELD_MINIMUM_REQUIRED]))) {
                $reviewers[$reviewer] = $requirement;
            } else {
                $force = $options[self::FORCE_REQUIREMENT] === true ||
                    ($retained && $options[self::FORCE_RETAINED] === true);
                if (isset($reviewers[$reviewer]) && $force && isset($requirement['required'])) {
                    if (!isset($reviewers[$reviewer]['required']) || $requirement['required'] === true) {
                        $reviewers[$reviewer]['required'] = $requirement['required'];
                    }
                }
                if (isset($requirement[Review::FIELD_MINIMUM_REQUIRED])) {
                    if (!isset($reviewers[$reviewer][Review::FIELD_MINIMUM_REQUIRED])
                        || $requirement[Review::FIELD_MINIMUM_REQUIRED] === true) {
                        $reviewers[$reviewer][Review::FIELD_MINIMUM_REQUIRED] =
                            $requirement[Review::FIELD_MINIMUM_REQUIRED];
                    }
                }
            }
        }
    }
}
