<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

// enable profiling if xhprof is present
extension_loaded('xhprof') && xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

use Application\Model\ServicesModelTrait;
use Laminas\Mvc\Application;
use Laminas\Stdlib\ArrayUtils;

// Minimum required year and dot release for P4PHP
const MIN_P4PHP_YEAR    = 2018;
const MIN_P4PHP_VERSION = 2;
const APPLICATION_JSON  = "application/json";
const MIN_PHP_VERSION   = 702000;
// String for password incorrect.
const PASSWORD_INVALID_OR_UNSET = 'Command failed: Perforce password (P4PASSWD) invalid or unset.';

define('BASE_PATH', dirname(__DIR__));

// allow BASE_DATA_PATH to be overridden via an environment variable
define(
    'BASE_DATA_PATH',
    getenv('SWARM_DATA_PATH') ? rtrim(getenv('SWARM_DATA_PATH'), '/\\') : BASE_PATH . '/data'
);
require_once __DIR__ . '/../vendor/onelogin/php-saml/_toolkit_loader.php';

// Try to run the application and catch the errors and run the sanityCheck on the errors.
try {
    // detect a multi-p4-server setup and select which one to use
    require_once __DIR__ . '/../module/Application/SwarmFunctions.php';
    \Application\SwarmFunctions::configureEnvironment(BASE_DATA_PATH);

    // Composer autoloading
    include __DIR__ . '/../vendor/autoload.php';

    if (! class_exists(Application::class)) {
        throw new RuntimeException(
            "Unable to load application.\n"
            . "- Type `composer install` if you are developing locally.\n"
        );
    }

    // ensure strict and notice is disabled; otherwise keep the existing levels
    error_reporting(error_reporting() & ~(E_STRICT | E_NOTICE));
    checkP4PHP();

    $appConfig = require __DIR__ . '/../config/application.config.php';
    if (isDevelopmentMode(BASE_DATA_PATH) && file_exists(__DIR__ . '/../config/development.config.php')) {
        $appConfig = ArrayUtils::merge($appConfig, require __DIR__ . '/../config/development.config.php');
    }

    // ensure that before running the application, cache directory must be exists
    if (!is_dir($appConfig['module_listener_options']['cache_dir'])) {
        @mkdir($appConfig['module_listener_options']['cache_dir'], 0755, true);
    }

    // configure and run the application
    $app = Application::init($appConfig);
    // For now provide a global access to services - this must be done before calling run
    ServicesModelTrait::setServices($app->getServiceManager());
    $app->run();

    // If we catch any parse or exception errors check to see if we can help advise user what might be the cause.
} catch (ParseError $e) {
    reportErrors(new ErrorException($e->getMessage(), $e->getCode(), E_PARSE, $e->getFile(), $e->getLine()));
} catch (Exception $e) {
    reportErrors($e);
}

/**
 * Checks that the P4PHP library is installed and that it is at least MIN_P4PHP_YEAR.MIN_P4PHP_VERSION
 * @throws \P4\Environment\Exception\VersionException
 */
function checkP4PHP()
{
    if (!extension_loaded('perforce')) {
        throw new \P4\Environment\Exception\VersionException(
            'The Perforce PHP extension (P4PHP) is not installed or enabled.'
        );
    } else {
        preg_match_all('/(P4PHP\/.[^\/]*.([^\/]*).[^\/\s]*)/i', substr(P4::identify(), 0), $p4phpVersion);
        $p4phpYear    = intval(substr($p4phpVersion[2][0], 0, 4));
        $p4phpRelease = intval(substr($p4phpVersion[2][0], 5, 6));
        if ($p4phpYear < MIN_P4PHP_YEAR ||
            ($p4phpYear == MIN_P4PHP_YEAR && $p4phpRelease < MIN_P4PHP_VERSION)) {
            throw new \P4\Environment\Exception\VersionException(
                "The Perforce PHP extension (P4PHP) requires upgrading.\n" .
                "Found $p4phpYear.$p4phpRelease, only " .
                MIN_P4PHP_YEAR . '.' . MIN_P4PHP_VERSION . " or later is supported."
            );
        }
    }
}

