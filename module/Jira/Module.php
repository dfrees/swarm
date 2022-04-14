<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jira;

use Application\Config\ConfigException;
use Application\Filter\Linkify;
use Application\Config\ConfigManager;
use Jira\Model\Linkage;
use P4\Spec\Change;
use P4\Spec\Definition;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Job;
use Record\Exception\NotFoundException;
use Reviews\Model\Review;
use Laminas\Http\Client as HttpClient;
use Laminas\Json\Json;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;

class Module
{
    // The location of the Swarm logo for jira, within the the apache docroot
    const SWARM_LOGO_LOCATION = '/swarm/img/logo-sm.png';

    /**
     * The JIRA module performs a few tasks assuming it has configuration data available.
     *
     * On worker 1 startup (so every ~10 minutes) we cache a copy of all valid JIRA project
     * ids by querying the JIRA server's 'project' route. This data is used for the later
     * work described below.
     *
     * Whenever text is linkified we link any JIRA issues that appear.
     *
     * When reviews are added/updated we ensure all JIRA issues they reference either in
     * their description or via associated jobs have links back to the review and that the
     * links labels have the current review status.
     *
     * When changes are committed we ensure all JIRA issues they reference either in
     * their description or via associated jobs have links back to the change in Swarm.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     * @throws \Application\Config\ConfigException
     */
    public function onBootstrap(MvcEvent $event)
    {
        $services = $event->getApplication()->getServiceManager();
        $config   = $services->get('config');
        $host     = ConfigManager::getValue($config, ConfigManager::JIRA_HOST);
        $projects = static::getProjects();

        // bail out if we lack a host, we won't be able to do anything
        if (!$host) {
            return;
        }

        // add the linkify callback if we have projects defined
        if ($projects) {
            self::linkifyJira($projects, $host);
        }
    }

    /**
     * Setup linkify callback to jira urls.
     *
     * @param mixed $projects           List of all projects
     * @param mixed $host               Jira host from config settings
     */
    public static function linkifyJira($projects, $host)
    {
        Linkify::addCallback(
            function ($linkify, $value, $escaper) use ($projects, $host) {
                // Need to split projects into chunks to prevent the pattern generated being too large for preg_match
                $chunks = static::chunkProjects($projects);
                foreach ($chunks as $chunk) {
                    $regex = "/^@?(?P<issue>(?:" . implode('|', array_map('preg_quote', $chunk)) . ")-[0-9]+)('s)?$/";
                    // if it looks like a jira issue for a known project linkify
                    if (preg_match($regex, $value, $matches)) {
                        return '<a href="'
                            . $escaper->escapeFullUrl($host . '/browse/' . $matches['issue']) . '">'
                            . $escaper->escapeHtml($value) . '</a>';
                    }
                }
                // not a hit; tell caller we didn't handle this one
                return false;
            },
            'jira',
            min(array_map('strlen', $projects)) + 2
        );
    }

    /**
     * Jira project list could potentially be too large for a single regex expression so we want to
     * split it up to avoid 'regular expression is too large' errors. 500 is based on sample data
     * from a customer with 5000 projects ~ 10 characters in each name so breaking this into 10 for that
     * size should be suitable
     * @param array     $projects   project array to chunk
     * @return array project chunks
     */
    private static function chunkProjects(array $projects)
    {
        return array_chunk($projects, 500);
    }

