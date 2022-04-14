<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Laminas\Filter\AbstractFilter;
use Laminas\Stdlib\StringUtils;

class WordWrap extends AbstractFilter
{
    protected $width = null;

    /**
     * Wraps words in the passed text such that no line in the filtered text is longer
     * than the value specified in 'width'. Setting the 'width' value to
     * null or zero (or any negative value) will effectively disable this filter.
     *
     * @param  string $value
     * @return string
     */
    public function filter($value)
    {
        $width = (int) $this->width;
        if ($width > 0) {
            $utility = StringUtils::getWrapper();
            $value   = $utility->wordWrap($value, $width);
        }

        return $value;
    }

    /**
     * Set max length of each line in the passed text.
     * To disable this feature, set the value to zero or null.
     *
     * @param   int|null    $width      maximum length of each line in the output text
     * @return  WordWrap    to maintain fluent interface
     */
    public function setWidth($width)
    {
        $this->width = $width;
        return $this;
    }
}
