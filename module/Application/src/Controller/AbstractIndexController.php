<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Controller;

use Api\IRequest;
use Application\Validator\ModelFieldValidator;
use InvalidArgumentException;
use Redis\RedisService;
use Laminas\Mvc\Controller\AbstractActionController;

abstract class AbstractIndexController extends AbstractActionController
{
    protected $services = null;

    /**
     * IndexController constructor.
     * @param $services
     */
    public function __construct($services)
    {
        $this->services = $services;
        // Call the Redis service so we can check it is connected and return a error if not.
        $services->get(RedisService::class);
    }

    /**
     * Validate fields against a model
     * @param mixed     $query      request query
     * @param mixed     $model      model instance
     * @return array|null
     * @throws InvalidArgumentException if fields provided are invalid
     */
    protected function validateFields($query, $model)
    {
        $fieldsQuery = $query->get(IRequest::FIELDS, '');
        $fields      = null;
        // $fieldsQuery is a string if called via the API and an array from the UI
        if ($fieldsQuery) {
            if (is_array($fieldsQuery)) {
                $fields = $fieldsQuery;
            } else {
                $fields = explode(',', $fieldsQuery);
            }
            if ($fields) {
                $validator = new ModelFieldValidator();
                if (!$validator->isValid($fields, $model)) {
                    throw new InvalidArgumentException();
                }
            }
        }
        return $fields;
    }
}
