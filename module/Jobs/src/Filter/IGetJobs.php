<?php

namespace Jobs\Filter;

use Application\Factory\InvokableService;

interface IGetJobs extends InvokableService
{
    const FILTER           = 'getJobsFilter';
    const FILTER_PARAMETER = 'filter';
    const FETCH_MAX        = 'max';
}
