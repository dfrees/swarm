<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Queue\Controller;

use Application\Controller\AbstractIndexController;
use Application\Filter\ShorthandBytes;
use P4\Connection\Exception\CommandException;
use P4\Key\Key;
use Queue\Listener\Ping;
use Queue\Manager;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\GenericKey;
use Laminas\EventManager\Event;
use Laminas\Log\Writer\Stream as StreamWriter;
use Laminas\View\Model\JsonModel;

class IndexController extends AbstractIndexController
{
    const   DEFAULT_WORKERS  = 3;
    const   DEFAULT_LIFETIME = 600;  // 10 minutes

    protected $configMd5 = null; // used by pre-flight to test if data/config.php has been modified

    /**
     * Workers process tasks in the queue.
     *
     * The role of a worker is very simple. It grabs the oldest task from the
     * queue and triggers an event for the task. Each event has two params:
     * id and time. The name of the event is task.type (e.g. task.change or
     * or task.change). The event manager and listeners handle the rest.
     *
     * Immediately after processing a task, the worker attempts to grab another
     * task. If there are no task in the queue, the worker will sleep for
     * a second and then try again (unless the retire flag is set in which case
     * the worker will shutdown). When the worker's running time exceeds the
     * worker lifetime setting, it exits (to avoid potential memory leakage).
     * However, any given task can run as long as it wants and consume as much
     * memory as is available (to accommodate large changes for instance).
     *
     * Workers run in the background by default (they hang-up immediately).
     * You can pass a 'debug' query parameter to prevent the worker from
     * closing the connection. When run in this mode, the worker will write
     * its log output to the client (in addition to the log file).
     *
     * @triggers worker.startup
     *           When a worker first starts up
     * @triggers task.<type>
     *           When a <type> task is processed (e.g. task.change)
     * @see Queue\Manager for more details.
     * @todo consider setting p4 service to p4_admin and clearing user identity info.
     */
    public function workerAction()
    {
        $request     = $this->getRequest();
        $response    = $this->getResponse();
        $services    = $this->services;
        $logger      = $services->get('logger');
        $manager     = $services->get('queue');
        $config      = $manager->getConfig();
        $events      = $manager->getEventManager();
        $permissions = $services->get('permissions');

        // if retire flag is set, worker will quit when queue is empty
        $retire = $request->getQuery('retire');
        $retire = $retire !== null && $retire !== '0';

        // if debug flag is set and the user has super rights, send log output
        // to the client otherwise, hang-up on client and run headless.
        $debug = $request->getQuery('debug');
        $debug = $debug !== null && $debug !== '0';
        $debug = $debug && ($permissions->is('super') || $request->isTest);
        if ($debug) {
            $logger->addWriter(new StreamWriter('php://output'));

            // flush automatically so the client gets output immediately.
            ob_implicit_flush();
        } else {
            $this->disconnect();
        }

        // as we've likely disconnected at this point, catch any exceptions and simply log them
        try {
            // attempt to get a worker slot.
            $slot = $manager->getWorkerSlot();
            if (!$slot) {
                $logger->trace('All worker slots (' . (int) $config['workers'] . ') in use.');
                if ($request->isTest) {
                    return $response;
                }
                exit;
            }

            // log worker startup.
            $logger->info("Worker $slot startup.");

            // workers have a (typically) more generous memory limit and we'll apply it if needed:
            // - if the current limit is negative its already unlimited leave it be
            // - otherwise, if the new limit is unlimited or at least larger, use it
            // in the end if the new limit would have been lower we leave it as is
            $currentLimit = ShorthandBytes::toBytes(ini_get('memory_limit'));
            $newLimit     = ShorthandBytes::toBytes($config['worker_memory_limit']);
            if ($currentLimit >= 0 && ($newLimit < 0 || $newLimit > $currentLimit)) {
                ini_set('memory_limit', $newLimit);
            }

            // do an initial preflight to ensure everything looks good and also to record
            // the starting md5 of the config.php file.
            if (!$this->preflight(true, $slot)) {
                $logger->warn("Worker $slot initial preflight failure. Aborting.");
                if ($request->isTest) {
                    return $response;
                }
                exit;
            }

            // fire startup event so external code can perform periodic tasks.
            $event = new Event;
            $event->setName('worker.startup');
            $event->setParam('slot', $slot);
            $event->setTarget($this);

            $events->triggerEvent($event);

            // start pulling tasks from the queue.
            // track our runtime so we can honor worker lifetime limit.
            $birth = time();
            while ((time() - $birth) < $config['worker_lifetime']) {
                // reset max_execution_time for each task
                ini_set('max_execution_time', $config['worker_task_timeout']);

                // if the worker lock has gone away we consider this a signal to shutdown
                // log the justification for bailing and shut 'er down.
                if (!$manager->hasWorkerSlot($slot)) {
                    $logger->info("Worker $slot has been unlocked or removed.");
                    break;
                }

                // fire loop event so that outside code can do things before we grab a task (e.g. ping the server)
                $event = new Event;
                $event->setName('worker.loop');
                $event->setParam('slot', $slot);
                $event->setTarget($this);
                $events->triggerEvent($event);

                // if we can't get a task, take a nap and spin again.
                $task = $manager->grabTask();
                if (!$task) {
                    $logger->trace("Worker $slot idle. No tasks in queue.");
                    if ($retire) {
                        break;
                    } else {
                        sleep(1);
                        continue;
                    }
                }

                // we got a task, let's process it!
                // @codingStandardsIgnoreStart
                $logger->info("Worker $slot event: task." . $task['type'] . '(' . $task['id'] . ')');
                $logger->debug('Task(' . $task['id'] . '): ' . print_r($task, true));
                // @codingStandardsIgnoreEnd

                // verify we have a working connection to p4d, that (if applicable) our replica is
                // up to date and clear our cache invalidation counters (to ensure we re-read them).
                // if things look too out of whack, put the task back for another worker and exit.
                if (!$this->preflight(true, $slot)) {
                    $logger->warn("Worker $slot preflight failure. Requeuing task and aborting.");
                    $manager->addTask($task['type'], $task['id'], $task['data'], $task['time']);
                    break;
                }

                // turn simple task into a rich event object and trigger it
                $event = new Event();
                $event->setName('task.' . $task['type']);
                $event->setParam('id',    $task['id']);
                $event->setParam('type',  $task['type']);
                $event->setParam('time',  $task['time']);
                $event->setParam('data',  $task['data']);
                $event->setTarget($this);

                $events->triggerEvent($event);
            }

            // log worker shutdown.
            $logger->info("Worker $slot shutdown.");
            $this->getStatusData($services, $permissions, $manager, $config, $logger);

            // fire shutdown event
            $event = new Event;
            $event->setName('worker.shutdown');
            $event->setParam('slot', $slot);
            $event->setTarget($this);

            $events->triggerEvent($event);

            // release our worker slot - helpful for tests
            $manager->releaseWorkerSlot($slot);
        } catch (\Exception $e) {
            // we're likely disconnected just log any exceptions
            $logger->err($e);
        }

        return $response;
    }

