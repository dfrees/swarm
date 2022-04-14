<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\View\Helper;

use P4\File\File;
use Laminas\View\Helper\AbstractHelper;

class DecodeFilespec extends AbstractHelper
{
    /**
     * Call through to File::decodeFilespec to decode occurrences
     * of %40 %23 %25 %2A to @#%* respectively.
     *
     * @param   string  $filespec  the potentially encoded filespec
     * @return  string  the decoded filespec
     */
    public function __invoke($filespec)
    {
        return $this->getView()->escapeHtml(File::decodeFilespec($filespec));
    }
}
