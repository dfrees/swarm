<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Workflow\Filter;

use Laminas\Filter\AbstractFilter;
use Workflow\Model\IWorkflow;

/**
 * Class Tests. Filters values
 * @package Workflow\Filter
 */
class Tests extends AbstractFilter
{
    /**
     * Filters test values to ensure values are present or correctly translated before validation
     * @param mixed     $value      the list of tests being validated
     * @return mixed the value with any filter changes
     */
    public function filter($value)
    {
        if (is_array($value)) {
            foreach ($value as &$test) {
                // Default 'blocks' to 'none' if it is not set
                if (is_array($test)) {
                    if (!isset($test[IWorkflow::BLOCKS]) || !$test[IWorkflow::BLOCKS]) {
                        $test[IWorkflow::BLOCKS] = IWorkflow::NOTHING;
                    }
                }
            }
        }
        return $value;
    }
}
