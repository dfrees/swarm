<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Application\Filter\WordWrap as Filter;
use Laminas\View\Helper\AbstractHelper;

class WordWrap extends AbstractHelper
{
    /**
     * Wraps words in the output text such that no line in the filtered text is longer
     * than the value specified in $maxLineLength
     *
     * @param  string   $value      text to be wrapper
     * @param  int|null $width      maximum length of each line in the output text
     * @return string   wrapped result
     */
    public function __invoke($value, $width)
    {
        $filter = new Filter;
        return $filter->setWidth($width)
                      ->filter($value);
    }
}
