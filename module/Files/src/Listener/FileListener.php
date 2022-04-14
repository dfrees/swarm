<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Files\Listener;

use Application\Config\Services;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

class FileListener extends AbstractEventListener
{

    public function cleanUpArchive(Event $event)
    {
        $archiveFile = $event->getParam('id');
        $data        = $event->getParam('data');
        $statusFile  = isset($data['statusFile']) ? $data['statusFile'] : null;

        try {
            $this->services->get(Services::ARCHIVER)->removeArchive($archiveFile, $statusFile);
        } catch (\Exception $e) {
            $this->services->get('logger')->err($e);
        }
    }
}