    /**
     * Report on the status of the queue (number of tasks, workers, etc.) - requires authentication
     *
     * @return \Laminas\View\Model\JsonModel
     */
    public function statusAction()
    {
        // only allow logged in users to see status
        $services    = $this->services;
        $permissions = $services->get('permissions');
        $permissions->enforce('authenticated');
        $manager   = $services->get('queue');
        $config    = $manager->getConfig();
        $logger    = $services->get('logger');
        $queueData = $this->getStatusData($services, $permissions, $manager, $config, $logger);
        return new JsonModel($queueData);
    }

    /**
     * Logs and collects data on the queue (number of tasks, workers, etc.) without the need for authentication
     *
     * @param $services
     * @param $permissions
     * @param $manager
     * @param $config
     * @param $logger
     * @return array
     */
    protected function getStatusData($services, $permissions, $manager, $config, $logger)
    {
        $tasks      = $manager->getTaskCounts();
        $p4Admin    = $services->get('p4_admin');
        $translator = $services->get('translator');
        $pingError  = false;

        // prepare value for $pingError, it will be one of these:
        //  - false     ... if we didn't detect any problems with sending/receiving ping or diagnostics is disabled
        //  - true      ... if we detected a problem, but want to display just a general message
        //  - <string>  ... the error message to display to the end user
        if (!$config['disable_trigger_diagnostics']) {
            try {
                $ping            = GenericKey::fetch(Ping::PING_KEY, $p4Admin);
                $lastReceivedAgo = $p4Admin->getServerTime() - (int) $ping->get('receiveTime');

                if ($ping->get('error')) {
                    $pingError = $permissions->is('admin') ? $ping->get('error') : true;
                } elseif (!$ping->get('receiveTime')) {
                    $pingError = $translator->t('Trigger diagnostic ping never received.');
                } elseif ($lastReceivedAgo > Ping::PING_LAPSE_TIME * 2) {
                    $pingError = $translator->t(
                        'Trigger diagnostic ping timed out (last received %sm ago).',
                        [round($lastReceivedAgo / 60) ?: '<1']
                    );
                }
            } catch (RecordNotFoundException $e) {
            }
        }

        $queueData = [
            'tasks'          => $tasks['current'],
            'futureTasks'    => $tasks['future'],
            'workers'        => $manager->getWorkerCount(),
            'maxWorkers'     => $config['workers'],
            'workerLifetime' => $config['worker_lifetime'] . 's',
            'pingError'      => $pingError
        ];
        $logger->trace("Queue/Status: " . var_export($queueData, true));
        return $queueData;
    }

