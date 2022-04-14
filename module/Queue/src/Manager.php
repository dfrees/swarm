<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Queue;

use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Interop\Container\ContainerInterface;
use P4\Uuid\Uuid;
use Laminas\EventManager\EventManager;
use Queue\Exception\QueueException;

/**
 * A basic queue manager.
 *
 * There is a very simple script under the public folder (queue.php) that adds
 * tasks to the queue. The queue is just a directory of files where each file
 * represents a task. The files are named using microtime so that they list in
 * the order they are added. The contents of the file are the details of the
 * task. We expect task data to take the form of 'type,id' (e.g. change,54321).
 *
 * Workers process tasks in the queue. They are invoked via the worker action
 * of the index controller. It is expected that you will setup a cronjob to
 * kick off a worker periodically (e.g. every minute). A limited number of
 * workers can run at a time (3 by default). When a worker starts it tries to
 * grab a slot - each slot is a lock file. If no slots are open, the worker
 * shuts down. The cron and the slots together ensure that we are always trying
 * to process tasks in the queue, but we don't exceed the max worker setting.
 */
class Manager implements InvokableService
{
    const   DEFAULT_WORKERS      = 3;
    const   DEFAULT_LIFETIME     = 600;  // 10 minutes
    const   DEFAULT_TASK_TIMEOUT = 1800; // 30 minutes
    const   DEFAULT_MEMORY_LIMIT = '1G';
    const   FUTURE_TASKS         = 'futureTasks';
    const   CURRENT_TASKS        = 'currentTasks';
    const   ALL_TASKS            = 'allTasks';
    const   SERVICE              = 'queue';

    const UNABLE_TO_CREATE_DIRECTORY = 'Unable to create workers directory.';

    protected $config   = null;
    protected $handles  = [];
    protected $events   = null;
    protected $services = null;


    /**
     * @inheritdoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $fullConfig     = $services->get(ConfigManager::CONFIG);
        $config         = $fullConfig[ConfigManager::QUEUE] ?? null;
        $this->config   = $config + [
                ConfigManager::PATH                         => DATA_PATH . '/queue',
                ConfigManager::WORKERS                      => static::DEFAULT_WORKERS,
                ConfigManager::WORKER_LIFETIME              => static::DEFAULT_LIFETIME,
                ConfigManager::WORKER_TASK_TIMEOUT          => static::DEFAULT_TASK_TIMEOUT,
                ConfigManager::WORKER_MEMORY_LIMIT          => static::DEFAULT_MEMORY_LIMIT,
                ConfigManager::DISABLE_TRIGGER_DIAGNOSTICS  => false
            ];
    }

    /**
     * Get the queue config.
     *
     * @return  array   normalized queue config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Attempt to get a worker slot. We limit the active workers via flock.
     *
     * @return  int|false   the slot number or false if no open slots.
     * @throws \Exception
     */
    public function getWorkerSlot()
    {
        $config = $this->getConfig();
        $path   = $config[ConfigManager::PATH] . '/workers';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \Exception(self::UNABLE_TO_CREATE_DIRECTORY);
        }
        for ($slot = 1; $slot <= (int) $config[ConfigManager::WORKERS]; $slot++) {
            $file = fopen($path . '/' . $slot, 'c');
            $lock = flock($file, LOCK_EX | LOCK_NB);
            if ($lock) {
                // don't let handle fall out of scope or the lock will release.
                $this->handles[$slot] = $file;
                return $slot;
            }
        }

