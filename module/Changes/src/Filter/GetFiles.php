<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Filter;

use Application\Filter\FilterTrait;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;

class GetFiles extends InputFilter implements IChange
{
    use FilterTrait;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        // From change id must be an integer greater zero where provided
        $this->addInt(self::FROM_CHANGE_ID);
    }
}
