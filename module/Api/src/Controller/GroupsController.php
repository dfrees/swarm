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
use Groups\Model\Config;
use Laminas\Http\Request;
use Laminas\View\Model\JsonModel;

/**
 * Swarm Groups
 */
class GroupsController extends AbstractApiController
{
    /**
     * Get a list of groups, supporting pagination, fielded queries and a blacklist, filtered by keywords
     * @return mixed
     */
    public function getList()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery(self::FIELDS);
        $version = $this->getEvent()->getRouteMatch()->getParam('version');
        $result  = $this->forward(
            \Groups\Controller\IndexController::class,
            'groups',
            null,
            [
                self::MAX               => $request->getQuery(self::MAX, 100),
                self::AFTER             => $request->getQuery(self::AFTER),
                self::KEYWORDS          => $request->getQuery(self::KEYWORDS),
                self::IGNORE_EXCLUDE_LIST => $request->getQuery(self::IGNORE_EXCLUDE_LIST),
                self::FIELDS            => is_string($fields) ? explode(',', $fields) : $fields,
                self::EXCLUDE_PROJECTS  => $version !== 'v2',
            ]
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Get an individual group, allowing for blacklisted groups to be excluded
     * @param   string  $id     Group ID to fetch
     * @return  mixed
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery(self::FIELDS);

        $result = $this->forward(
            \Groups\Controller\IndexController::class,
            'group',
            ['group' => $id],
            [self::IGNORE_EXCLUDE_LIST => $this->getRequest()->getQuery(self::IGNORE_EXCLUDE_LIST)]
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Create a new group
     * @param mixed $data
     * @return JsonModel
     */
    public function create($data)
    {
        $data   = $this->flattenGroupInput($data);
        $data   = $this->filterOutQuotation($data);
        $result = $this->forward(
            \Groups\Controller\IndexController::class,
            'add',
            ['idFromName' => false],
            null,
            $data
        );

        if (!$result->getVariable(self::IS_VALID)) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Modify part of an existing group, replacing the content of any field names provided with the new values
     * @param mixed $data
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);
        $data   = $this->flattenGroupInput($data);
        $data   = $this->filterOutQuotation($data, ['Users', 'Owners', 'Subgroups']);
        $result = $this->forward(\Groups\Controller\IndexController::class, 'edit', ['group' => $id], null, $data);

        if (!$result->getVariable(self::IS_VALID)) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Delete an existing group
     * @param mixed $id
     * @return JsonModel
     */
    public function delete($id)
    {
        $result = $this->forward(\Groups\Controller\IndexController::class, 'delete', ['group' => $id]);

        if (!$result->getVariable(self::IS_VALID)) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of group data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);
        $group = $model->getVariable('group');

        if ($group) {
            $model->setVariable('group', $this->normalizeGroup($group, $limitEntityFields));
        }

        // if a list of groups is present, normalize each one
        $groups = $model->getVariable('groups');
        if ($groups) {
            foreach ($groups as $key => $group) {
                $groups[$key] = $this->normalizeGroup($group, $limitEntityFields);
            }

            $model->setVariable('groups', $groups);
        }

        return $model;
    }

    protected function normalizeGroup($group, $limitEntityFields = null)
    {
        $group = $this->sortEntityFields($group);
        unset(
            $group['name'],
            $group['description'],
            $group[Config::FIELD_EMAIL_ADDRESS],
            $group[Config::FIELD_USE_MAILING_LIST],
            $group[Config::FIELD_EMAIL_FLAGS],
            $group['isEmailEnabled'],
            $group['notificationSettings'],
            $group['isMember'],
            $group['isInGroup'],
            $group['memberCount'],
            $group['ownerAvatars']
        );

        // move config to the end and sub-sort
        $config = isset($group['config']) && is_array($group['config']) ? $group['config'] : [];
        unset($group['config'], $config['id']);
        $group['config'] = $this->sortEntityFields($config);

        return $this->limitEntityFields($group, $limitEntityFields);
    }

    protected function flattenGroupInput($data)
    {
        if (isset($data['config'])) {
            $data += array_intersect_key(
                $data['config'],
                array_flip(
                    [
                        'name',
                        'description',
                        Config::FIELD_EMAIL_FLAGS,
                        Config::FIELD_EMAIL_ADDRESS,
                        Config::FIELD_USE_MAILING_LIST
                    ]
                )
            );
            unset($data['config']);
        }

        return $data;
    }
}
