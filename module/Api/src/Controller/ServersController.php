<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Laminas\View\Model\JsonModel;

/**
 * Swarm Servers API
 */
class ServersController extends AbstractApiController
{
    /**
     * Get a list of Perforce servers defined in the current configuration
     * @return JsonModel
     */
    public function getList()
    {
        $services = $this->services;
        $config   = $services->get('config');
        $servers  = [];
        foreach (array_filter(
            isset($config['p4']['port']) ? [$config[ 'p4']] : $config[ 'p4'],
            function ($p4d) {
                return isset($p4d['port']);
            }
        ) as $id => $server) {
            unset($server['user']);
            unset($server['password']);
            // If this is a single server environment return consistent results to
            // multi-server by using 'p4' as the identifier
            $servers[$id === 0 ? 'p4' : $id] = $server;
        }
        return $this->prepareSuccessModel(new JsonModel(['servers' => $servers]));
    }
}
