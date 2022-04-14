<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jira\Listener;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Events\Listener\AbstractEventListener;
use Events\Listener\ListenerFactory as EventListenerFactory;
use Jira\Model\Linkage;
use Jira\Module;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Job;
use Reviews\Model\Review;
use Laminas\EventManager\Event;
use Laminas\Json\Json;

class JiraListener extends AbstractEventListener
{
    /**
     * Check if we should attach to the event that is being triggered.
     *
     * We have a few checks that must apply before we allow the event to attach.
     * - Swarm MUST have a Jira host set in the config.
     * - Additionally for events of change, commit, job or review the job_field must be defined.
     *
     * @param mixed $eventName   The event name
     * @param array $eventDetail The events detail
     * @return bool
     * @throws ConfigException
     */
    protected function shouldAttach($eventName, $eventDetail)
    {
        $config   = $this->services->get(ConfigManager::CONFIG);
        $jiraHost = ConfigManager::getValue($config, ConfigManager::JIRA_HOST);
        // If the Jira host is not set we can avoid attaching.
        if (!$jiraHost) {
            return false;
        }

        // If we are change, commit, job or review event then check for the job_field.
        // If not enabled, return false.
        if ($eventName === EventListenerFactory::TASK_COMMIT
            || $eventName === EventListenerFactory::TASK_JOB
            || $eventName === EventListenerFactory::TASK_CHANGE
            || $eventName === EventListenerFactory::TASK_REVIEW
        ) {
            $projects     = Module::getProjects();
            $jiraJobField = ConfigManager::getValue($config, ConfigManager::JIRA_JOB_FIELD);
            if (!$projects && !$jiraJobField) {
                return false;
            }
        }

        return true;
    }

    // connect to worker 1 startup to refresh our cache of jira project ids
    public function refreshProjectList(Event $event)
    {
        parent::log($event);
        if ($event->getParam('slot') !== 1) {
            return;
        }

        // attempt to request the list of projects, if the request fails keep
        // whatever list we have though as something is better than nothing.
        $cacheDir = Module::getCacheDir();
        $result   = Module::doRequest('get', 'project', null, $this->services);
        if ($result !== false) {
            $projects = [];
            foreach ((array) $result as $project) {
                if (isset($project['key'])) {
                    $projects[] = $project['key'];
                }
            }

            file_put_contents($cacheDir . '/projects', Json::encode($projects));
        }
    }

    // when a review is created or updated, find any associated JIRA issues;
    // either via associated jobs or callouts in the description, and ensure
    // the JIRA issues link back to the review in Swarm.
    public function checkReview(Event $event)
    {
        parent::log($event);
        $review = $event->getParam('review');
        if (!$review instanceof Review) {
            return;
        }

        try {
            // update any associated issues
            Module::updateIssueLinks($review, $this->services);
        } catch (\Exception $e) {
            $this->services->get(SwarmLogger::SERVICE)->err($e);
        }
    }

    // when a change is submitted or updated, find any associated JIRA issues;
    // either via associated jobs or callouts in the description, and ensure
    // the JIRA issues link back to the change in Swarm.
    public function checkChange(Event $event)
    {
        parent::log($event);
        $change = $event->getParam('change');
        if (!$change instanceof Change) {
            try {
                $change = Change::fetchById($event->getParam('id'), $this->services->get(ConnectionFactory::P4_ADMIN));
                $event->setParam('change', $change);
            } catch (SpecNotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }
        }

        // if this isn't a submitted change; nothing to do
        if (!$change instanceof Change || !$change->isSubmitted()) {
            return;
        }

        try {
            Module::updateIssueLinks($change, $this->services);
        } catch (\Exception $e) {
            $this->services->get(SwarmLogger::SERVICE)->err($e);
        }
    }

    // when a job task flies by it may represent the job being added to or removed
    // from a change or review. fetch associated changes and ensure they are linked
    public function handleJob(Event $event)
    {
        parent::log($event);
        $config   = $this->services->get(ConfigManager::CONFIG);
        $logger   = $this->services->get(SwarmLogger::SERVICE);
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $job      = $event->getParam('job');
        $maxFixes = ConfigManager::getValue(
            $config,
            ConfigManager::JIRA_MAX_JOB_FIXES,
            -1
        );

        // if we don't have a job; nothing to do
        if (!$job instanceof Job) {
            return;
        }

        // Figure out the changes that are, or were, impacted by this job
        // If max fixes not 0 (-1 is the default, meaning no limit) then get the 'old' links to update.
        // Limit to the value of max fixes, $job->getChanges will ignore a -ve number so in effect there
        // is not maximum.
        $logger->debug('Jira::Max fixes: ' . $maxFixes);
        $ids = $job->getChanges($maxFixes);
        $logger->debug('Jira::getChanges ids: ' . var_export($ids, true));
        if ($maxFixes !== 0) {
            // Maximum fixes to update is specified. We look at the count of changes we are going to
            // update and see what is remaining. For example if maxFixes is 10 and there are 3 changes
            // we will update 7 of the old linkages. If there were 10 changes we would not update any
            // old linkages.
            $options       = [Linkage::FETCH_BY_JOB => $job->getId()];
            $fixesToUpdate = $maxFixes - sizeof($ids);
            $logger->debug('Jira::fixesToUpdate: ' . $fixesToUpdate);
            if ($fixesToUpdate > 0) {
                $options[Linkage::FETCH_MAXIMUM] = $fixesToUpdate;
            }
            $logger->debug('Jira::Linkage fetch options: ' . var_export($options, true));
            if (isset($options[Linkage::FETCH_MAXIMUM]) || $maxFixes === -1) {
                // We have some linkages left to get or we wanted no maximum with maxFixes = -1 so
                // we want to do the fetch
                $linkages = Linkage::fetchAll($options, $p4Admin);
                $logger->debug('Jira::Linkage count before merge: ' . sizeof($linkages));
                $ids = array_unique(array_merge($ids, $linkages->invoke('getId')));
                $logger->debug('Jira::unique change and fixes ids: ' . var_export($ids, true));
            }
        }

        // fetch any items that represent submitted changes or represent reviews
        // note, we only deal with JIRA links for committed changes and reviews
        $changes = Change::fetchAll(
            [Change::FETCH_BY_IDS => $ids, Change::FETCH_BY_STATUS => Change::SUBMITTED_CHANGE],
            $p4Admin
        );
        $reviews = Review::fetchAll(
            [Review::FETCH_BY_IDS => array_diff($ids, $changes->invoke('getId'))],
            $p4Admin
        );

        // Add/update the job link in JIRA back to Swarm.
        try {
            $delayed   = false;
            $eventData = $event->getParam('data');
            if (isset($eventData['delayed'])) {
                $delayed = $eventData['delayed'];
            }
            Module::updateIssueLinks($job, $this->services, $delayed);
        } catch (\Exception $e) {
            $logger->err($e);
        }

        // for each change/review we found, update the JIRA links
        foreach ($changes->merge($reviews) as $item) {
            try {
                Module::updateIssueLinks($item, $this->services);
            } catch (\Exception $e) {
                $logger->err($e);
            }
        }
    }
}
