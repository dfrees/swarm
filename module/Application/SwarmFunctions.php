<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application;

/**
 * Static functions to assist with Multi-P4D behaviours.
 */
final class SwarmFunctions
{
    /**
     * Read the configuration from the Data Path and extract Multi-P4D configuration entries
     *
     * @param   string  $basePath   path to data folder
     * @return  array
     */
    public static function getMultiServerConfiguration($basePath)
    {
        // any sub-array under 'p4' with a port element enables multi-p4-server mode
        $config  = $basePath . '/config.php';
        $config  = file_exists($config) ? include $config : null;
        $servers = array_filter(
            isset($config['p4']) ? (array) $config['p4'] : [],
            function ($item) {
                return is_array($item) && isset($item['port']);
            }
        );

        return $servers;
    }

    /**
     * Detects whether the system is in Multi-P4D mode, and defines related constants:
     *
     *      MULTI_P4_SERVER     true if Multi-P4D mode is enabled, otherwise false
     *      P4_SERVER_ID        the ID of the currently-selected server in Multi-P4D mode
     *      P4_SERVER_VALID_IDS a serialized array containing a list of valid P4 Server IDs
     *
     * @param string      $basePath   path to data folder
     * @param string|null $serverId   optional server ID or null
     *                                Default: null (attempt server ID detection in Multi-P4D mode)
     */
    public static function detectP4Server($basePath, $serverId = null)
    {
        $servers = static::getMultiServerConfiguration($basePath);

        // early exit if we do not have multiple p4 servers
        define('MULTI_P4_SERVER', (bool) $servers);
        define('P4_SERVER_VALID_IDS', serialize(array_keys($servers)));
        if (!MULTI_P4_SERVER) {
            define('P4_SERVER_ID', null);

            return;
        }

        // if a server ID has been specified, such as in the search.php script,
        // use it if it exists
        if ($serverId) {
            define('P4_SERVER_ID', array_key_exists($serverId, $servers) ? $serverId : null);
            return;
        }

        // as we have multiple p4 servers, we need the request uri to pick one
        // the first path component of the URI tells us which server to select
        if (isset($_SERVER['HTTP_X_REWRITE_URL'])) {
            $requestUri = $_SERVER['HTTP_X_REWRITE_URL'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = $_SERVER['REQUEST_URI'];
        } else {
            $requestUri = '/';
        }

        // strip origin and extract first path component
        $requestUri = preg_replace('#^[^/:]+://[^/]+#', '', $requestUri);
        $firstPath  = preg_replace('#^/?([^/?]*).*#', '$1', $requestUri);

        define('P4_SERVER_ID', array_key_exists($firstPath, $servers) ? $firstPath : null);
    }

    /**
     * Configure important environment variables, including DATA_PATH and P4_SERVER_ID
     *
     * @param string      $basePath   path to data folder
     * @param string|null $serverId   optional server ID or null
     *                                Default: null (attempt server ID detection in Multi-P4D mode)
     */
    public static function configureEnvironment($basePath, $serverId = null)
    {
        static::detectP4Server($basePath, $serverId);

        // in a multi-p4-server setup the DATA_PATH is BASE_DATA_PATH/servers/P4_SERVER_ID
        // this isolates each server's data so that files do not collide and conflict
        define(
            'DATA_PATH',
            rtrim($basePath . '/' . (P4_SERVER_ID ? 'servers/' . P4_SERVER_ID : ''), '/\\')
        );
    }
}
