<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Controller;

use Laminas\Mvc\Controller\AbstractRestfulController;

class IndexController extends AbstractRestfulController
{
    protected $services = null;

    /**
     * IndexController constructor.
     * @param $services
     */
    public function __construct($services)
    {
        $this->services = $services;
    }

    // Null operation to prevent 405 http status from parent
    public function getList()
    {
        return;
    }
}
