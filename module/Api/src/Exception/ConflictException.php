<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Api\Exception;

use Exception;
use Laminas\Http\Response;

/**
 * A generic exception class to handle conflicts when calling APIs.
 */
class ConflictException extends Exception
{
    private $data;

    /**
     * Construct the exception
     * @param string    $message    the message
     * @param mixed     $data       data to return with the conflict. APIs will most likely return updated data along
     *                              with the exception
     * @param int       $code       exception HTTP code
     */
    public function __construct($message, $data, $code = Response::STATUS_CODE_409)
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }

    /**
     * Get the data
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
