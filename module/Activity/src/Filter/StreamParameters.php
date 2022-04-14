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
use Interop\Container\ContainerInterface;

/**
 * Class StreamParameters. Filter for activity get by stream.
 * @package Activity\Filter
 */
class StreamParameters extends Parameters
{
    use FilterTrait;

    /**
     * StreamParameters constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        parent::__construct($services, $options);
        $this->addBool(self::FOLLOWED);
    }
}
