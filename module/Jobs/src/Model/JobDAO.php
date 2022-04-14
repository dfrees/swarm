<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jobs\Model;

use Application\Model\AbstractDAO;
use P4\Spec\Change;
use P4\Spec\Job;

/**
 * Class JobDAO. DAO to access job information
 * @package Jobs\Model
 */
class JobDAO extends AbstractDAO
{
    // The Perforce class that handles Job
    const MODEL = Job::class;

    /**
     * Gets the jobs for a change in the form
     *       [
     *           "job"                  => "job000020",
     *           "link"                 => "/jobs/job000020",
     *           "fixStatus"            => "open",
     *           "description"          => "Need Project files\n",
     *       ]
     * @param Change $change
     * @return array an array of jobs or an empty array if none are found
     */
    public function getJobs(Change $change): array
    {
        return $this->buildJobsArray($change->getJobObjects());
    }

    /**
     * Build an array of jobs from models in the form
     *       [
     *           "job"                  => "job000020",
     *           "link"                 => "/jobs/job000020",
     *           "fixStatus"            => "open",
     *           "description"          => "Need Project files\n",
     *       ]
     * @param mixed $jobs   job models
     * @return array
     */
    public function buildJobsArray($jobs) : array
    {
        $jobsArray = [];
        foreach ($jobs as $job) {
            $jobsArray[] = [
                IJob::FIELD_JOB         => $job->getId(),
                // Included a link for now, we might find we don't need this in the react implementation
                IJob::FIELD_LINK        => '/jobs/' . $job->getId(),
                IJob::FIELD_FIX_STATUS  => $job->getStatus(),
                IJob::FIELD_DESCRIPTION => $job->getDescription()
            ];
        }
        return $jobsArray;
    }
}
