<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Api\Controller;

use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Permissions\Exception\UnauthorizedException;
use Exception;
use Laminas\Mvc\Controller\AbstractRestfulController as ZendAbstractRestfulController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\View\Model\JsonModel;
use InvalidArgumentException;

abstract class AbstractRestfulController extends ZendAbstractRestfulController
{
    const API_BASE = '/api';
    const ERROR    = 'error';
    const MESSAGES = 'messages';
    const DATA     = 'data';
    const CODE     = 'code';
    const TEXT     = 'text';

    protected $services = null;

    /**
     * IndexController constructor.
     * @param $services
     */
    public function __construct($services)
    {
        $this->services = $services;
    }

    /**
     * Return Success Response
     * @param mixed $data           Data that will be returned
     * @param array $messages       optional messages, defaults to empty array
     * @return JsonModel
     */
    public function success($data, array $messages = [])
    {
        return $this->buildResponse($data, $messages);
    }

    /**
     * Return Error Response
     * @param  array    $messages       messages
     * @param  mixed    $errorCode      error code
     * @param  mixed    $data           optional data
     * @return JsonModel
     */
    public function error(array $messages, $errorCode, $data = null)
    {
        return $this->buildResponse($data, $messages, $errorCode);
    }

    /**
     * Build a message with the code and message text
     * @param mixed  $code      message code
     * @param string $text      message text
     * @return array with self::CODE => $code and self::TEXT => $text
     */
    public function buildMessage($code, string $text)
    {
        return [self::CODE => $code, self::TEXT => $text];
    }

    /**
     * Prepares Json Response
     * @param  array|null   $data           Data that will be returned
     * @param  array        $messages       messages to return
     * @param  int|null     $errorCode      error code
     * @return JsonModel
     */
    private function buildResponse($data, array $messages, $errorCode = null)
    {
        $returnResponse = [
            self::ERROR     => $errorCode,
            self::MESSAGES  => $messages,
            self::DATA      => $data
        ];
        return new JsonModel($returnResponse);
    }

    /**
     * Limit the provided entity, retaining only the desired fields (shallow limiting only, case insensitive)
     *
     * @param   array           $entity     the entity array to limit
     * @param   mixed           $fields     an optional comma-separated string (or array) of fields to keep
     *                                      (null, false, empty array, empty string will keep all fields)
     * @return array            the limited entity, or the original entity if no limiting was performed
     */
    protected function limitFields(array $entity, $fields = null)
    {
        if (!$fields) {
            return $entity;
        }
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }
        if (!is_array($fields)) {
            throw new InvalidArgumentException(
                "Cannot limit fields, expected fields list to be a string, array, null or false."
            );
        }
        // trim the fields, then flip them so they can be used to limit entity fields by key
        return array_intersect_ukey(
            $entity,
            array_flip(array_map('trim', $fields)),
            function ($value1, $value2) {
                return strcasecmp($value1, $value2);
            }
        );
    }

    /**
     * Limit the provided entities, retaining only the desired fields (shallow limiting only, case insensitive)
     *
     * @param   array           $entities   array of entities to limit
     * @param   mixed           $fields     an optional comma-separated string (or array) of fields to keep
     *                                      (null, false, empty array, empty string will keep all fields)
     * @return array            the limited entities, or the original entities if no limiting was performed
     */
    protected function limitFieldsForAll(array $entities, $fields = null)
    {
        $results = [];
        if ($fields) {
            foreach ($entities as $entity) {
                $results[] = $this->limitFields($entity, $fields);
            }
        } else {
            $results = $entities;
        }
        return $results;
    }

    /**
     * extend the api dispatch to log when a user request api calls.
     * This will result in a log entire like:
     * <timeStamp> INFO (6): User <USER> making a request for endpoint http://<HOST>/<URL> for method <METHOD>
     * INFO (6): User bruno making a request for endpoint http://localhost/api/v11/users for method GET
     *
     * @param MvcEvent $e
     * @return array|mixed|string[]
     */
    public function onDispatch(MvcEvent $e)
    {
        $logger      = $this->services->get(SwarmLogger::SERVICE);
        $request     = $e->getRequest();
        $identifier  = $request->getUriString();
        $userRequest = "Anonymous user ";
        $method      = $request->getMethod();
        try {
            $p4User      = $this->services->get(ConnectionFactory::P4_USER);
            $userRequest = "User " . $p4User->getUser() . " ";
        } catch (Exception | UnauthorizedException | ServiceNotCreatedException $error) {
            // Ignore this.
            $logger->trace("No user for the request");
        }
        $logger->info(
            $userRequest . "making a request for endpoint " . $identifier . " for method " . $method
        );
        return parent::onDispatch($e);
    }
}
