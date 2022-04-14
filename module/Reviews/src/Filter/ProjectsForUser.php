<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Filter;

use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Permissions\PrivateProjects;
use Interop\Container\ContainerInterface;
use Laminas\Filter\AbstractFilter;
use P4\Exception as P4Exception;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Projects\Model\Project as ProjectModel;

/**
 * Filter to handle 'projects-for-user:<userId>'
 * @package Reviews\Filter
 */
class ProjectsForUser extends AbstractFilter implements IProjectsForUser
{
    private $services;
    private $roles;

    /**
     * Construct the filter
     * @param ContainerInterface    $services   application services
     * @param array                 $options    options for the filter. Accepts a 'roles' value that will limit the
     *                                          roles when filtering. Can accept a string or array. For example a value
     *                                          [
     *                                              ProjectModel::MEMBERSHIP_LEVEL_MEMBER,
     *                                              ProjectModel::MEMBERSHIP_LEVEL_MODERATOR
     *                                          ]
     *                                          would only consider projects where the user is a member or moderator
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        if (isset($options[self::ROLES])) {
            $this->roles = (array)$options[self::ROLES];
        }
    }

    /**
     * The value is cast to an array and if any of the values is set to a special value 'projects-for-user:<userId>'
     * this function will modify the value to include all of the projects the user is involved in (as determined by
     * $project->isInvolved). For example:
     * If one of the values was 'projects-for-user:swarm' and swarm was involved in projects
     * a, b, and c then 'value' would be changed to be [a, b, c]. This allows a simple parameter to expand into many
     * projects.
     * @param mixed $value value
     * @return array an array of unique projects with the original value(s) merged with any projects found for the user
     * if the special value was detected
     * @throws P4Exception
     */
    public function filter($value) : array
    {
        $myProjects = [];
        $values     = (array)$value;
        foreach ($values as $arrayValue) {
            if (is_string($arrayValue)
                    && preg_match('/^' . self::PROJECTS_FOR_USER . '(.+)$/', $arrayValue, $matches)) {
                $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
                $userId  = $matches[1];
                $userDAO = $this->services->get(IModelDAO::USER_DAO);
                try {
                    $user       = $userDAO->fetchById($userId, $p4Admin);
                    $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
                    $projects   = $projectDAO->fetchAll([], $p4Admin);
                    $groupDAO   = $this->services->get(IModelDAO::GROUP_DAO);
                    $allGroups  = $groupDAO->fetchAll([], $p4Admin)->toArray(true);
                    $projects   = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($projects);
                    $myProjects = (array)$projects->filterByCallback(
                        function (ProjectModel $project) use ($user, $allGroups) {
                            return $project->isInvolved($user, $this->roles, $allGroups);
                        }
                    )->invoke('getId');
                } catch (SpecNotFoundException $e) {
                    $logger = $this->services->get(SwarmLogger::SERVICE);
                    $logger->warn(sprintf("User [%s] in [%s] not found", $userId, $value));
                }
                $values = array_diff($values, [$arrayValue]);
                break;
            }
        }
        return array_unique(array_merge($values, $myProjects));
    }
}
