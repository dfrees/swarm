<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Queue\Exception;

use Laminas\ServiceManager\Exception\ExceptionInterface;
use RuntimeException;

/**
 * Class QueueException. Specific class for queue related issues
 * @package Queue\Exception
 */
class QueueException extends RuntimeException implements ExceptionInterface
{
    const INVALID_INFO  = "invalid info";
    const INVALID_DATA  = "invalid data";
    const PARSE_MESSAGE = "Task data beginning with [%s] could not be parsed due to %s, this task cannot be processed";
    const FILE_MESSAGE  = "File [%s] not found";

    /**
     * Throw an exception for an error parsing a queue task
     * @param string        $header     first part of a task that caused the error
     * @param string        $reason     reason for the error
     */
    public static function parseError($header, $reason)
    {
        throw new QueueException(sprintf(QueueException::PARSE_MESSAGE, $header, $reason));
    }

    /**
     * Throw an exception for a file path not being found
     * @param mixed     $file       file path
     */
    public static function fileError($file)
    {
        throw new QueueException(sprintf(QueueException::FILE_MESSAGE, $file));
    }
}
