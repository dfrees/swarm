<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Filter;

use Application\Factory\InvokableService;
use Application\Filter\FilterTrait;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;
use Comments\Model\IComment;

/**
 * Filter for values associated with updating mark as read on a comment
 */
class MarkAsRead extends InputFilter implements InvokableService
{
    use FilterTrait;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->addInt(IComment::EDITED);
    }
}
