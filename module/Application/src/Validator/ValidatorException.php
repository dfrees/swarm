<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Throwable;
use Exception;
use Laminas\Validator\ValidatorInterface;

/**
 * Class ValidatorException. Exception to help with validator messages as arrays
 * @package Application\Validator
 */
class ValidatorException extends Exception
{
    private $messages;

    /**
     * FilterException constructor.
     * @param ValidatorInterface $validator
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(ValidatorInterface $validator, $message = '', $code = 0, Throwable $previous = null)
    {
        $this->messages = $validator->getMessages();
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
