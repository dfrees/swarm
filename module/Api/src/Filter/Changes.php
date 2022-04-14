<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Filter;

use Application\InputFilter\InputFilter;

/**
 * Filter to handle requests to changes
 * @package Api\Filter
 */
class Changes extends InputFilter
{
    const STRICT   = 'strict';
    const ENFORCED = 'enforced';
    const SHELVE   = 'shelve';

    public function __construct()
    {
        $this->addTypeFilter();
    }

    /**
     * Ensures that the type is a valid value.
     */
    private function addTypeFilter()
    {
        $this->add(
            [
                'name'          => 'type',
                'required'      => true,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (in_array(
                                    $value,
                                    [$this::STRICT, $this::ENFORCED, $this::SHELVE]
                                )) {
                                    return true;
                                } else {
                                    return sprintf(
                                        "Type must be [%s] or [%s] or [%s].",
                                        $this::STRICT,
                                        $this::ENFORCED,
                                        $this::SHELVE
                                    );
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }
}
