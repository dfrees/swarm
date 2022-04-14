<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Projects\Helper;

use Application\Config\Services;
use Application\Factory\InvokableService;
use Application\Helper\StringHelper;
use Application\Model\IModelDAO;
use Application\Service\P4Command;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface as Connection;
use P4\Spec\Change;
use Projects\Model\Project;
use Redis\Model\ProjectDAO;

/**
 * Service to find projects based on criteria such as changes, jobs and reviews
 * @package Projects\Helper
 */
class FindAffected implements InvokableService, IFindAffected
{
    const DEFAULT_FLAGS = '-sS';

    private $services;
    private $projectDao;
    private $changeService;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services      = $services;
        $this->projectDao    = $services->get(IModelDAO::PROJECT_DAO);
        $this->changeService = $services->get(Services::CHANGE_SERVICE);
    }

    /**
     * @inheritDoc
     */
    public function findByChange(
        Connection $connection,
        Change $change,
        $options = [P4Command::COMMAND_FLAGS => [self::DEFAULT_FLAGS], P4Command::TAGGED => false],
        $projects = null
    ) {
        $affected      = [];
        $cachedMaps    = [];
        $files         = $this->changeService->getFileList($connection, $options, $change);
        $caseSensitive = $connection->isCaseSensitive();
        $projects      = $projects === null
            ? $this->projectDao->fetchAllByPath([ProjectDAO::FETCH_BY_PATH => $files], $connection)
            : $projects;
        foreach ($projects as $key => $project) {
            $projectId = $project->getId();
            foreach ($project->getBranches() as $branch) {
                // Build up a hash map of P4_Maps to use instead of rebuilding them over and over again.
                isset($cachedMaps[$projectId][$branch[Project::FIELD_ID]]) ? :
                    $cachedMaps[$projectId][$branch[Project::FIELD_ID]] = new \P4_Map(
                        StringHelper::quoteStrings(
                            $branch,
                            Project::FIELD_BRANCH_PATHS,
                            $caseSensitive
                        )
                    );

                $map = $cachedMaps[$projectId][$branch[Project::FIELD_ID]];
                foreach ($files as $file) {
                    if ($map->includes($caseSensitive ? $file : mb_strtolower($file))) {
                        $affected += [$projectId => []];
                        $flipped   = array_flip($affected[$projectId]);
                        if (!isset($flipped[$branch[Project::FIELD_ID]])) {
                            $affected[$projectId][] = $branch[Project::FIELD_ID];
                        }
                    }
                }
            }
        }
        return $affected;
    }
}
