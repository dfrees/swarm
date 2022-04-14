<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Filter;

use Application\Filter\FilterTrait;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;
use P4\Counter\AbstractCounter;

/**
 * Class Parameters. Filter for activity parameters
 * @package Activity\Filter
 */
class Parameters extends InputFilter implements IParameters
{
    use FilterTrait;

    /**
     * Parameters constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        foreach ([AbstractCounter::FETCH_AFTER, self::CHANGE] as $field) {
            $this->addInt($field);
        }
        $this->addInt(AbstractCounter::FILTER_MAX, 100);
    }
}
