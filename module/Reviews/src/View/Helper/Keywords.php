<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\View\Helper;

use Reviews\Filter\Keywords as KeywordsFilter;
use Application\View\Helper\AbstractHelper;

/**
 * Class Keywords
 *
 * @package Reviews\View\Helper
 */
class Keywords extends AbstractHelper
{
    /**
     * If the caller passes an argument we'll strip all keywords and return the modified value.
     * If no arguments are passed the keyword filter is returned allowing access to other methods.
     *
     * @param   string|null     $value  if a value is passed, it will be stripped of keywords and returned
     * @return  string|Filter   if a value was passed, the stripped version otherwise a Keyword Filter object
     */
    public function __invoke($value = null)
    {
        $services = $this->services;
        $filter   = $services->get(KeywordsFilter::SERVICE);

        // if an argument was passed; simply filter it
        if (func_num_args() > 0) {
            return $filter($value);
        }

        // if no arguments return the filter to allow caller access to other methods
        return $filter;
    }
}
