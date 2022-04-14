<?php

namespace Jobs\Controller;

use Application\Filter\Preformat;
use Jobs\Model\IJob;
use P4\Filter\Utf8 as Utf8Filter;

/**
 * Trait to carry out common functions for jobs to be used by classes that have services in context
 */
trait JobTrait
{
    /**
     * Iterate the jobs to filter out any invalid characters from the description
     * @param mixed     $jobs   jobs in array from
     * @return mixed jobs in array form
     */
    public function filterDescriptions($jobs)
    {
        $preFormat = new Preformat($this->services, $this->getRequest()->getBaseUrl());
        $utf8      = new Utf8Filter;
        foreach ($jobs as &$job) {
            $job[IJob::FIELD_DESCRIPTION_MARKDOWN] = $preFormat->filter($job[IJob::FIELD_DESCRIPTION]);
            // Filter out any problem characters that may be in the job description
            $job[IJob::FIELD_DESCRIPTION] = $utf8->filter($job[IJob::FIELD_DESCRIPTION]);
        }
        return $jobs;
    }
}