    /**
     * This method figures out which JIRA issues are involved with the passed review or
     * change either via mentions in the description or associated jobs and updates them:
     * - JIRA issues that are no longer associated have their Swarm links deleted.
     * - JIRA issues that are new have Swarm links added.
     * - If the link title or summary has changed, any old JIRA issue links are updated.
     *
     * @param   Change|Review   $item           the change or review we're linking to
     * @param   ServiceLocator  $services       the service locator
     * @param   Boolean         $delayed        Has this already been delayed.
     * @throws SpecNotFoundException
     * @throws \Application\Config\ConfigException
     * @throws \P4\Spec\Exception\Exception
     */
    public static function updateIssueLinks($item, ServiceLocator $services, $delayed = false)
    {
        $p4Admin      = $services->get('p4_admin');
        $qualifiedUrl = $services->get('ViewHelperManager')->get('qualifiedUrl');
        $truncate     = $services->get('ViewHelperManager')->get('truncate');
        $serverBase   = $qualifiedUrl();
        $icon         = (P4_SERVER_ID ? str_replace('/'.P4_SERVER_ID, '', $serverBase) : $serverBase)
            . static::SWARM_LOGO_LOCATION;
        $summary      = (string) $truncate($item->getDescription(), 80);

        if (!$item instanceof Job) {
            $linkedIssues = self::getLinkedJobIssues($item->getId(), $services);
            $callouts     = self::getJiraCallouts($item->getDescription(), $services);
            $issues       = array_merge($linkedIssues, $callouts);
            $issues       = array_values(array_unique(array_filter($issues, 'strlen')));
            sort($issues);
        }
        // get the linkage details for this issue; creating a new record if needed
        try {
            $linkage = Linkage::fetch($item->getId(), $p4Admin);
        } catch (NotFoundException $e) {
            $linkage = new Linkage($p4Admin);
            $linkage->setId($item->getId());
        }

        // the title, URL and jira global id vary by type (review/change); figure that out
        if ($item instanceof Review) {
            $title  = 'Review ' . $item->getId() . ' - ' . $item->getStateLabel() . ', ';
            $title .= $item->isCommitted() ? 'Committed' : 'Not Committed';
            $url    = $qualifiedUrl('review', ['review' => $item->getId()]);
            $jiraId = 'swarm-review-' . md5(serialize(['review' => $item->getId()]));

            // if this is a legacy record where the JIRA state is stored on the
            // review upgrade that data to being stored in the linkage record
            if ($item->get('jira')) {
                $old = $item->get('jira') + ['label', 'issues'];
                $linkage->set('title',  $old['label'])
                        ->set('issues', $old['issues']);

                // strip the jira value off of the review so we don't do this again
                $item->unsetRawValue('jira')->save();
            }
        } elseif ($item instanceof Change) {
            $title  = 'Commit ' . $item->getId();
            $url    = $qualifiedUrl('change', ['change' => $item->getId()]);
            $jiraId = 'swarm-change-' . md5(serialize(['change' => $item->getId()]));
        } elseif ($item instanceof Job) {
            // Get the jira config data.
            // get the delay link time.
            $config        = $services->get('config');
            $delayJobLinks = ConfigManager::getValue($config, ConfigManager::JIRA_DELAY_JOB_LINKS, 60);
            // Check if job has been delayed.
            if ($delayed === false && $delayJobLinks > 0) {
                // If the item is not delayed we should delay to prevent race condition.
                $queue  = $services->get('queue');
                $future = time() + $delayJobLinks;
                $queue->addTask('job', $item->getId(), ['delayed' => true], $future);
                return;
            }
            if (ConfigManager::getValue($config, ConfigManager::JIRA_LINK_TO_JOBS, false)) {
                $title    = 'Job ' . $item->getId();
                $url      = $qualifiedUrl('job', ['job' => $item->getId()]);
                $jiraId   = 'swarm-job-' . md5(serialize(['job' => $item->getId()]));
                $jobField = ConfigManager::getValue($config, ConfigManager::JIRA_JOB_FIELD);

                if (!$jobField || !Definition::fetch('job', $p4Admin)->hasField($jobField)) {
                    return;
                }

                $jobSpec = Job::fetchById($item->getId(), $p4Admin);
                $issue   = $jobSpec->get($jobField);
                // As p4 jobs adds a new line after each line we need to remove these.
                $summary = preg_replace("/\r|\n/", "", $summary);
            } else {
                // If we don't want jira to update then we should return empty.
                return;
            }
        } else {
            throw new \InvalidArgumentException('Update Issue Links expects a Change, Job or Review');
        }

        // pull out the 'old' issues/title/summary/jobs before we update the linkage
        $old = $linkage->get();

        // record the new values before we start mucking with JIRA. this should help
        // ensure we don't get into a loop where we update JIRA, it tickles DTG which
        // updates jobs; round and round we go.
        $linkage->set('title',   $title)
                ->set('summary', $summary);

        // First check if this is an instance of a job and process that.
        if ($item instanceof Job) {
            // Save the linkage.
            $linkage->save();
            // Call Jira to build link.
            self::callJira($services, $issue, $jiraId, $url, $title, $summary, $icon);
        } else {
            // This will process the change or review jira url creation and update.
            $linkage->set('issues', $issues)->set('jobs', array_keys($linkedIssues))->save();

            // remove links from any issues which are no longer impacted
            $delete = array_diff($old['issues'], $issues);
            foreach ($delete as $issue) {
                self::doRequest(
                    'delete',
                    'issue/' . $issue . '/remotelink',
                    ['globalId' => $jiraId],
                    $services
                );
            }
            // time to deal with new/added issues
            // if the title and summary are unchanged; only add new issues. otherwise we add new
            // issues and update existing issues to match the new title/summary.
            $updates = $issues;
            if ($title == $old['title'] && $summary == $old['summary']) {
                $updates = array_diff($issues, $old['issues']);
            }
            foreach ($updates as $issue) {
                self::callJira($services, $issue, $jiraId, $url, $title, $summary, $icon);
            }
        }
    }

