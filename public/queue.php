<?php
/**
 * Very simple queuing system.
 *
 * This is intentionally simple to be fast. We want to queue events
 * quickly so as not to slow down the client (ie. the Perforce Server).
 *
 * To add something to the queue, POST to this script. Up to 1024kB
 * of raw post data will be written to a file in the queue folder.
 * No assumptions are made about the nature of the data (at least not
 * by this script).
 *
 * Each file in the queue is named for the current microtime. It is
 * possible to get collisions under high load or if time moves backward.
 * Therefore, we make 1000 attempts to get a unique name by incrementing
 * a trailing number.
 */

// base data path can come from three possible locations:
// 1) if a path is passed as the first cli argument, it will be used
// 2) otherwise, if the SWARM_DATA_PATH environment variable is set, it will be used
// 3) otherwise, we'll go up a folder from this script then into data
$basePath    = getenv('SWARM_DATA_PATH')
    ? (rtrim(getenv('SWARM_DATA_PATH'), '/\\'))
    : (__DIR__ . '/../data');
$basePath    = isset($argv[1]) ? $argv[1] : $basePath;
$logPriority = null;
$server      = isset($_GET['server']) ? $_GET['server'] : null;
$logMessage  = function($msg, $msgPriority = 8) use ($basePath, &$logPriority, $server){
    if(!$logPriority) {
        $logPriority = 3; // Same default as config
        // First message gets config
        $configLocation = $basePath . '/config.php';
        $fullConfig     = file_exists($configLocation) ? include $configLocation : array();
        if ($fullConfig && isset($fullConfig['log']['priority'])) {
            $logPriority = $fullConfig['log']['priority'];
        }
    }
    if($msgPriority <= $logPriority) {
        $timestamp   = new DateTime(ini_get('date.timezone')?:'UTC');
        $logLocation = $server ? "$basePath/servers/$server/log" : "$basePath/log";
        error_log($timestamp->format("c:($msgPriority) "). "Swarm/Queue: $msg\n", 3, $logLocation);
    }
};
$logMessage("Adding a task to the queue");

// the queue path is typically data-path/queue, however, when a server-id
// is passed in it becomes base-path/servers/server/queue.
$path = $basePath . '/queue';
if ($server) {
    require_once __DIR__ . '/../module/Application/SwarmFunctions.php';
    $servers = \Application\SwarmFunctions::getMultiServerConfiguration($basePath);

    if (preg_match('/[^a-z0-9_-]/i', $server)
        || !array_key_exists($server, $servers)
        || !is_dir($basePath . '/servers/' . $server)
    ) {
        queueError(
            404,
            'Not Found',
            'Invalid Perforce Server identifier. Check Swarm configuration file for valid servers.',
            'queue/add attempted with invalid p4 server: "' . $server . '"',
            $logMessage
        );
    }
    $path = $basePath . '/servers/' . $server . '/queue';
}

// bail if we didn't get a valid auth token - can be passed as
// second arg for testing, normally passed via get param
$token = isset($argv[2]) ? $argv[2] : null;
$token = $token ?: (isset($_GET['token']) ? $_GET['token'] : null);
$token = preg_replace('/[^a-z0-9\-]/i', '', $token);
$logMessage("Token is [$token]");
if (!strlen($token) || !file_exists($path . '/tokens/' . $token)) {
    queueError(
        401,
        'Unauthorized',
        'Missing or invalid token. View "About Swarm" as a super user for a list of valid tokens.',
        'queue/add attempted with invalid/missing token: "' . $token . '"',
        $logMessage
    );
}
$path = $path . '/' . sprintf('%015.4F', microtime(true)) . '.';
// Limit in bytes for the file size to write, 10MB as a default if not provided
$limit = isset($argv[3]) ? $argv[3] : (10 * 1024 * 1024);
// write up to limit bytes of input.
// takes from stdin when CLI invoked for testing.
$input = fopen(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', 'r');
// When reading remote files fread will stop at 8K packet size regardless of the
// read limit given (default 10MB) so we need to read a loop and determine how many
// 8K packets are read to work out when the limit is exceeded
$packetSize  = 1024 * 8;
if ($limit <= $packetSize) {
    $packetLimit = 1;
    $packetSize  = $limit;
} else {
    $packetLimit = floor($limit / $packetSize);
    $packetLimit = $packetLimit === 0 ? 1 : $packetLimit;
}
$packets = 0;
$content = '';
$valid   = true;
while (!feof($input)) {
    $content .= fread($input, $packetSize);
    $packets++;
    // feof will always require 1 extra loop to test for
    // end of file after a read so as long as packets read
    // is not greater than the limit + 1 we know we haven't
    // read past our size limit
    if ($packets > $packetLimit + 1) {
        $valid = false;
        break;
    }
}
// Get the first 128 characters of content so we can give an indication of the task
// in any success or error message
$taskSummary = substr($content, 0, 128);
if ($valid) {
    // Only create a file if we have read valid data
    // 1000 attempts to get a unique filename.
    for ($i = 0; $i < 1000 && !($file = @fopen($path . $i, 'x')); $i++);
    if ($file) {
        fwrite($file, $content);
        $logMessage("Written content starting with [$taskSummary]");
    } else {
        $logMessage("Could not generate unique file name for content starting with [$taskSummary]", 3);
    }
} else {
    $logMessage(
        "Task data beginning with [$taskSummary] exceeded limit of [$limit], " .
        "a task file has not been created", 3);
}

$logMessage("Finished");

function queueError($code, $status, $message, $log, $debugger) {

    header('HTTP/1.0 ' . $code . ' ' . $status, true, $code);
    echo htmlspecialchars($message) . "\n";

    // try and get this failure into the logs to assist diagnostics
    // don't display the triggered error to the user, we've already done that bit
    call_user_func($debugger, $message.' '.$log, 3);
    ini_set('display_errors', 0);
    trigger_error($log, E_USER_ERROR);

    exit;
}