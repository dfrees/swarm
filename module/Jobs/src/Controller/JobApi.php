<?php

namespace Jobs\Controller;

use Application\Config\IDao;
use Exception;
use Api\Controller\AbstractRestfulController;
use Application\Connection\ConnectionFactory;
use Jobs\Filter\IGetJobs;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Spec\Job;

class JobApi extends AbstractRestfulController
{
    use JobTrait;
    const DATA_JOBS = 'jobs';

    /**
     * Get a list of jobs.
     *
     * Example response:
     *   {
     *       "error": null,
     *       "messages": [],
     *       "data": {
     *           "jobs": [
     *               {
     *                   "job": "job000020",
     *                   "link": "/jobs/job000020",
     *                   "fixStatus": "open",
     *                   "description": "Need Project files\n",
     *                   "descriptionMarkdown": "<span class=\"first-line\">Need Project files</span>"
     *              },
     *              ...
     *              ...
     *       ]
     *   }
     *
     * Example error response:
     *   {
     *       "error": <code>,
     *       "messages": [
     *           {
     *               "code": <code>,
     *               "text": "<message>"
     *           }
     *       ],
     *       "data": null
     *   }
     *
     * @return JsonModel
     */
    public function getList() : JsonModel
    {
        $errors  = null;
        $jobs    = [];
        $request = $this->getRequest();
        $query   = $request->getQuery();
        try {
            $services = $this->services;
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $jobDao   = $services->get(IDao::JOB_DAO);
            $filter   = $services->get(IGetJobs::FILTER);
            $options  = $query->toArray();
            $filter->setData($options);
            if ($filter->isValid()) {
                $options  = $filter->getValues();
                $options += [
                    Job::FETCH_REVERSE => true,
                    Job::FETCH_BY_FILTER => $options[IGetJobs::FILTER_PARAMETER] ?? null,
                    Job::FETCH_MAXIMUM => $options[IGetJobs::FETCH_MAX] ?? 50
                ];
                unset($options[IGetJobs::FETCH_MAX]);
                $jobs = $jobDao->buildJobsArray($jobDao->fetchAll($options, $p4Admin));
                $jobs = $this->filterDescriptions($jobs);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors !== null) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success(
                [
                    self::DATA_JOBS => $jobs
                ]
            );
        }
        return $json;
    }
}
