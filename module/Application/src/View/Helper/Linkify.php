<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Application\Model\ServicesModelTrait;
use Laminas\View\Helper\AbstractHelper;

class Linkify extends AbstractHelper
{
    use ServicesModelTrait;

    /**
     * Attempts to linkify the passed text.
     * Email addresses, HTTP, HTTPS and FTP links will be made clickable.
     * Things following an @ (other than emails) will be routed to our
     * special 'goto' action to be resolved in the application. This will
     * cover changes, jobs, files/folders, users and projects.
     *
     * @param  string   $value  text to be linkified
     * @return string   linkified and escaped (for html context) result
     */
    public function __invoke($value)
    {
        $filter = self::getLinkifyService();
        $filter->setBaseUrl($this->getView()->basePath());
        return $filter->filter($value);
    }
}
