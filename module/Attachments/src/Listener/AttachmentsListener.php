<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Attachments\Listener;

use Attachments\Model\Attachment;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;

class AttachmentsListener extends AbstractEventListener
{

    public function cleanUp(Event $event)
    {
        $p4Admin = $this->services->get('p4_admin');
        $id      = $event->getParam('id');

        try {
            $attachment = Attachment::fetch($id, $p4Admin);
            $event->setParam('attachment', $attachment);

            if (!$attachment->getReferences()) {
                $attachment->delete();
            }
        } catch (\Exception $e) {
            $this->services->get('logger')->err($e);
        }
    }
}
