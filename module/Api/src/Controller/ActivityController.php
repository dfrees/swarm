<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Application\Helper\BooleanHelper;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Laminas\InputFilter\InputFilter;
use Laminas\Filter\StringTrim;
use Activity\Model\Activity;

/**
 * API controller to handle requests related to Swarm activity
 * @package Api\Controller
 */
class ActivityController extends AbstractApiController
{
    /**
     * Create a new activity key with provided data, limiting the fields/values that can be provided
     * @param mixed $data
     * @return mixed|JsonModel|null
     */
    public function create($data)
    {
        $services = $this->services;
        // Must be authenticated to use this service
        $services->get('permissions')->enforce('authenticated');

        // only allow expected inputs
        $data = array_intersect_key(
            $data,
            array_flip(['action', 'change', 'description', 'link', 'streams', 'target', 'topic', 'type', 'user'])
        );

        $filter = $this->getActivityFilter();
        $filter->setData($data);

        $isValid = $filter->isValid();
        $model   = null;

        if ($isValid) {
            $p4Admin  = $this->services->get('p4_admin');
            $activity = new Activity($p4Admin);
            $activity->set($filter->getValues())->save();
            $model = $this->prepareSuccessModel(['activity' => $activity->toArray()]);
        } else {
            // Unless the filter specifically set forbidden set the response code to the general 400
            if ($this->getResponse()->getStatusCode() !== Response::STATUS_CODE_403) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
            $jsonModel = new JsonModel(
                [
                    'isValid'   => false,
                    'messages'  => $filter->getMessages(),
                    'activity'  => null,
                ]
            );
            $model     = $this->prepareErrorModel($jsonModel);
        }
        return $model;
    }

    /**
     * Get a list of activity, with pagination support, optionally filtered by changelist number and  activity type.
     * @return mixed|JsonModel
     */
    public function getList()
    {
        $request     = $this->getRequest();
        $fields      = $request->getQuery(self::FIELDS);
        $stream      = $request->getQuery(self::STREAM);
        $version     = $this->getEvent()->getRouteMatch()->getParam('version');
        $disableHtml = BooleanHelper::isTrue($request->getQuery('disableHtml', true));

        $result = $this->forward(
            \Activity\Controller\IndexController::class,
            'activityData',
            null,
            [
                'stream'               => $stream,
                'change'               => $request->getQuery(self::CHANGE),
                'type'                 => $request->getQuery(self::TYPE),
                'max'                  => $request->getQuery(self::MAX, 100),
                'after'                => $request->getQuery(self::AFTER),
                'disableHtml'          => $disableHtml,
                'excludeProjectGroups' => version_compare($version, 'v4', '>='),
            ]
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Extends parent to provide special preparation of activity data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits activity entries to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // detect if activity contains an individual event or a stream of events and normalize appropriately
        $activity = $model->getVariable('activity');
        if ($activity) {
            if (isset($activity['id'])) {
                $model->setVariable('activity', $this->normalizeActivity($activity, $limitEntityFields));
            } elseif (isset($activity[0]['id'])) {
                $activities = [];
                foreach ($activity as $key => $entry) {
                    $activities[$key] = $this->normalizeActivity($entry, $limitEntityFields);
                }
                $model->setVariable('activity', $activities);
            }
        }

        return $model;
    }

    protected function normalizeActivity($activity, $limitEntityFields = null)
    {
        // exit early if an invalid activity entry is detected
        if (!isset($activity['id'])) {
            return [];
        }

        unset($activity['avatar']);

        $activity = $this->limitEntityFields($activity, $limitEntityFields);
        return $this->sortEntityFields($activity);
    }

    /**
     * Gets a filter to validate activity data
     * @return InputFilter
     */
    protected function getActivityFilter()
    {
        $filter     = new InputFilter;
        $controller = $this;
        $filter->add(
            [
                'name'          => 'type',
                'filters'       => [new StringTrim()],
                'validators' => [
                    [
                        'name'    => 'StringLength',
                        'options' => [
                            'min' => '1'
                        ],
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'link',
                'filters'       => [new StringTrim()],
                'required'      => false,
                'validators' => [
                    [
                        'name'    => 'StringLength',
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'user',
                'filters'       => [new StringTrim()],
                'validators'    => [
                    [
                        'name'                   => 'StringLength',
                        'break_chain_on_failure' => true,
                        'options'                => [
                            'min' => '1'
                        ],
                    ],
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($controller) {
                                $services = $controller->services;
                                if ($services->get('permissions')->isOne(['admin', 'super'])) {
                                    $retVal = true;
                                } else {
                                    if ($services->get('user')->getId() === $value) {
                                        $retVal = true;
                                    } else {
                                        $controller->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                                        $retVal = 'You must have at least admin level privileges to create an '
                                            . 'activity for a user other than yourself.';
                                    }
                                }
                                return $retVal;
                            }
                        ]
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'action',
                'filters'       => [new StringTrim()],
                'validators' => [
                    [
                        'name'    => 'StringLength',
                        'options' => [
                            'min' => '1'
                        ],
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'target',
                'filters'       => [new StringTrim()],
                'validators' => [
                    [
                        'name'    => 'StringLength',
                        'options' => [
                            'min' => '1'
                        ],
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'description',
                'filters'       => [new StringTrim()],
                'required'      => false,
                'validators' => [
                    [
                        'name'    => 'StringLength',
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'topic',
                'filters'       => [new StringTrim()],
                'required'      => false,
                'validators' => [
                    [
                        'name'    => 'StringLength',
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'time',
                'required'      => false,
                'validators'    => [['name' => 'Digits']]
            ]
        );

        $filter->add(
            [
                'name'          => 'streams',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (!is_array($value)) {
                                    return 'Streams must be an array.';
                                }

                                if (count($value) !== count(array_filter($value, 'is_string'))) {
                                    return 'Only string values are permitted in the streams array.';
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );

        $filter->add(
            [
                'name'          => 'change',
                'required'      => false,
                'validators'    => [['name' => 'Digits']]
            ]
        );

        return $filter;
    }
}
