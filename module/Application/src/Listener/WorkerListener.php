<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Listener;

use Application\Module;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

class WorkerListener extends AbstractEventListener
{

    public function setHostUrl(Event $event)
    {
        $p4       = $this->services->get('p4_admin');
        $p4Config = $this->services->get('p4_config');
        $register = isset($p4Config['auto_register_url']) && $p4Config['auto_register_url'];

        // only run for the first worker on new enough servers (2013.1+).
        if ($event->getParam('slot') !== 1 || !$p4->isServerMinVersion('2013.1')) {
            return;
        }

        $mainKey    = Module::PROPERTY_SWARM_URL;
        $commitKey  = Module::PROPERTY_SWARM_COMMIT_URL;
        $value      = $p4->run('property', ['-l', '-n', $mainKey])->getData(0, 'value');
        $url        = $this->services->get('ViewHelperManager')->get('qualifiedUrl');
        $info       = $p4->getInfo();
        $serverType = isset($info['serverServices']) ? $info['serverServices'] : null;
        $isEdge     = strpos($serverType, 'edge-server')   !== false;
        $isCommit   = strpos($serverType, 'commit-server') !== false;

        // set main URL property so that P4V (or others) can find Swarm
        // set if empty or doesn't match and this is not an edge server
        // we don't change the value if we are talking to an edge server
        // because the value could point to a commit Swarm
        if ($register && (!$value || ($value !== $url() && !$isEdge))) {
            $p4->run('property', ['-a', '-n', $mainKey, '-v', $url(), '-s0']);
        }

        // set commit url property so that edge Swarm's can find commit Swarm's
        if ($isCommit) {
            $p4->run('property', ['-a', '-n', $commitKey, '-v', $url(), '-s0']);
        }
    }

    public function removeInvalidatedFiles(Event $event)
    {
        // only run for the first worker
        if ($event->getParam('slot') !== 1) {
            return;
        }
    }
}
