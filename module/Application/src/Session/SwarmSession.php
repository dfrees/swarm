<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Session;

use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Laminas\Http\Request;
use Laminas\Session\Config\SessionConfig;

/**
 * Class SwarmSession
 *
 * @package Application\Session
 */
class SwarmSession extends SessionManager implements InvokableService
{
    const SESSION      = 'session';
    const SESSION_NAME = 'SWARM';

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $config  = $services->get(ConfigManager::CONFIG) + [self::SESSION => []];
        $strict  = isset($config[ 'security' ][ 'https_strict' ]) && $config[ 'security' ][ 'https_strict' ];
        $baseUrl = isset($config[ 'environment' ][ 'base_url' ]) ? $config[ 'environment' ][ 'base_url' ] : '/';
        $config  = $config[ self::SESSION ] + [
                'name'                       => null,
                'cookie_path'                => $baseUrl,
                'save_path'                  => null,
                'cookie_lifetime'            => null,
                'remembered_cookie_lifetime' => null
            ];

        // detect if we're on https, if we are (or we're strict) we'll set the cookie to secure
        $request = $services->get('request');
        $https   = $request instanceof Request && $request->getUri()->getScheme() == 'https';

        // by default, relocate session storage if we can to avoid mixing with
        // other php apps using different/default session clean settings.
        $savePath = $this->setupSavePath($config['save_path']);
        if ($savePath) {
            $config['save_path'] = $savePath;
        } else {
            unset($config['save_path']);
        }

        // by default, we name the session id SWARM and, if its running on a
        // non-standard port, we add the port number. This allows separate
        // Swarm instances to run on a given domain using different ports.
        if (!$config['name']) {
            $config['name'] = $this->setupName();
        }

        // verify the session isn't already started (shouldn't be) and adjust
        // the settings. attempting an adjustment post start produces errors.
        $sessionConfig = new SessionConfig;
        if (!session_id()) {
            // if the user has a 'remember me' cookie utilize the 'remembered' cookie lifetime
            // note we have to clear the made up remembered_cookie_lifetime regardless as it
            // would cause an exception if it makes it into the session config.
            if (isset($_COOKIE['remember']) && $_COOKIE['remember']) {
                $config['cookie_lifetime'] = $config['remembered_cookie_lifetime'];
            }
            unset($config['remembered_cookie_lifetime']);

            // set the session config by mixing any user provided config
            // values with our defaults
            $sessionConfig->setOptions(
                $config +  [
                    'cookie_httponly'  => true,
                    'cookie_secure'    => ($https || $strict),
                    'gc_probability'   => 1,
                    'gc_divisor'       => 100,
                    'gc_maxlifetime'   => 24*60*60 * 30    // 1 month
                ]
            );
        }

        parent::__construct($sessionConfig);

        // a couple conditions require the session id pre-start, get it if possible
        $sessionName = $sessionConfig->getOption('name');
        $sessionId   = isset($_COOKIE[$sessionName]) ? $_COOKIE[$sessionName] : null;

        // if we have no session cookie, no need to deal with session expiry or
        // read current values from disk; just bail!
        if (!strlen($sessionId)) {
            return;
        }

        // we want to actually enforce the gc lifetime for file-based sessions.
        // to support this, pull the mtime from the session file before we start.
        $sessionFile = strlen($sessionId) && $sessionConfig->getOption('save_handler') == 'files'
            ? $sessionConfig->getOption('save_path') . '/sess_' . $sessionId
            : false;
        $sessionTime = $sessionFile && file_exists($sessionFile) ? filemtime($sessionFile) : false;

        // ensure the session is started (to populate session storage data)
        // but promptly close it to minimize locking - anytime we need
        // to update the session later, we need to explicitly open/close it.
        $this->start();

        // if we found a session file mod-time and its expired, destroy the session
        if ($sessionTime && (time() - $sessionTime) > $sessionConfig->getOption('gc_maxlifetime')) {
            $this->destroy(['send_expire_cookie' => true, 'clear_storage' => true]);
        }

        $this->writeClose();
    }

    /**
     * Setup the SavePath for sessions to be storage in.
     *
     * @param $savePath
     * @return bool|string
     */
    public function setupSavePath($savePath)
    {
        $savePath = $savePath ?: DATA_PATH . '/sessions';
        is_dir($savePath) ?: @mkdir($savePath, 0700, true);
        if (!is_writable($savePath) || is_file($savePath)) {
            return false;
        }
        return $savePath;
    }

    /**
     * Setup the Session name.
     *
     * @return string
     */
    public function setupName()
    {
        // we try to extract the port from the HTTP_HOST if possible.
        // if we fail to find it there we fall back to the SERVER_PORT variable
        // SERVER_PORT is fairly certain to be present but known to report 80
        // even when another port is in use under some apache configurations.
        $server = $_SERVER + ['HTTP_HOST' => '', 'SERVER_PORT' => null];
        preg_match('/:(?P<port>[0-9]+)$/', $server['HTTP_HOST'], $matches);
        $port = isset($matches['port']) && $matches['port']
            ? $matches['port']
            : $server['SERVER_PORT'];

        return self::SESSION_NAME. ($port && $port != 80 && $port != 443 ? '-' . $port : '');
    }
}