        return false;
    }

    /**
     * Verifies we still have the specified worker slot.
     * If we never had the slot or have released it this returns false.
     * Further, if we had the slot but someone has deleted the worker lock this returns false.
     *
     * @param   int     $slot   the slot to test
     * @return  bool    true if we have the slot locked; false otherwise
     */
    public function hasWorkerSlot($slot)
    {
        // if we don't have a lock, we don't have the slot
        if (!ctype_digit((string) $slot) || !isset($this->handles[$slot]) || !is_resource($this->handles[$slot])) {
            return false;
        }

        // figure out the path to the specified lock
        $config = $this->getConfig();
        $path   = $config[ConfigManager::PATH] . '/workers';
        $file   = $path . '/' . $slot;

        // we don't want caching to burn us, clear the stat cache for this item
        clearstatcache(true, $file);

        // we think we have a lock so status is based on file existence
        return file_exists($file);
    }

    /**
     * Release the lock held on the given worker slot. By default, only releases
     * slots we hold. If force is specified even locks held by someone else will
     * be marked for release by deleting the file.
     *
     * @param   int     $slot   the slot number to release
     * @param   bool    $force  optional, if true release even if we don't hold the slot
     * @return  bool    true if we released the slot, false otherwise
     */
    public function releaseWorkerSlot($slot, $force = false)
    {
        if (!isset($this->handles[$slot]) || !is_resource($this->handles[$slot])) {
            // no lock and no force == no go
            if (!$force) {
                return false;
            }

            // figure out the path to the specified lock
            $config   = $this->getConfig();
            $path     = $config[ConfigManager::PATH] . '/workers';
            $filePath = $path.'/'.$slot;
            $file     = fopen($filePath, 'c');


            // if the file opens ok but we cannot get a lock, release it by unlinking
            if ($file && !flock($file, LOCK_EX | LOCK_NB)) {
                unlink($filePath);
                return true;
            }

            // looks like the file didn't exist or wasn't locked, return failure
            return false;
        }

        // this is one of our locked slots, simply unlock it
        flock($this->handles[$slot], LOCK_UN);
        fclose($this->handles[$slot]);
        unset($this->handles[$slot]);

        return true;
    }

    /**
     * Grab a task from the queue. To avoid multiple workers processing the
     * same task, we lock it first, then read its data and remove it.
     * @return array|bool|false an array containing the task file (name), time, type, id and data
     *                          or false if there are no tasks to grab.
     */
    public function grabTask()
    {
        $config = $this->getConfig();
        if (file_exists($config[ConfigManager::PATH])) {
            $entries = scandir($config[ConfigManager::PATH]);
            foreach ($entries as $entry) {
                // only consider files that look right
                $entry = $config[ConfigManager::PATH] . '/' . $entry;
                if (!$this->isTaskFile($entry)) {
                    continue;
                }

                // ignore 'future' tasks (time > now)
                if ($this->isFutureTask($entry)) {
                    continue;
                }

                // is this one up for grabs - can we lock it?
                // even if we lock it, we need to double check it exists
                // otherwise it could be recently consumed by another worker.
                $file = @fopen($entry, 'r');
                $lock = $file && flock($file, LOCK_EX | LOCK_NB);
                clearstatcache(false, $entry);
                if ($lock && is_file($entry)) {
                    // got it, consume and destroy!
                    $task = $this->parseTaskFile($entry, $file);

                    // don't process zombie tasks (ones we are unable to delete)
                    // as we'd just spin processing them forever
                    if (!unlink($entry)) {
                        throw new \RuntimeException(
                            "Non-deletable task encountered $file, " .
                            "please fix file permissions to continue task processing."
                        );
                    }

                    // release our lock and close the file handle
                    flock($file, LOCK_UN);
                    fclose($file);

                    if ($task) {
                        return $task;
                    }
                }
            }
        }
        // no tasks for us.
        return false;
    }

    /**
     * Deletes all task files that have the given hash as part of their name. Some task files
     * are created with a hash to identify them without having to parse the contents.
     * For example
     * '1522144223.0000.74f2ccdafe7e578bab22e59d37db9a01.0' where '74f2ccdafe7e578bab22e59d37db9a01' is the hash.
     * @param $hash string search for this hash in the file name and delete all that match.
     * @return array list of deleted file names
     */
    public function deleteTasksByHash($hash)
    {
        $deleted = [];
        $config  = $this->getConfig();
        if (file_exists($config[ConfigManager::PATH])) {
            $entries = scandir($config[ConfigManager::PATH]);
            foreach ($entries as $entry) {
                $entry = $config[ConfigManager::PATH] . '/' . $entry;
                if ($this->isTaskFileWithHash($entry, $hash)) {
                    // is this one up for grabs - can we lock it?
                    // even if we lock it, we need to double check it exists
                    // otherwise it could be recently consumed by another worker.
                    $file = @fopen($entry, 'r');
                    $lock = $file && flock($file, LOCK_EX | LOCK_NB);
                    clearstatcache(false, $entry);
                    if ($lock && is_file($entry)) {
                        // got it, consume and destroy!
                        // don't process zombie tasks (ones we are unable to delete)
                        // as we'd just spin processing them forever
                        if (!unlink($entry)) {
                            throw new \RuntimeException(
                                "Non-deletable task encountered $file, " .
                                "please fix file permissions to continue task processing."
                            );
                        }
                        // release our lock and close the file handle
                        flock($file, LOCK_UN);
                        fclose($file);
                        $deleted[] = $entry;
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * Get the list of all future tasks by hash
     *
     * @param string $hash search for this hash in the files name and return a list.
     * @return array
     */
    public function getTasksByHash($hash)
    {
        $tasks  = [];
        $config = $this->getConfig();
        if (file_exists($config[ConfigManager::PATH])) {
            $entries = scandir($config[ConfigManager::PATH]);
            foreach ($entries as $entry) {
                $entry = $config[ConfigManager::PATH] . '/' . $entry;
                if ($this->isTaskFileWithHash($entry, $hash)) {
                    $tasks[] = $entry;
                }
            }
        }
        return $tasks;
    }

    /**
     * Do we have a task by Hash.
     *
     * @param string $hash search for this hash in the files name
     * @return bool
     */
    public function hasTaskByHash($hash)
    {
        $config = $this->getConfig();
        if (file_exists($config[ConfigManager::PATH])) {
            $entries = scandir($config[ConfigManager::PATH]);
            foreach ($entries as $entry) {
                $entry = $config[ConfigManager::PATH] . '/' . $entry;
                if ($this->isTaskFileWithHash($entry, $hash)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Read a task file and return an array representation of it
     * @param mixed         $file       file path to read, will be opened if handle is not already available
     * @param mixed         $handle     handle to a file
     * @return array of task data
     * @throws QueueException with an appropriate message if the following issues occur
     *      - handle is null and the file path cannot be opened
     *      - the type of task cannot be determined from the info line (for example 'shelve,1234')
     *      - there is a payload after the info line that cannot be parsed as valid JSON
     */
    private function readTaskFile($file, $handle)
    {
        $data = [];
        $info = null;
        $type = null;
        if (!$handle) {
            $handle = @fopen($file, 'r');
            if (!$handle) {
                // Cannot open the file
                QueueException::fileError($file);
            }
        }
        // First line is type,id the rest is (optionally) json
        // we limit the first line to 1KB
        $header = fgets($handle, 1024);
        $json   = '';
        while (!feof($handle)) {
            $json .= fread($handle, 1024 * 8);
        }
        $info = explode(',', trim($header), 2);
        $type = isset($info[0]) ? $info[0] : null;
        if ($type) {
            $json = trim($json);
            if ($json) {
                $json = json_decode($json, true);
                if ($json === null) {
                    // Payload not valid JSON
                    QueueException::parseError($header, QueueException::INVALID_DATA);
                }
                $data = $json;
            }
        } else {
            // Info line does not describe a type
            QueueException::parseError($header, QueueException::INVALID_INFO);
        }
        return [
            'file' => $file,
            'time' => (int) ltrim(substr(basename($file), 0, -7), 0),
            'type' => $type,
            'id'   => isset($info[1]) ? $info[1] : null,
            'data' => $data
        ];
    }

    /**
     * Parse the contents of a task file.
     * We're expecting data in the form of: 'type,id[\n{JSON}]'
     *
     * @param   string          $file       the name of the task file
     * @param   resource        $handle     optional - a file handle to use.
     * @return  false|array     an array containing the task file (name), time, type, id and data
     *                          or false if the file could not be parsed.
     */
    public function parseTaskFile($file, $handle = null)
    {
        try {
            return $this->readTaskFile($file, $handle);
        } catch (QueueException $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err($e->getMessage());
            return false;
        }
    }

    /**
     * Add a task to the queue.
     *
     * @param   string          $type   the type of task to process (e.g. 'change')
     * @param   string|int      $id     the relevant identifier (e.g. '12345')
     * @param   array|null      $data   optional - additional task details
     * @param   int|float|null  $time   influence name of queue file (defaults to microtime)
     *                                  future tasks (time > now) aren't grabbed until time <= now
     * @param null $hash        optional hash value to include as part of the file name
     * @return  bool            true if queued successfully, false otherwise
     */
    public function addTask($type, $id, array $data = null, $time = null, $hash = null)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $time   = $time ?: microtime(true);
        $config = $this->getConfig();
        if (!is_dir($config[ConfigManager::PATH])) {
            mkdir($config[ConfigManager::PATH], 0700);
        }

        // 1000 attempts to get a unique filename.
        // @codingStandardsIgnoreStart
        $path = $config[ConfigManager::PATH] . '/' . sprintf('%015.4F', $time) . ($hash ? '.' . $hash . '.' : '.');
        for ($i = 0; $i < 1000 && !($file = @fopen($path . $i, 'x')); $i++);
        // @codingStandardsIgnoreEnd

        if ($file) {
            // contents take the form of type,id[\n{JSON}]
            fwrite($file, $type . "," . $id . ($data ? "\n" . json_encode($data) : ""));
            fclose($file);
            $logger->notice("Adding Task: ". $type . " for ID ". $id . " into file ". $path . $i);
            $logger->trace($type. " data: ".  var_export($data, true));
            return true;
        }
        $logger->warn("Failed to add Task: ". $type . " for ID ". $id . " into file queue");

        return false;
    }

    /**
     * Get a count of the active workers.
     *
     * @return  int     the number of active workers (locked slots)
     */
    public function getWorkerCount()
    {
        $workers = 0;
        $config  = $this->getConfig();
        $path    = $config[ConfigManager::PATH] . '/workers';
        $dir     = is_dir($path) ? opendir($path) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            // workers are purely numeric files
            $entry = $path . '/' . $entry;
            if (!preg_match('/\/[0-9]+$/', $entry) || !is_file($entry)) {
                continue;
            }

            // active workers lock their file.
            $file = fopen($entry, 'r');
            $lock = flock($file, LOCK_EX | LOCK_NB);
            if (!$lock) {
                $workers++;
            }
        }
        return $workers;
    }

    /**
     * Get a count of queued tasks.
     *
     * @return  int     the number of tasks in the queue.
     */
    public function getTaskCount()
    {
        $counts = $this->getTaskCounts();
        return $counts['total'];
    }

    /**
     * Get a count of queued tasks broken out into current, future and total.
     *
     * @return  array   task counts (current/future/total)
     */
    public function getTaskCounts($excludeFuture = false)
    {
        $counts = ['current' => 0, 'future' => 0, 'total' => 0];
        $config = $this->getConfig();
        $dir    = is_dir($config[ConfigManager::PATH]) ? opendir($config[ConfigManager::PATH]) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            if ($this->isTaskFile($config[ConfigManager::PATH] . '/' . $entry)) {
                $counts['current'] += $this->isFutureTask($entry) ? 0 : 1;
                $counts['future']  += $this->isFutureTask($entry) ? 1 : 0;
                $counts['total']++;
            }
        }

        return $counts;
    }

    /**
     * Get a list of queued task files.
     *
     * @return  array   list of task filenames (absolute paths).
     */
    public function getTaskFiles()
    {
        $tasks  = [];
        $config = $this->getConfig();
        $dir    = is_dir($config[ConfigManager::PATH]) ? opendir($config[ConfigManager::PATH]) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            $entry = $config[ConfigManager::PATH] . '/' . $entry;
            if ($this->isTaskFile($entry)) {
                $tasks[] = $entry;
            }
        }

        return $tasks;
    }

    /**
     * Get the event manager for triggering or attaching to events
     * such as task.change, worker.startup, etc.
     *
     * @return EventManager the event manager instance.
     */
    public function getEventManager()
    {
        $this->events = $this->events ?: new EventManager;

        return $this->events;
    }

    /**
     * Returns all queue tokens defined for this swarm install.
     * Normally we anticipate only having one token.
     *
     * If there are no existing tokens one be automatically added
     * and the value returned.
     *
     * @return  array   the queue token(s) defined for this instance
     */
    public function getTokens()
    {
        // ensure the token folder exists
        $config = $this->getConfig();
        $path   = $config[ConfigManager::PATH] . '/tokens';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        // get all files/tokens under the path
        $tokens = [];
        $handle = opendir($path);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open queue tokens path '$path'. Check file permissions.");
        }
        while (false !== ($entry = readdir($handle))) {
            if (is_file($path . '/' . $entry)) {
                $tokens[] = $entry;
            }
        }

        // if we couldn't find an existing token lets make one
        if (!$tokens) {
            $token = strtoupper(new Uuid);
            touch($path . '/' . $token);
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Get the future tasks.
     *
     * @return array the tasks
     */
    public function getFutureTasks()
    {
        return self::getTasks(self::FUTURE_TASKS);
    }

    /**
     * Get the current tasks.
     * @return array the tasks
     */
    public function getCurrentTasks()
    {
        return self::getTasks(self::CURRENT_TASKS);
    }

    /**
     * Get all tasks or requested tasks.
     *
     * @param string $type
     *
     * @return array the tasks
     */
    public function getTasks($type = self::ALL_TASKS)
    {
        $tasks  = [];
        $config = $this->getConfig();
        $dir    = is_dir($config[ConfigManager::PATH]) ? opendir($config[ConfigManager::PATH]) : null;
        while ($dir && ($entry = readdir($dir)) !== false) {
            if ($this->isTaskFile($config[ConfigManager::PATH] . '/' . $entry)) {
                if ($type === self::CURRENT_TASKS && !$this->isFutureTask($entry)) {
                    $tasks[self::CURRENT_TASKS][$entry] = $this->parseTaskFile(
                        $config[ConfigManager::PATH]. '/' .$entry
                    );
                } elseif ($type === self::FUTURE_TASKS && $this->isFutureTask($entry)) {
                    $tasks[self::FUTURE_TASKS][$entry] = $this->parseTaskFile(
                        $config[ConfigManager::PATH] . '/' .$entry
                    );
                } elseif ($type === self::ALL_TASKS) {
                    $queueType                 = $this->isFutureTask($entry) ? self::FUTURE_TASKS : self::CURRENT_TASKS;
                    $tasks[$queueType][$entry] = $this->parseTaskFile($config[ConfigManager::PATH] . '/' .$entry);
                }
            }
        }

        return $tasks;
    }

    /**
     * Shutdown the workers
     *
     * @return bool
     */
    public function restartWorkers()
    {
        $returnValue = false;
        $slot        = $this->getWorkerCount();
        for ($i = 1; $i <= $slot; $i++) {
            $returnValue = $this->releaseWorkerSlot($i, true);
        }
        return $returnValue;
    }

    /**
     * Works out if the provided file name contains the hash being looked for.
     * @param $file string the file name
     * @param $hash string the hash being looked for
     * @return bool true if the hash is part of the file name
     */
    protected function isTaskFileWithHash($file, $hash)
    {
        $matches = [];
        preg_match('/\/[0-9]{10}\.[0-9]{4}\.([0-9,a-f]{32})\.[0-9]{0,4}$/', $file, $matches);

        return count($matches) > 1 && isset($matches[1]) && $matches[1]=== $hash && is_file($file);
    }

    /**
     * Check if the given file name looks like a task file
     * (e.g. 1355957340.2225.*)
     *
     * @param   string  $file   the file to check
     * @return  bool    true if file looks like a task
     */
    protected function isTaskFile($file)
    {
        return (bool) preg_match('/\/[0-9]{10}\.[0-9]{4}\.[0-9]{0,4}.*$/', $file)
            && is_file($file);
    }

    /**
     * Check if the file name represents a future task
     *
     * @param   string  $file   the file to check
     * @return  bool    true if file's timestamp is in the future
     */
    protected function isFutureTask($file)
    {
        // we use microtime() instead of time() here because sometimes they disagree.
        $time = (int) ltrim(substr(basename($file), 0, -7), 0);
        return $time > (int) microtime(true);
    }
}
