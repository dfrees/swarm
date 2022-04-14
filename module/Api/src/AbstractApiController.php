<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api;

use Laminas\Http\Request;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\Parameters;
use Laminas\View\Model\JsonModel;

/**
 * Abstract helper for API consistency.
 */
abstract class AbstractApiController extends AbstractRestfulController implements IModelFields, IRequest
{
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
     * Convenience method to forward an API request to an existing controller for further processing.
     *
     * @param string            $name       controller name; either a class name or an alias
     *                                      used in the controller manager
     * @param string            $action     the name of the action to call
     * @param array|null        $params     optional - an array of extra parameters for the route matcher
     * @param array|Parameters  $query      optional - get/query parameters
     * @param array|Parameters  $post       optional - post parameters
     * @return JsonModel                    a JsonModel containing a result or an error message
     */
    public function forward($name, $action, $params = null, $query = null, $post = null)
    {
        // we don't want request parameters to bleed through, only explicit parameters shall pass
        $query = $query instanceof Parameters ? $query : new Parameters((array) $query);
        $post  = $post  instanceof Parameters ? $post  : new Parameters((array) $post);

        // forwarded requests generally require json output, so allow the ‘format’
        // param to pass through unless it has been explicitly overridden by $query
        if (!isset($query['format'])) {
            $query->set('format', $this->getRequest()->getQuery('format'));
        }

        // prepare the request by overwriting the query and post parameters
        $this->getRequest()->setQuery($query)->setPost($post);

        return parent::forward()->dispatch(
            $name,
            ['action' => $action] + (array) $params
        );
    }

    /**
     * Detect incoming POSTs with "_method" override parameters from clients that are not
     * compatible with PATCH/DELETE/PUT actions, and convert the request method appropriately
     *
     * @param MvcEvent  $event
     */
    public function onDispatch(MvcEvent $event)
    {
        $request = $event->getRequest();
        $query   = $request->getQuery();

        // override the HTTP method if PATCH, PUT, or DELETE were specified,
        // but only on POST requests
        $method  = strtoupper($query->get('_method', null));
        $methods = [Request::METHOD_PATCH, Request::METHOD_DELETE, Request::METHOD_PUT];

        if ($request->isPost() && in_array($method, $methods)) {
            $request->setMethod($method);
        } elseif ($request->isPost() && strlen($method)) {
            $this->response->setStatusCode(405);
            return;
        }

        parent::onDispatch($event);
    }

    /**
     * Helper to prepare consistently structured error responses.
     *
     * An 'error' element will be set from the response's reason phrase, unless model contains a usable error
     * A 'details' element will be set to a flattened version of the model's 'messages' field, if present
     *
     * @param   JsonModel|array     $model  A model containing 'error' and optionally 'details'.
     * @return  JsonModel           the normalized error model
     * @throws  \LogicException     model did not contain an error message and status code was not 4xx or 5xx
     */
    public function prepareErrorModel($model)
    {
        // In the case of errors controllers may return no actual model so we do not want to
        // ensureJsonModel - we'll fall back to the response body default reason phrase in those
        // cases
        $response = $this->getResponse();
        $error    = $model->getVariable('error');
        $isValid  = $model->getVariable(self::IS_VALID);
        $details  = (array) $model->getVariable(self::MESSAGES);

        if (!$error && !$response->isClientError() && !$response->isServerError()) {
            throw new \LogicException('Cannot build error model. No error message available.');
        }

        // we prefer to use the error from model if available, otherwise we use the reason phrase
        $model = new JsonModel(['error' => is_string($error) ? $error : $response->getReasonPhrase()]);

        // Propagate isValid if provided
        if (null !== $isValid) {
            $model->setVariable(self::IS_VALID, $isValid);
        }

        // if details are present, flatten them and add them to the error model
        // the messages tend to come from Zend's input filter and are needlessly
        // complex (taking the form of ['field' => ['validator' => 'message']],
        // we convert them to ['field' => 'message']).
        if ($details) {
            foreach ($details as $key => $value) {
                $details[$key] = implode(', ', array_values((array) $value));
            }
            $model->setVariable('details', $details);
        }

        return $model;
    }

    /**
     * Helper to prepare consistent (successful) responses.
     *
     * @param   JsonModel|array     $model  A model to adjust prior to rendering
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model)
    {
        $model = $this->ensureJsonModel($model);

        // eliminate common fields returned by system endpoints.
        // in the API we prefer to use status codes instead.
        unset($model->isValid, $model->messages, $model->redirect);

        return $model;
    }

    /**
     * Ensure the given model is an array or JsonModel - normalize to JsonModel.
     *
     * @param   JsonModel|array     $model  the model to check and normalize
     * @return  JsonModel           the normalized JsonModel
     * @throws  \InvalidArgumentException   if the given model is not an array or a JsonModel
     */
    protected function ensureJsonModel($model)
    {
        if (!is_array($model) && !$model instanceof JsonModel) {
            throw new \InvalidArgumentException("Cannot prepare model. Model must be array or JsonModel.");
        }

        return $model instanceof JsonModel ? $model : new JsonModel($model);
    }

    /**
     * Helper to sort fields alphabetically (with 'id' first)
     *
     * @param   array   $entity     the entity keys/values to sort (shallow)
     * @return  array   the sorted keys/values
     */
    protected function sortEntityFields(array $entity)
    {
        uksort(
            $entity,
            function ($a, $b) {
                return (($b === 'id') - ($a === 'id')) ?: strnatcasecmp($a, $b);
            }
        );

        return $entity;
    }

    /**
     * Limit the provided entity, retaining only the desired fields (shallow limiting only)
     *
     * @param   array           $entity     the entity array to limit
     * @param   mixed           $fields     an optional comma-separated string (or array) of fields to keep
     *                                      (null, false, empty array, empty string will keep all fields)
     * @return array            the limited entity, or the original entity if no limiting was performed
     */
    protected function limitEntityFields(array $entity, $fields = null)
    {
        if (!$fields) {
            return $entity;
        }

        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }

        if (!is_array($fields)) {
            throw new \InvalidArgumentException(
                "Cannot limit fields, expected fields list to be a string, array, null or false."
            );
        }

        // trim the fields, then flip them so they can be used to limit entity fields by key
        return array_intersect_key($entity, array_flip(array_map('trim', $fields)));
    }

    /**
     * Filters out quotation marks from values in a parameters array according to the keys.
     * @param $data the data
     * @param array $keys each key should reference an array within data for which the values
     * we be search replacing the quotation marks
     * @return mixed
     */
    public function filterOutQuotation($data, array $keys = [])
    {
        if ($data) {
            foreach ($keys as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $data[$key] = array_filter(
                        $data[$key],
                        function ($value) {
                            $replaced = preg_replace('/([\'|"|\x{0027}])/u', '', $value);
                            return strlen(trim($replaced)) > 0;
                        }
                    );
                }
            }
        }
        return $data;
    }

    /**
     * Override process to still return the body if a single empty parameter
     * @param mixed $request
     * @return array|mixed|object|string
     */
    protected function processBodyContent($request)
    {
        $content = $request->getContent();

        // JSON content? decode and return it.
        if ($this->requestHasContentType($request, self::CONTENT_TYPE_JSON)) {
            return $this->jsonDecode($request->getContent());
        }

        parse_str($content, $parsedParams);

        // If parse_str fails to decode
        if (! is_array($parsedParams) || empty($parsedParams)
        ) {
            return $content;
        }

        return $parsedParams;
    }
}