    /**
     * Get all the tasks for the given type.
     *
     * @return \Laminas\View\Model\JsonModel
     */
    public function tasksAction()
    {
        // only allow logged in users to see status
        $services    = $this->services;
        $permissions = $services->get('permissions');
        $permissions->enforce('authenticated');
        $manager = $services->get('queue');
        $type    = $this->getRequest()->getQuery()->get('type', Manager::ALL_TASKS);
        $tasks   = $manager->getTasks($type);

        return new JsonModel(
            [
                'tasks' => $tasks,
            ]
        );
    }

    /**
     * Before processing a task we want to ensure we have a functional environment.
     *
     * We want to check a number of things:
     * - we are able to run commands against the p4d server
     * - if we're behind a replica, the replica's data is up to date
     * - our cache invalidation counters are cleared (forcing a re-check if we later access cache)
     *
     * @param   bool    $retry   true  will retry once on 'partner exited unexpectedly' errors
     * @param   integer $slot    a worker slot
     * @return  bool    true if preflight went ok, false if problems were encountered
     */
    protected function preflight($retry, $slot)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');

        // ensure the config.php file hasn't been modified since we last saw it.
        // skip if testing, the config.php may not exist.
        if (!$services->get('request')->isTest) {
            try {
                $configMd5       = md5(file_get_contents(BASE_DATA_PATH . '/config.php'));
                $this->configMd5 = $this->configMd5 !== null ? $this->configMd5 : $configMd5;
                if ($this->configMd5 !== $configMd5) {
                    // if the config is modified, simply bail retrying here won't help
                    $services->get('logger')->info("Config is modified. Preflight warning will log for worker $slot");
                    return false;
                }
            } catch (\Exception $e) {
                $services->get('logger')->info(
                    "Exception occurred while reading config. Preflight warning will log for worker $slot"
                );
                $services->get('logger')->warn($e);
                return false;
            }
        }

        // the first thing we want to do is verify we have a working connection
        // to the perforce server. if we're behind a replica we already need to
        // run a command so rely on that, for direct connections we use p4 help.
        try {
            // get the info to see if we're behind a replica. this command is
            // likely to be cached so doesn't count as verification p4d is up.
            $info = $p4Admin->getInfo();

            if (isset($info['replica'])) {
                // if we're talking to a replica, we need to ensure it is up-to-date
                // otherwise a trigger could reference data it doesn't have yet
                // make sure it's current by incrementing a counter which incurs a
                // 'journalwait' until the update has round-tripped.
                $key = new Key($p4Admin);
                $key->setId('swarm-journalwait')->increment();
            } else {
                // looks like we are not on a replica, p4 help is cheap so run it.
                $p4Admin->run('help');
            }
        } catch (\Exception $e) {
            // if retry is true and this looks like a bad connection error; force a
            // reconnect and re-run preflight as that may clear things up.
            if ($retry
                && $e instanceof CommandException
                && strpos($e->getMessage(), 'Partner exited unexpectedly') !== false
            ) {
                $p4Admin->disconnect();
                return $this->preflight(false, $slot);
            }
            // oh oh; something is awry, log failure and return
            $services->get('logger')->info(
                "Retry fail for bad connection error. Preflight error will log for worker $slot"
            );
            $services->get('logger')->err($e);
            return false;
        }

        return true;
    }
}
