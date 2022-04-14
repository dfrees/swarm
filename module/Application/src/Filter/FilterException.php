<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Throwable;
use Exception;
use Laminas\InputFilter\InputFilterInterface;

/**
 * Class FilterException. Exception to help with filter messages as arrays
 * @package Application\Filter
 */
class FilterException extends Exception
{
    private $messages;

    /**
     * FilterException constructor.
     * @param InputFilterInterface $filter
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(InputFilterInterface $filter, $message = '', $code = 0, Throwable $previous = null)
    {
        $this->messages = $filter->getMessages();
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get filter messages
     * @return string[]
     */
    public function getMessages()
    {
        return $this->messages;
    }
}
