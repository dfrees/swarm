<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jobs\Controller;

use Application\Controller\AbstractIndexController;
use P4\Connection\Exception\CommandException;
use P4\Spec\Definition as Spec;
use P4\Spec\Job;
use P4\Spec\Exception\NotFoundException;
use Reviews\Model\Review;
use Application\InputFilter\InputFilter;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractIndexController
{
    public function jobAction()
    {
        $services = $this->services;
        $p4       = $services->get('p4');
        $route    = $this->getEvent()->getRouteMatch();
        $id       = $route->getParam('job');
        $project  = $route->getParam('project');
        $readme   = $route->getParam('readme');
        $request  = $this->getRequest();
        $query    = $request->getQuery('q');
        $config   = $services->get('config');

        // if path contains a possible job id, attempt to look it up.
        $job = null;
        if ($id && !$query) {
            try {
                $job = Job::fetchById($id, $p4);
            } catch (NotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            // if we didn't get the job and trimming would make
            // a difference try to fetch the trimmed id as well
            $trimmed = trim($id, '/');
            if (!$job && $trimmed && $id != $trimmed) {
                try {
                    $job = Job::fetchById($trimmed, $p4);
                } catch (NotFoundException $e) {
                } catch (\InvalidArgumentException $e) {
                }
            }

            // if id is numeric and we still have no job, try prefixing with 'job0...'
            if (!$job && ctype_digit($trimmed)) {
                try {
                    $prefixed = 'job' . str_pad($trimmed, 6, '0', STR_PAD_LEFT);
                    if (Job::exists($prefixed, $p4)) {
                        return $this->redirect()->toRoute('job', ['job' => $prefixed]);
                    }
                } catch (NotFoundException $e) {
                } catch (\InvalidArgumentException $e) {
                }
            }
        }

        // hand-off to the jobs action if no precise match and doing a GET
        if (!$job && $request->isGet()) {
            return $this->forward()->dispatch(
                IndexController::class,
                [
                    'action'  => 'jobs',
                    'query'   => $query ?: $id,
                    'project' => $project,
                    'readme'  => $readme
                ]
            );
        } elseif (!$job) {
            $this->response->setStatusCode(404);
            return;
        }

        // handle edits
        if ($request->isPost()) {
            $services->get('permissions')->enforce('authenticated');

            $spec   = $job->getSpecDefinition();
            $filter = $this->getJobFilter($spec);
            $posted = $request->getPost()->toArray();
            $filter->setData($posted);
            $filter->setValidationGroupSafe(array_keys($posted));

            // if posted data passes filter, attempt to update job
            $isValid = $filter->isValid();
            if ($isValid) {
                try {
                    $job->set($filter->getValues())->save();

                    // we forward to the jobs action to get JSON output with raw values
                    $jobs = $this->forward()->dispatch(
                        IndexController::class,
                        [
                            'action'  => 'jobs',
                            'format'  => 'json',
                            'query'   => $spec->fieldCodeToName(101) . "=" . $job->getId()
                        ]
                    );
                    $jobs = $jobs->getVariable('jobs');
                } catch (CommandException $e) {
                    $isValid = false;
                }
            }

            return new JsonModel(
                [
                    'isValid'  => $isValid,
                    'messages' => $filter->getMessages(),
                    'error'    => isset($e)    ? $e->getMessage() : null,
                    'job'      => isset($jobs) ? current($jobs)   : null
                ]
            );
        }

        // first off separate changes into pending and committed buckets
        $changes   = $job->getChangeObjects();
        $pending   = [];
        $committed = [];
        foreach ($changes as $change) {
            if ($change->isSubmitted()) {
                $committed[$change->getId()] = $change;
            } else {
                $pending[$change->getId()] = $change;
            }
        }

        // determine which pending changes are actually reviews and separate them
        $p4Admin = $services->get('p4_admin');
        $all     = $pending;
        $reviews = Review::exists(array_keys($all), $p4Admin);
        $pending = array_diff_key($pending, array_flip($reviews));
        $reviews = array_diff_key($all, $pending);

        // remove reviews not accessible by the current user
        $filter = $services->get('projects_filter');
        foreach (Review::fetchAll([Review::FETCH_BY_IDS => array_keys($reviews)], $p4Admin) as $model) {
            if (!$filter->canAccess($model)) {
                unset($reviews[$model->getId()]);
            }
        }

        return new ViewModel(
            [
                'job'   => $job,
                'fixes' => [
                    'reviews'   => $reviews,
                    'committed' => $committed,
                    'pending'   => $pending
                ],
                'mentionsMode' => $config['mentions']['mode']
            ]
        );
    }

    public function jobsAction()
    {
        $services = $this->services;
        $route    = $this->getEvent()->getRouteMatch();
        $project  = $route->getParam('project');
        $readme   = $route->getParam('readme');
        $request  = $this->getRequest();
        $query    = $request->getQuery('q', $route->getParam('query'));
        $max      = $request->getQuery('max', 50);
        $after    = $request->getQuery('after');
        $format   = $request->getQuery('format', $route->getParam('format'));
        $json     = $format == 'json';
        $partial  = $format == 'partial';

        // early exit if not requesting jobs data (just render the page)
        if (!$json) {
            $model = new ViewModel(
                [
                    'partial' => $partial,
                    'query'   => $query,
                    'project' => $project,
                    'readme'  => $readme
                ]
            );

            $model->setTerminal($partial);
            return $model;
        }

        // compose job search expression
        // if a project was passed, automatically apply it's jobview
        $filter  = trim($query);
        $jobview = $project ? trim($project->get('jobview')) : null;
        if ($jobview) {
            $filter  = $filter  ? "($filter) "  : "";
            $filter .= $jobview ? "($jobview)"  : "";
        }

        $p4   = $services->get('p4');
        $jobs = [];
        try {
            $jobs = Job::fetchAll(
                [
                    Job::FETCH_BY_FILTER    => trim($filter) ?: null,
                    Job::FETCH_REVERSE      => true,
                    Job::FETCH_MAXIMUM      => $max,
                    Job::FETCH_AFTER        => $after
                ],
                $p4
            );
        } catch (CommandException $e) {
            // we expect the user might enter a bad expression or field name
            $pattern = "/expression parse error|unknown field name|can't handle \^ \(not\) operator there/i";
            if (!preg_match($pattern, $e->getMessage())) {
                throw $e;
            }
        }

        // prepare jobs for output
        // special handling for the id, user, date and text fields.
        $rows = [];
        $spec = Spec::fetch('job', $p4);
        $view = $services->get('ViewRenderer');
        foreach ($jobs as $job) {
            $row = ['__id' => $job->getId()];
            foreach ($job->get() as $key => $value) {
                $info = $spec->getField($key) + ['default' => null];

                // include the raw value for editing
                $row['__raw-' . $key] = $view->utf8Filter($value);

                if (!strlen($value)) {
                    // if value is empty, nothing to do
                    // handle this case early to avoid errors trying to
                    // create links to null users or similar
                } elseif ($info['code'] == 101) {
                    $value = '<a href="' . $view->url('job', ['job' => $value]) . '">'
                           . $view->escapeHtml($value)
                           . '</a>';
                } elseif ($info['code'] == 102) {
                    $value = $view->wordify($value);
                } elseif ($info['default'] === '$user') {
                    $value = $view->userLink($value);
                } elseif ($info['default'] === '$now') {
                    $value = '<span class=timeago title="'
                           . $view->escapeHtmlAttr(date('c', $job->getAsTime($key)))
                           . '"></span>';
                } else {
                    $value = (string) $view->preformat($value);
                }

                $row[$key] = $value;
            }
            $rows[] = $row;
        }

        // enhance spec with a quick lookup for important fields
        $job    = new Job($p4);
        $fields = $spec->getFields() + [
            '__id'           => $spec->fieldCodeToName(101),
            '__status'       => $spec->fieldCodeToName(102),
            '__description'  => $spec->fieldCodeToName(105),
            '__createdBy'    => $job->hasCreatedByField()    ? $job->getCreatedByField()    : null,
            '__createdDate'  => $job->hasCreatedDateField()  ? $job->getCreatedDateField()  : null,
            '__modifiedBy'   => $job->hasModifiedByField()   ? $job->getModifiedByField()   : null,
            '__modifiedDate' => $job->hasModifiedDateField() ? $job->getModifiedDateField() : null
            ];

        $model = new JsonModel;
        $model->setVariables(
            [
                'project' => $project,
                'query'   => $query,
                'spec'    => $fields,
                'jobs'    => $rows,
                'after'   => $after,
                'errors'  => isset($e) ? $e->getResult()->getErrors() : [],
                'readme'  => $readme
            ]
        );

        return $model;
    }

    /**
     * Get an input filter suitable for validating job updates
     *
     * @param   Spec         $spec  job fields and their options
     * @return  InputFilter  the input filter for validating jobs
     */
    protected function getJobFilter(Spec $spec)
    {
        $filter = new InputFilter;
        foreach ($spec->getFields() as $name => $info) {
            $filter->add(
                [
                    'name'     => $name,
                    'required' => $info['fieldType'] === 'required'
                ]
            );
        }

        return $filter;
    }
}
