<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application;

use Application\Config\ConfigManager;
use Application\Filter\ExternalUrl;
use Application\View\Http\RouteNotFoundStrategy;
use Exception;
use RuntimeException;
use Laminas\Http\Response as HttpResponse;
use Laminas\Http\Request as HttpRequest;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Laminas\Validator\AbstractValidator;

class Module
{
    const   PROPERTY_SWARM_URL        = 'P4.Swarm.URL';
    const   PROPERTY_SWARM_COMMIT_URL = 'P4.Swarm.CommitURL';

    /**
     * Initializes the application module.
     * @param ModuleManager $moduleManager
     */
    public function init(ModuleManager $moduleManager)
    {
        $events = $moduleManager->getEventManager();
        // We are binding to a module event here that cannot be done in the usual Events module as the module events
        // are triggered before the Events can register listeners
        // Registering a listener at default priority, 1, which will trigger when the ConfigListener merges config.
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, [new ConfigManager(), 'mergeConfig']);
    }

    /**
     * Bootstrap the module
     * @param MvcEvent $event
     * @throws Config\ConfigException
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $config      = $services->get(ConfigManager::CONFIG);

        $services->setAllowOverride(true);
        // remove Zend's route not found strategy and attach our own
        // ours does not muck with JSON responses (we think that is bad)
        $this->replaceNotFoundStrategy($event);

        // attempt to select a UTF-8 compatible locale, this is important
        // to avoid corrupting unicode characters when manipulating strings
        $this->setUtf8Locale($services);

        // enable localized validator messages
        $translator = $services->get('translator');
        AbstractValidator::setDefaultTranslator($translator);

        // only allow same origin framing to deter clickjacking
        if ($application->getResponse() instanceof HttpResponse
            && (isset($config['security']['x_frame_options']) && $config['security']['x_frame_options'])
        ) {
            $application->getResponse()->getHeaders()->addHeaderLine(
                'X-Frame-Options: ' . $config['security']['x_frame_options']
            );
        }

        // if strict https is set, tell browsers to only use SSL for the next 30 days
        // if strict https is set, along with redirect, and we're on http add a meta-refresh to goto https
        if ($application->getResponse() instanceof HttpResponse
            && isset($config['security']['https_strict']) && $config['security']['https_strict']
        ) {
            // always add the HSTS header, HTTP Clients will just ignore it
            $application->getResponse()->getHeaders()->addHeaderLine('Strict-Transport-Security: max-age=2592000');

            // if we came in on http and redirection is enabled, add a meta-refresh
            $uri = $application->getRequest()->getUri();
            if ($uri->getScheme() == 'http'
                && isset($config['security']['https_strict_redirect']) && $config['security']['https_strict_redirect']
            ) {
                $port = isset($config['security']['https_port']) ? $config['security']['https_port'] : null;
                $uri  = clone $uri;
                $uri->setScheme('https');
                $uri->setPort($port);
                $services->get('ViewHelperManager')->get('HeadMeta')->appendHttpEquiv('Refresh', '0;URL=' . $uri);
            }
        }

        // ensure a timezone was set to quell later warnings
        date_default_timezone_set(@date_default_timezone_get());

        // enable the display of errors in development mode.
        $display_errors = 0;
        $dev            = isset($config['environment']['mode']) && $config['environment']['mode'] == 'development';
        if ($dev) {
            if (strpos($application->getRequest()->getUri(), '/api/') === false) {
                // don't allow php to output html errors for api calls
                $display_errors = 1;
            }
            $services->get('HttpExceptionStrategy')->setDisplayExceptions(true);
        }
        ini_set('display_errors', $display_errors);
        ini_set('display_startup_errors', $display_errors);
        if (!$display_errors) {
            // Disable warnings if errors are not being displayed
            error_reporting(error_reporting() & ~E_WARNING);
        }
        // base_url in null in a default environment. If it is overridden in config.php
        // we need to get that value. We also need to take account of a multi-server
        // environment using P4_SERVER_ID.
        // This effectively takes the place of values that were computed every request in
        // Application/config/module.config.php but will no longer be when configuration
        // caching is used
        $configBaseUrl = ConfigManager::getValue($config, ConfigManager::ENVIRONMENT_BASE_URL);
        // Trim any provided value
        $moddedUrl = $configBaseUrl ? trim($configBaseUrl, '/') : '';
        // Append with multi-p4d if configured
        $moddedUrl = $moddedUrl . (P4_SERVER_ID ? '/' . P4_SERVER_ID : '');
        if (empty($moddedUrl)) {
            $moddedUrl = null;
        } else {
            if (strpos($moddedUrl, '/') !== 0) {
                $moddedUrl = '/' . $moddedUrl;
            }
        }
        $config[ConfigManager::ENVIRONMENT][ConfigManager::BASE_URL]        = $moddedUrl;
        $config[ConfigManager::ENVIRONMENT][ConfigManager::ASSET_BASE_PATH] = P4_SERVER_ID ? '/' : null;
        $services->setService(ConfigManager::CONFIG, $config);

        // normalize the hostname if one is set.
        // users might erroneously include a scheme or port when all we want is a host.
        if (!empty($config['environment']['hostname'])) {
            preg_match('#^([a-z]+://)?(?P<hostname>[^:]+)?#', $config['environment']['hostname'], $matches);
            $config['environment']['hostname'] = isset($matches['hostname']) ? $matches['hostname'] : null;
            $services->setService(ConfigManager::CONFIG, $config);
        }

        // derive the hostname from the request if one isn't set.
        if (empty($config['environment']['hostname']) && $application->getRequest() instanceof HttpRequest) {
            $config['environment']['hostname'] = $application->getRequest()->getUri()->getHost();
            $services->setService(ConfigManager::CONFIG, $config);
        }

        // normalize and lightly validate the external_url if one is set.
        if (!empty($config['environment']['external_url'])) {
            $enforceHttps = isset($config['security']['https_strict']) && $config['security']['https_strict'];
            $filter       = new ExternalUrl($enforceHttps);
            $url          = $filter->filter($config['environment']['external_url']);
            if (!$url) {
                throw new RuntimeException(
                    'Invalid environment external_url value in config.php'
                );
            }

            $config['environment']['external_url'] = $url;
            $config['environment']['hostname']     = parse_url($url, PHP_URL_HOST);
            $services->setService(ConfigManager::CONFIG, $config);
        }

        // ensure the various view helpers use our escaper as it
        // will replace invalid utf-8 byte sequences with an inverted
        // question mark, zend's version would simply blow up.
        $escaper = new Escaper\Escaper;
        $helpers = $services->get('ViewHelperManager');
        $helpers->get('escapeCss')->setEscaper($escaper);
        $helpers->get('escapeHtml')->setEscaper($escaper);
        $helpers->get('escapeHtmlAttr')->setEscaper($escaper);
        $helpers->get('escapeJs')->setEscaper($escaper);
        $helpers->get('escapeUrl')->setEscaper($escaper);
        $helpers->get('escapeFullUrl')->setEscaper($escaper);

        // define the version constants
        $file    = BASE_PATH . '/Version';
        $values  = file_exists($file) ? parse_ini_file($file) : [];
        $values += ['RELEASE' => 'unknown', 'PATCHLEVEL' => 'unknown', 'SUPPDATE' => date('Y/m/d')];
        if (!defined('VERSION_NAME')) {
            define('VERSION_NAME',       'SWARM');
        }
        if (!defined('VERSION_RELEASE')) {
            define('VERSION_RELEASE',    strtr(preg_replace('/ /', '.', $values['RELEASE'], 1), ' ', '-'));
        }
        if (!defined('VERSION_PATCHLEVEL')) {
            define('VERSION_PATCHLEVEL', $values['PATCHLEVEL']);
        }
        if (!defined('VERSION_SUPPDATE')) {
            define('VERSION_SUPPDATE',   strtr($values['SUPPDATE'], ' ', '/'));
        }
        if (!defined('VERSION')) {
            define(
                'VERSION',
                VERSION_NAME . '/' . VERSION_RELEASE . '/' . VERSION_PATCHLEVEL . ' (' . VERSION_SUPPDATE . ')'
            );
        }

        // set base url on the request
        // we take base url from the config if set, otherwise from the server (defaults to empty string)
        $request = $application->getRequest();
        $baseUrl = isset($config['environment']['base_url'])
            ? $config['environment']['base_url']
            : $request->getServer()->get('REQUEST_BASE_URL', '');
        $request->setBaseUrl($baseUrl);

        // in some cases the base path used for assets needs to differ from
        // the base url (e.g. when running in multi-server mode)
        $assetBasePath = isset($config['environment']['asset_base_path'])
            ? $config['environment']['asset_base_path']
            : $baseUrl;
        $services->get('ViewHelperManager')->get('assetBasePath')->setBasePath($assetBasePath);
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Remove Zend's route not found strategy and attach our own
     * ours does not muck with JSON responses (we think that is bad)
     *
     * @param MvcEvent $event
     */
    public function replaceNotFoundStrategy(MvcEvent $event)
    {
        $application      = $event->getApplication();
        $services         = $application->getServiceManager();
        $events           = $application->getEventManager();
        $notFoundStrategy = $services->get('HttpRouteNotFoundStrategy');
        $sharedEvents     = $events->getSharedManager();
        $sharedListeners  = $sharedEvents->getListeners(
            ['Laminas\Stdlib\DispatchableInterface'], MvcEvent::EVENT_DISPATCH
        );

        // detach from the general event manager
        $notFoundStrategy->detach($events);

        // detach from the shared events manager
        foreach ($sharedListeners as $index => $callbacks) {
            foreach ($callbacks as $callback) {
                if ($callback[0] === $notFoundStrategy) {
                    $sharedEvents->detach($callback);
                }
            }
        }

        $oldNotFoundStrategy = $notFoundStrategy;
        $notFoundStrategy    = new RouteNotFoundStrategy;

        // preserve behaviour from old strategy instance
        $notFoundStrategy->setDisplayExceptions($oldNotFoundStrategy->displayExceptions());
        $notFoundStrategy->setDisplayNotFoundReason($oldNotFoundStrategy->displayNotFoundReason());
        $notFoundStrategy->setNotFoundTemplate($oldNotFoundStrategy->getNotFoundTemplate());

        // update the stored service and attach the strategy to the event manager
        $services->setService('HttpRouteNotFoundStrategy', $notFoundStrategy);
        $notFoundStrategy->attach($events);
    }

    /**
     * Set the locale to one that supports UTF-8.
     *
     * Note: we only change the locale for LC_CTYPE as we only
     * want to affect the behavior of string manipulation.
     *
     * @param ServiceLocator $services the service locator for logging purposes
     * @throws Exception
     */
    protected function setUtf8Locale(ServiceLocator $services)
    {
        $logger  = $services->get('logger');
        $pattern = '/\.utf\-?8$/i';

        // if we are already using a utf8 locale, nothing to do.
        if (preg_match($pattern, setlocale(LC_CTYPE, 0))) {
            return;
        }

        // we don't want to run 'locale -a' for every request - cache it for 1hr.
        $cacheFile = DATA_PATH . '/cache/system-locales';
        static::ensureCacheDirExistAndWritable(dirname($cacheFile));
        if (file_exists($cacheFile) && (time() - (int) filemtime($cacheFile)) < 3600) {
            $fromCache = true;
            $locales   = unserialize(file_get_contents($cacheFile));
        } else {
            $fromCache = false;
            exec('locale -a', $locales, $result);
            if ($result) {
                $logger->err("Failed to exec 'locale -a'. Exit status: $result.");
                $locales = [];
            }
            file_put_contents($cacheFile, serialize($locales));
        }

        foreach ($locales as $locale) {
            if (preg_match($pattern, $locale) && setlocale(LC_CTYPE, $locale) !== false) {
                return;
            }
        }

        // we don't want to complain for every request - only report errors every 1hr.
        if (!$fromCache) {
            $logger->err("Failed to set a UTF-8 compatible locale.");
        }
    }

    /**
     * Throws an exception if $dir is not a directory or is not writable.
     *
     * @param $dir          string  the name of the directory to check
     * @throws Exception    thrown if $dir is not a directory or is not writable
     */
    public static function ensureCacheDirExistAndWritable($dir)
    {
        // ensure cache dir exists and is writable
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0700);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception(
                "Cannot write to cache directory ('" . $dir . "'). Check permissions."
            );
        }
    }
}