/**
 * Check if we are running in development mode.
 * @param string $basePath This is the location to the directory the config exist.
 * @return bool
 */
function isDevelopmentMode($basePath)
{
    $config = $basePath . '/config.php';
    $config = file_exists($config) ? include $config : null;
    $mode   = getConfigValue($config, array('environment', 'mode'));
    return $mode === 'development' ? true : false;
}

/**
 * Do what we can to report what we can detect might be misconfigured
 * @param Exception $error
 */
function reportErrors(Exception $error)
{
    $e = 'htmlspecialchars';

    // if we are in a multi-p4-server setup, the data path might need to be created
    $badP4dId = preg_match('/[^a-z0-9_-]/i', P4_SERVER_ID);
    if (!$badP4dId && !is_dir(DATA_PATH)) {
        @mkdir(DATA_PATH, 0700, true);
    }

    // check what could be misconfigured
    $config       = BASE_DATA_PATH . '/config.php';
    $badPhp       = (!defined('PHP_VERSION_ID') || (PHP_VERSION_ID < MIN_PHP_VERSION));
    $versionIssue = $error && $error instanceof \P4\Environment\Exception\VersionException;
    $passwdIssue  = $error && $error instanceof \P4\Connection\Exception\CommandException;
    $noIconv      = !extension_loaded('iconv');
    $noJson       = !extension_loaded('json');
    $noSession    = !extension_loaded('session');
    $noRedis      = !extension_loaded('redis');
    $numPhpIssues = $badPhp + $noIconv + $noJson + $noSession + $versionIssue + $noRedis;
    $badDataDir   = !$badP4dId && !is_writeable(DATA_PATH);
    $noConfig     = !file_exists($config);
    $configErrors = $noConfig ? array() : (array) checkConfig($config);
    $threadSafe   = defined('ZEND_THREAD_SAFE') ? ZEND_THREAD_SAFE : false;
    $numIssues    = $numPhpIssues + $badDataDir + $noConfig + $threadSafe + $badP4dId + count($configErrors);

    // if anything is misconfigured, compose error page and then die
    if ($numIssues) {
        $html = '<html><body>'
            . '<h1>Swarm has detected a configuration error</h1>'
            . '<p>Problem' . ($numIssues > 1 ? 's' : '') . ' detected:</p>';

        // compose message per condition
        $html                  .= '<ul>';
        $badPhp       && $html .= '<li>Swarm requires PHP 7.2 or higher; you have ' . $e(PHP_VERSION) . '.</li>';
        $versionIssue && $html .= '<li>' . $error->getMessage() . '</li>';
        $noIconv      && $html .= '<li>The iconv PHP extension is not installed or enabled.</li>';
        $noJson       && $html .= '<li>The json PHP extension is not installed or enabled.</li>';
        $noSession    && $html .= '<li>The session PHP extension is not installed or enabled.</li>';
        $noRedis      && $html .= '<li>The Redis PHP extension is not installed or enabled.</li>';
        $badDataDir   && $html .= '<li>The data directory (' . $e(DATA_PATH) . ') is not writeable.</li>';
        $noConfig     && $html .= '<li>Swarm configuration file does not exist (' . $e($config) . ').</li>';
        $threadSafe   && $html .= '<li>Thread-safe PHP detected -- Swarm does not support running with thread-safe PHP.'
            . ' To remedy, install or rebuild a non-thread-safe variant of PHP and Apache (prefork).</li>';
        $badP4dId     && $html .= '<li>The Perforce server name (' . $e(P4_SERVER_ID) . ') contains invalid characters.'
            . ' Perforce server names may only contain alphanumeric characters, hyphens and underscores.</li>';
        $configErrors && $html .= '<li>' . implode('</li></li>', $configErrors) . '</li>';
        $html                  .= '</ul>';

        // display further information if there were any PHP issues
        if ($numPhpIssues) {
            // tell the user where the php.ini file is
            $php_ini_file = php_ini_loaded_file();
            if ($php_ini_file) {
                $html .= '<p>The php.ini file loaded is ' . $e($php_ini_file) . '.</p>';
            } else {
                $html .= '<p>There is no php.ini loaded (expected to find one in ' . $e(PHP_SYSCONFDIR) . ').</p>';
            }

            // if there are additional php.ini files, list them here
            if (php_ini_scanned_files()) {
                $html .= '<p>Other scanned php.ini files (in ' . $e(PHP_CONFIG_FILE_SCAN_DIR) . ') include:</p>'
                    . '<ul><li>' . implode('</li><li>', explode(",\n", $e(php_ini_scanned_files()))) . '</li></ul>';
            }
        }

        // Get the url
        $url = getURL($configErrors);

        // Check if there are any other errors that we could help output to the end user.
        if ($error != null) {
            $html .= '<p>Please investigate the below PHP error below:</p>'
            . '<pre>' . $error->getMessage() . '</pre>'
            . '<p>'.$error->getFile() . ' on line ' . $error->getLine() . '</p>';
        }

        // wrap it up with links to the docs
        $html .= '<p>For more information, please see the'
            . ' <a href="' . $url . 'Content/Swarm/chapter.setup.html ">Install and upgrade Swarm</a> documentation;'
            . ' in particular:</p>'
            . '<ul>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.dependencies.html">Runtime dependencies</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.installation.html">Installation</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup_php.html">PHP configuration</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.swarm.html">Swarm configuration</a></li>'
            . '</ul>'
            . '<p>If you are using SELinux: check it is configured correctly, see '
            . '<a href="' . $url . 'Content/Swarm/setup.dependencies.html#Security-enhanced_Linux_(SELinux)">'
            . ' Security-enhanced Linux (SELinux)</a>.</p>'
            . '<p>Please restart your web server after making any PHP changes.</p>'
            . '<p>If you change your configuration you must delete your Swarm config cache, see '
            . '<a href="' . $url . 'Content/Swarm/swarm-apidoc_endpoint_config_cache.html">'
            . 'Swarm config cache file delete</a>.</p>'
            . '</body></html>';

        // goodbye cruel world
        returnData($html, $error);
    } elseif ($passwdIssue && strpos($error->getMessage(), PASSWORD_INVALID_OR_UNSET) == false) {
        $url  = getURL();
        $html = '<html><body>'
            . '<h1>Swarm has detected a configuration error</h1>'
            . '<p>If you have changed your configuration, delete your Swarm config cache to rebuild it, see '
            . '<a href="' . $url . 'Content/Swarm/swarm-apidoc_endpoint_config_cache.html">'
            . 'Swarm config cache file delete</a>. '
            . 'If this API does not work, you will need to manually remove the config cache file with the command: </p>'
            . '<blockquote>rm -f ' . DATA_PATH . '/cache/module-config-cache.php</blockquote>'
            . '<p>Be aware, if your Helix Server is running at security level 3 or above, you must use a valid '
            . 'long lived ticket instead of a password '
            . '<a href="' . $url . 'Content/Swarm/setup.swarm.html#password">'
            . 'Swarm configuration file password</a>. </p>'
            . '<p>If your ticket has expired, obtain a new ticket by using the following command on your'
            . ' Swarm instance:'
            . '<blockquote>p4 login -p</blockquote></p>';

        // wrap it up with links to the docs
        $html .= '<p>If this is a new Swarm install, please see the links below and ensure you have configured Swarm'
            . ' correctly. <a href="' . $url . 'Content/Swarm/chapter.setup.html ">Install and upgrade Swarm</a>'
            . ' documentation; in particular:</p>'
            . '<ul>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.dependencies.html">Runtime dependencies</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.installation.html">Installation</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup_php.html">PHP configuration</a></li>'
            . '<li><a href="' . $url . 'Content/Swarm/setup.swarm.html">Swarm configuration</a></li>'
            . '</ul>'

            . '<p>If this does not solve your Swarm issue, contact Support <a href="'
            . 'https://www.perforce.com/support">Perforce Support portal</a>.</p>'
            . '</body></html>';

        // goodbye cruel world
        returnData($html, $error);
    } else {
        // If no config errors then just output the error that has been given by apache or php.
        $html = '<html><body>'
            . '<h1>Swarm has detected an error</h1>'
            . '<p>Please investigate the below PHP error:</p>'
            . '<pre>' . $error->getMessage() . '</pre>'
            . '<p>'.$error->getFile() . ' on line ' . $error->getLine() . '</p>'
            . '</body></html>';

        returnData($html, $error);
    }
}