    /**
     * Call jira and check the response
     * If jira fails put the call into a queue again.
     * @param $services
     * @param $issue
     * @param $jiraId
     * @param $url
     * @param $title
     * @param $summary
     * @param $icon
     * @throws ConfigException
     */
    public static function callJira($services, $issue, $jiraId, $url, $title, $summary, $icon)
    {
        $config       = $services->get(ConfigManager::CONFIG);
        $relationShip = ConfigManager::getValue($config, ConfigManager::JIRA_RELATIONSHIP);

        self::doRequest(
            'post',
            'issue/' . $issue . '/remotelink',
            [
                'globalId'  => $jiraId,
                'object'    => [
                    'url'       => $url,
                    'title'     => $title,
                    'summary'   => $summary,
                    'icon'      => [
                        'url16x16'  => $icon,
                        'title'     => 'Swarm'
                    ]
                ],
                ConfigManager::RELATIONSHIP => $relationShip
            ],
            $services
        );
    }

    /**
     * Given a change or change id this method will find all associated perforce jobs
     * and return the list of JIRA issue ids that appear in the 'job_field'.
     *
     * @param   string|int|Change   $change     the change to examine
     * @param   ServiceLocator      $services   the service locator
     * @return  array               an array of JIRA issues keyed on associated job id
     * @return array|strlen
     * @throws \Application\Config\ConfigException
     */
    public static function getLinkedJobIssues($change, ServiceLocator $services)
    {
        $p4Admin  = $services->get('p4_admin');
        $config   = $services->get('config');
        $jobField = ConfigManager::getValue($config, ConfigManager::JIRA_JOB_FIELD);
        $change   = $change instanceof Change ? $change->getId() : $change;

        // nothing to do if no job field or job field isn't defined in our spec
        if (!$jobField || !Definition::fetch('job', $p4Admin)->hasField($jobField)) {
            return [];
        }

        // determine the ids of affected jobs
        $jobs = $p4Admin->run('fixes', ['-c', $change])->getData();
        $ids  = [];
        foreach ($jobs as $job) {
            $ids[] = $job['Job'];
        }

        // fetch the jobs and collect the issues; keyed by job id
        $issues = [];
        $jobs   = Job::fetchAll([Job::FETCH_BY_IDS => $ids], $p4Admin);
        foreach ($jobs as $job) {
            $issues[$job->getId()] = $job->get($jobField);
        }

        // return the trimmed non-empty values
        return array_filter(array_map('trim', $issues), 'strlen');
    }

    /**
     * Given a string of text, this method will try and locate any JIRA issue
     * ids that are present either raw e.g. SW-123, at prefixed e.g. @SW-123
     * or listed in a full url e.g. http://<jirahost>/browse/SW-123.
     *
     * @param   string          $value      the text to examine for JIRA issue ids
     * @param   ServiceLocator  $services   the service locator
     * @return  array   an array of unique JIRA issue ids referenced in the passed text
     * @throws \Application\Config\ConfigException
     */
    public static function getJiraCallouts($value, ServiceLocator $services)
    {
        $config      = $services->get('config');
        $host        = ConfigManager::getValue($config, ConfigManager::JIRA_HOST);
        $url         = $host ? $host . '/browse/' : false;
        $trimPattern = '/^[”’"\'(<{\[]*@?(.+?)[.”’"\'\,!?:;)>}\]]*$/';
        $projects    = array_map('preg_quote', static::getProjects());
        $words       = preg_split('/([\s<>{}[]+)/', $value);
        $callouts    = [];
        foreach ($words as $word) {
            if (!strlen($word)) {
                continue;
            }

            // strip the leading/trailing punctuation from the actual word
            preg_match($trimPattern, $word, $matches);
            $word = $matches[1];

            // if it looks like a full JIRA url strip it down to just the potential issue id
            if ($url && stripos($word, $url) === 0) {
                $word = rtrim(substr($word, strlen($url)), '/');
            }

            // If the trimmed word isn't empty, matches our pattern and we haven't
            // seen before it counts towards callouts. Need to split projects into
            // chunks to prevent the pattern generated being too large for preg_match
            $projectChunks = static::chunkProjects($projects);
            foreach ($projectChunks as $projectChunk) {
                $calloutPattern = "/^(?:" . implode('|', $projectChunk) . ")-[0-9]+$/";
                if (strlen($word) && preg_match($calloutPattern, $word) && !in_array($word, $callouts)) {
                    $callouts[] = $word;
                }
            }
        }
        return $callouts;
    }

