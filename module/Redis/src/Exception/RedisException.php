<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Redis\Exception;

use RuntimeException;
use Throwable;
use Laminas\Http\Response;
use Laminas\ServiceManager\Exception\ExceptionInterface;

/**
 * This exception indicates that redis is starting.
 */
class RedisException extends RuntimeException implements ExceptionInterface
{
    const LOADING_MESSAGE = "Swarm is starting, please wait";
    const LOADING         = "LOADING";

    public function __construct($message = "", $code = Response::STATUS_CODE_503, Throwable $previous = null)
    {
        // If the message contains a Loading string show the nice message else show the unavailable message.
        $content = strpos($message, self::LOADING) !== false ? self::LOADING_MESSAGE : $message;
        parent::__construct($content, $code, $previous);
    }
}