/**
 * get the local url else if we are on docs and still got the error go to public site.
 * @param array $configErrors
 * @return string
 */
function getURL($configErrors = [])
{
    // Check if the user has attempted to access docs but fallen into the error checking.
    $url            = parse_url($_SERVER['REQUEST_URI']);
    $urlFirst       = explode('/', $url['path']);
    $desired_output = $urlFirst[1];

    // Default docs url endpoint.
    $url = '/docs/';

    // If user is on docs or has badly configured the p4 block we should point them to public docs.
    if ($desired_output === 'docs' || in_array("Swarm configuration file contain a p4 block", $configErrors)) {
        $url = 'https://www.perforce.com/perforce/doc.current/manuals/swarm/';
    }
    return $url;
}

// This will first check if this is a json request and if it is return json data
// else we just return html like we used to have.
function returnData($html, $error)
{
    if ($_SERVER['HTTP_ACCEPT'] === APPLICATION_JSON) {
        // If we have found it is a json request return data in json
        die(json_encode(array('isValid' => false,'message' => $error->getMessage())));
    }
    die($html);
}

function checkConfig($configPath)
{
    // if config file doesn't exist, just return (we handle that error elsewhere)
    if (!file_exists($configPath)) {
        return null;
    }

    // Check if the config.php has any syntax errors.
    try {
        include $configPath;
    } catch (ParseError $parseE) {
        return array('Swarm configuration file is incorrectly configured');
    }

    // bail early if config is not an array
    $config = include $configPath;
    if (!is_array($config)) {
        return array('Swarm configuration file must return an array.');
    }

    // check if the p4 block has been set.
    if (!isset($config['p4'])) {
        return array('Swarm configuration file contain a p4 block');
    }

    $errors         = array();
    $urlShortLinks  = getConfigValue($config, array('short_links', 'external_url'));
    $urlEnvironment = getConfigValue($config, array('environment', 'external_url'));

    // ensure environment/short-links urls look ok and include valid scheme
    if ($urlEnvironment && !in_array(parse_url($urlEnvironment, PHP_URL_SCHEME), array('http', 'https'))) {
        $errors[] = 'Invalid value in [environment][external_url] config option.';
    }
    if ($urlShortLinks && !in_array(parse_url($urlShortLinks, PHP_URL_SCHEME), array('http', 'https'))) {
        $errors[] = 'Invalid value in [short_links][external_url] config option.';
    }

    // ensure valid short_links configuration
    if (strlen($urlShortLinks) && !$urlEnvironment) {
        $errors[] = 'Config option [environment][external_url] must be set if [short_links][external_url] is set.';
    }

    return $errors;
}

// helper function to return config value for a specified options path
// if config has no value for the given path, return null
function getConfigValue(array $config, array $optionsPath)
{
    $value = $config;
    foreach ($optionsPath as $option) {
        $value = isset($value[$option]) ? $value[$option] : null;
    }

    return $value;
}