    /**
     * Convenience function to ease RESTful interaction with the JIRA service.
     *
     * @param   string          $method     one of get, post, delete
     * @param   string          $resource   the resource e.g. 'project' or 'issue/<id>/remoteLinks'
     * @param   mixed           $data       get/post data to include on the request or null/false for none
     * @param   ServiceLocator  $services   the service locator
     * @return  mixed           the response or false if request fails
     */
    public static function doRequest($method, $resource, $data, ServiceLocator $services)
    {
        // we commonly do a number of requests and don't want one failure to bork them all,
        // if anything goes wrong just log it
        try {
            list($client, $url) = self::getClient($method, $resource, $data, $services);

            // attempt the request and log any errors
            $logger = $services->get('logger');
            $logger->info('JIRA making ' . $method . ' request to resource: ' . $url, (array) $data);
            $response = $client->dispatch($client->getRequest());

            if (!$response->isSuccess()) {
                self::handleBadRequest($method, $response, $client, $logger, $url);
                return false;
            }

            // looks like it worked, return the result
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $services->get('logger')->err($e);
        }

        return false;
    }

    /**
     * Creates and configures a new HttpClient instance
     *
     * @param   string          $method     one of get, post, delete
     * @param   string          $resource   the resource e.g. 'project' or 'issue/<id>/remoteLinks'
     * @param   mixed           $data       get/post data to include on the request or null/false for none
     * @param   ServiceLocator  $services   the service locator
     * @return  array           the new HttpClient and its corresponding url
     * @throws  \Application\Config\ConfigException
     */
    public static function getClient($method, $resource, $data, ServiceLocator $services): array
    {
        // setup the client and request details
        $config = $services->get('config');
        $host   = ConfigManager::getValue($config, ConfigManager::JIRA_API_HOST);
        if (!isset($host) || $host === "") {
            $host = ConfigManager::getValue($config, ConfigManager::JIRA_HOST);
        }
        $url    = $host . '/rest/api/latest/' . $resource;
        $client = new HttpClient;
        $client->setUri($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setMethod($method);

        // set the http client options; including any special overrides for our host
        $options = $config + ['http_client_options' => []];
        $options = (array)$options['http_client_options'];
        if (isset($options['hosts'][$client->getUri()->getHost()])) {
            $options = (array)$options['hosts'][$client->getUri()->getHost()] + $options;
        }
        unset($options['hosts']);
        $client->setOptions($options);

        if ($method == 'post') {
            $client->setRawBody(Json::encode($data));
        } else {
            $client->setParameterGet((array)$data);
        }
        $user = ConfigManager::getValue($config, ConfigManager::JIRA_USER);
        if ($user) {
            $client->setAuth($user, ConfigManager::getValue($config, ConfigManager::JIRA_PASSWORD));
        }
        return [$client, $url];
    }

    /**
     * Handles responses with unsuccessful response codes
     *
     * @param   string                $method     one of get, post, delete
     * @param   string                $response   response from server
     * @param   HttpClient            $client
     * @param   \Laminas\Log\Logger      $logger
     * @param   string                $url        source of the response
     */
    private static function handleBadRequest($method, $response, $client, $logger, $url)
    {
        $status  = $response->getStatusCode();
        $reason  = $response->getReasonPhrase();
        $message = 'JIRA failed to ' . $method . ' resource: ' . $url . ' (' . $status . " - " . $reason . ').';
        $extra   = [
            'request' => $client->getLastRawRequest(),
            'response' => $client->getLastRawResponse()
        ];

        self::logRequestError($logger, $message, $extra);
    }

    /**
     * Encodes and logs the message and the request and response returned from Jira
     *
     * @param   \Laminas\Log\Logger      $logger
     * @param   string                $message
     * @param   array|\Traversable    $extra
     */
    public static function logRequestError($logger, $message, $extra)
    {
        //Make sure that we don't break the logger with a non-utf8 string
        $request           = $extra['request'];
        $response          = $extra['response'];
        $extra['request']  = mb_check_encoding($request, 'UTF-8') ? $request : utf8_encode($request);
        $extra['response'] = mb_check_encoding($response, 'UTF-8') ? $response : utf8_encode($response);

        $logger->err($message, $extra);
    }

    /**
     * Get the project ids that are defined in JIRA from cache.
     *
     * @return  array   array of project ids in JIRA, empty array if cache is missing/empty
     */
    public static function getProjects()
    {
        $file = DATA_PATH . '/cache/jira/projects';
        if (!file_exists($file)) {
            return [];
        }

        return (array) json_decode(file_get_contents($file), true);
    }

    /**
     * Get the path to write cache entries to. Ensures directory is writable.
     *
     * @return  string  the cache directory to write to
     * @throws  \RuntimeException   if the directory cannot be created or made writable
     */
    public static function getCacheDir()
    {
        $dir = DATA_PATH . '/cache/jira';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }

        return $dir;
    }

    /**
     * The config defaults.
     *
     * @return  array   the default config for this module
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
