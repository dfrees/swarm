<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Http;

use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\JsonModel;

class RouteNotFoundStrategy extends \Laminas\Mvc\View\Http\RouteNotFoundStrategy
{
    /**
     * Extended to leave JSON models alone
     *
     * @param  MvcEvent $e
     * @return void
     */
    public function prepareNotFoundViewModel(MvcEvent $e)
    {
        if ($e->getResult() instanceof JsonModel) {
            return;
        }

        return parent::prepareNotFoundViewModel($e);
    }
}
