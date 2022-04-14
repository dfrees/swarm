<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Xhprof\Listener;

use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

class XhprofListener extends AbstractEventListener
{
    /**
     * Only attach if xhprof is enabled
     * @param mixed $eventName the event name
     * @param array $eventDetail the event detail
     * @return bool
     */
    protected function shouldAttach($eventName, $eventDetail)
    {
        return extension_loaded('xhprof') && !$this->services->get('Application')->getRequest()->isTest;
    }

    public function handleRouteEvent(Event $event)
    {
        $config        = $this->services->get('config');
        $routeMatch    = $event->getRouteMatch();
        $seconds       = $config['xhprof']['slow_time'];
        $ignoredRoutes = $config['xhprof']['ignored_routes'];

        // if current route is ignored: halt profiling, discard output, do not register shutdown handler
        if (in_array($routeMatch->getMatchedRouteName(), $ignoredRoutes)) {
            xhprof_disable();
            return;
        }

        register_shutdown_function([$this, 'shutdownHandler'], $seconds);
    }

    public function handleWorkerShutdown(Event $event)
    {
        // only run for the first worker.
        if ($event->getParam('slot') !== 1) {
            return;
        }

        $path = DATA_PATH . '/xhprof';

        if (!is_dir($path)) {
            return;
        }
        $config = $this->services->get('config');
        // delete xhprof files older than 'report_file_lifetime' (default: 1 week)
        $files  = glob($path . '/*.swarm.xhprof');
        $errors = [];
        foreach ($files as $file) {
            if (filemtime($file) < time() - $config['report_file_lifetime']) {
                @unlink($file);
                if (file_exists($file)) {
                    $errors[] = $file;
                }
            }
        }

        if ($errors) {
            $message = 'Unable to clean up ' . count($errors) . ' stale xhprof file(s). '
                . 'Please verify that Swarm has write permission on ' . $path . '. '
                . (count($errors) > 5 ? 'Some of the affected files: ' : 'Affected file(s): ')
                . implode(', ', array_slice($errors, 0, 5));
            $this->services->get('logger')->err($message);
        }
    }

    /**
     * Shutdown handler for writing profile information on exit.
     *
     * @param int $seconds only write profiling information when runtime > $seconds
     */
    public function shutdownHandler($seconds)
    {
        // return if xhprof is not loaded (nothing to do)
        if (!extension_loaded('xhprof')) {
            return;
        }

        $data = (array) xhprof_disable();

        // skip writing if $data is empty or malformed; log findings
        if (!$data || !isset($data['main()']['wt'])) {
            $this->services->get('logger')->err('Discarding unexpected result from xhprof_disable()');
            return;
        }

        $totalTime = $data['main()']['wt'];
        $slowTime  = $seconds * 1000 * 1000;

        // capture executions longer than $seconds (converted to microseconds)
        if ($totalTime > $slowTime) {
            $path = DATA_PATH . '/xhprof';
            $file = $path . '/' . uniqid() . '.swarm.xhprof';

            // ensure cache dir exists and is writable
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                @chmod($path, 0755);
            }

            // if the path is unwritable, there's nothing to do
            if (!is_dir($path) || !is_writable($path)) {
                $this->services->get('logger')->err('Unable to write to directory ' . $path);
                return;
            }

            $extra = array_intersect_key(
                $_SERVER,
                array_flip(
                    [
                        'REQUEST_URI',
                        'QUERY_STRING',
                        'HTTP_REFERER',
                        'HTTP_USER_AGENT',
                    ]
                )
            );

            $extra['timestamp']      = time();
            $data['main()']['extra'] = $extra;

            file_put_contents($file, serialize($data));
        }
    }
}
