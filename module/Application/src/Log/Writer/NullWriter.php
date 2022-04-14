<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Log\Writer;

use Laminas\Log\Writer\AbstractWriter;

class NullWriter extends AbstractWriter
{
    /**
     * Discard the provided message.
     *
     * @param   array   $event
     */
    protected function doWrite(array $event)
    {
    }
}
