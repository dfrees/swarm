<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Activity\Model;

use Activity\Controller\IActivityApi;
use Activity\Filter\IParameters;
use Application\Connection\ConnectionFactory;
use Application\Model\AbstractDAO;
use Application\Model\IModelDAO;
use Application\Permissions\IpProtects;
use Application\Permissions\PrivateProjects;
use Application\Permissions\Protections;
use Application\Permissions\RestrictedChanges;
use Exception;
use P4\Connection\ConnectionInterface;
use P4\Model\Connected\Iterator;
use Record\Key\AbstractKey;

/**
 * Class ActivityDAO to handle access to Perforce activity data
 * @package Changes\Model
 */
class ActivityDAO extends AbstractDAO
{
    // The Perforce class that handles activity
    const MODEL = Activity::class;

    const FETCH_MAX_DEFAULT = 50;
    const PERSONAL          = 'personal';
    const REVIEWHYPHEN      = 'review-';

    /**
     * Return the fetch max option value if set otherwise fallback to 50.
     * @param $options  array   - Array of options
     * @return integer          - The max option for fetching activities
     * @throws Exception
     */
    protected function getFetchMaxOption(array $options): int
    {
        if (isset($options[Activity::FETCH_MAX])) {
            return $options[Activity::FETCH_MAX];
        } else {
            return self::FETCH_MAX_DEFAULT;
        }
    }

    /**
     * Returns a list of activities, filtered by 'after', 'max' as well as restricted access to private resources
     * @param array $options                            - Optionals options to filter the list of activities
     * @param ConnectionInterface|null $connection      - The P4 connection to be used
     * @return object                                   - List of activities
     * @throws Exception
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null)
    {
        if (isset($options[Activity::FETCH_BY_CHANGE])) {
            $change    = $options[Activity::FETCH_BY_CHANGE];
            $changeDAO = $this->services->get(IModelDAO::CHANGE_DAO);
            $changeDAO->fetchById($change, $connection); // This will throw an error if invalid
        }

        if (isset($options[IParameters::FOLLOWED]) && $options[IParameters::FOLLOWED]) {
            $userId = self::PERSONAL.'-'.$this->services->get(ConnectionFactory::USER)->getId();
            if (isset($options[Activity::FETCH_BY_STREAM])) {
                array_push($options[Activity::FETCH_BY_STREAM], $userId);
            } else {
                $options += [
                    Activity::FETCH_BY_STREAM => [$userId]
                ];
            }
        }

        $options[Activity::FETCH_MAX]            = $this->getFetchMaxOption($options);
        $options[AbstractKey::FETCH_MAXIMUM]     = $options[Activity::FETCH_MAX];
        $options[AbstractKey::FETCH_TOTAL_COUNT] = true;

        $activities = Activity::fetchAll($options, $this->getConnection($connection));

        if (isset($options[AbstractKey::FETCH_TOTAL_COUNT])) {
            if (!$activities->hasProperty(AbstractKey::FETCH_TOTAL_COUNT)) {
                $activities->setProperty(AbstractKey::FETCH_TOTAL_COUNT, sizeof($activities));
            }
        }

        return  $this->filterActivityData($activities);
    }

    /**
     * Returns a list of activities for a specific stream type and stream ID
     * @param string $streamPath                        - The path of the stream (review, change, job)...
     * @param string $streamId                          - The ID of the stream to fetch activities for
     * @param array $options                            - Optional additional options, such as 'after' or 'max'
     * @param ConnectionInterface|null $connection      - The P4 connection to be used
     * @return object                                   - List of activities for specified stream
     * @throws Exception
     */
    public function fetchByStream(
        string $streamPath,
        string $streamId,
        array $options = [],
        ConnectionInterface $connection = null
    ) {
        $stream   = $streamPath === IActivityApi::REVIEWS_STREAM ?
            self::REVIEWHYPHEN . $streamId : $streamPath . "-" . $streamId;
        $options += [
            Activity::FETCH_BY_STREAM => [$stream],
        ];
        return $this->fetchAll($options, $connection);
    }

    /**
     * Filter out any permission related activities
     * @param mixed $activities activities return from fetchAll
     * @return Iterator
     */
    protected function filterActivityData($activities): Iterator
    {
        // Grab the size before we remove any for restrictions
        $originalSize = sizeof($activities);

        // remove activities related to restricted/forbidden changes
        $activities = $this->services->get(RestrictedChanges::class)->filter($activities, 'change');
        // filter out private projects
        $activities = $this->services->get(PrivateProjects::PROJECTS_FILTER)->filter($activities, 'projects');

        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);

        $totalCount = $activities->getProperty(AbstractKey::FETCH_TOTAL_COUNT);

        $activities->filterByCallback(
            function ($activity) use ($ipProtects) {
                $includeActivity = true;
                // filter out activities related to files user doesn't have access to
                $depotFile = $activity->get(IActivity::DEPOTFILE);
                if ($depotFile && !$ipProtects->filterPaths($depotFile, Protections::MODE_READ)) {
                    $includeActivity = false;
                }
                return $includeActivity;
            }
        );
        // We may have removed some for restrictions  get the new size
        $restrictedSize = sizeof($activities);
        $sizeDiff       = $originalSize - $restrictedSize;
        $lastSeen       = $activities->getProperty(AbstractKey::LAST_SEEN) ?? 0;

        $activities->setProperty(AbstractKey::FETCH_TOTAL_COUNT, $totalCount - $sizeDiff);
        $activities->setProperty(AbstractKey::LAST_SEEN, $lastSeen);

        return $activities;
    }
}
