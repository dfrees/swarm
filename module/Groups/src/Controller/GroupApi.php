<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Groups\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Permissions\ConfigCheck;
use Groups\Model\Config;
use Groups\Model\IGroup;
use Laminas\View\Model\JsonModel;
use Exception;
use Laminas\Http\Response;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Spec\Group as Group;
use Projects\Model\Project as ProjectModel;

/**
 * Controller to handle v10+ API calls to the groups endpoint
 */
class GroupApi extends AbstractRestfulController implements IGroupApi
{
    const DATA_GROUPS = 'groups';
    /**
     * Get a list of groups.
     * Example Success Response
     * {
     *      "error": null,
     *      "messages": [],
     *      "data": {
     *              "groups": [
     *                {
     *                       "id": "testers",
     *                       "maxResults": null,
     *                       "maxScanRows": null,
     *                       "maxLockTime": null,
     *                       "maxOpenFiles": "unset",
     *                       "timeout": 43200,
     *                       "passwordTimeout": null,
     *                       "ldapConfig": null,
     *                       "ldapSearchQuery": null,
     *                       "ldapUserAttribute": null,
     *                       "ldapUserDNAttribute": null,
     *                       "subgroups": [],
     *                       "owners": [],
     *                       "users": [
     *                       "Randall_Scott"
     *                       ],
     *                       "name": "testers",
     *                       "description": null,
     *                       "useMailingList": null,
     *                       "emailAddress": null,
     *                       "emailFlags": [],
     *                       "group_notification_settings": null
     *                  }
     *               ]
     *       }
     * }
     *  Query parameters supported:
     *  ids    - filter data by singe or multiple groups
     *  fields - response contains specific field
     *  expand - Whether to expand subgroup in response.
     *
     * Example error response
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something went wrong"
     *       }
     *   ],
     *   "data": null
     * }
     * @return JsonModel
     */
    public function getList() : JsonModel
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $errors  = null;
        $request = $this->getRequest();
        $query   = $request->getQuery();
        $groups  = [];
        try {
            $options = $query->toArray();
            $filter  = $this->services->get(Services::GET_GROUPS_FILTER);
            $filter->setData($options);
            if ($filter->isValid()) {
                $options = $filter->getValues();
                $dao     = $this->services->get(IDao::GROUP_DAO);
                $fields  = $query->get(IRequest::FIELDS);
                $fields  = is_string($fields) ? explode(',', $fields) : $fields;
                $ids     = $options[IGroup::FETCH_BY_ID]??null;
                if ($ids) {
                    $options[Group::FETCH_BY_IDS] = $ids;
                }
                $groups = $dao->fetchAll($options, $p4Admin);
                $groups = $this->normaliseGroupDataFields($groups, $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (Exception $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_GROUPS => $groups,
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Filter out any invalid fields from the Groups and return results
     * according to requested fields
     * @param $groups
     * @param $fields
     * @return array
     * @throws ConfigException
     */
    protected function normaliseGroupDataFields($groups, $fields): array
    {
        // prepare list of fields to include in result
        $groups      = $groups->getArrayCopy();
        $groupFields = $groups ? current($groups)->getFields() : [];
        $extraFields = IGroup::CONFIG_FIELDS;
        if (isset($fields)) {
            $fields = array_replace(
                $fields,
                array_fill_keys(
                    array_keys($fields, IGroup::FIELD_ID),
                    IGroup::GROUP
                )
            );
            $fields = array_map(
                function ($fieldName) {
                    return ucfirst($fieldName);
                },
                $fields
            );
        }
        $fields = (array) $fields ?: array_keys(array_flip(array_merge($groupFields, $extraFields)));
        // build the result set
        $result        = [];
        $utf8          = new Utf8Filter;
        $p4            = $this->services->get(ConnectionFactory::P4_ADMIN);
        $caseSensitive = $p4->isCaseSensitive();
        $appConfig     = $this->services->get(IConfigDefinition::CONFIG);
        // Get list of exclude groups
        $mode        =  ConfigManager::getValue($appConfig, IConfigDefinition::MENTIONS_MODE) ?? 'global';
        $excludeList = $mode == 'disabled' ? [] : ConfigManager::getValue(
            $appConfig, IConfigDefinition::MENTIONS_GROUPS_EXCLUDE_LIST
        );
        foreach ($groups as $group) {
            // if we have enabled mentions,
            // and the group id is on the groups exclude list - skip this group
            // check against all values in group exclude list
            // skip project group
            if (ConfigCheck::isExcluded($group->getId(), $excludeList, $caseSensitive)
                ||
                ProjectModel::isProjectName($group->getId())
            ) {
                continue;
            }

            $config = $group->getConfig();
            $data   = [];
            foreach ($fields as $field) {
                $lcField = lcfirst($field);
                if (in_array($lcField, IGroup::CONFIG_FIELDS)) {
                    $value          = $config->get($lcField);
                    $data[$lcField] = $value;
                } else {
                    // skip invalid fields
                    if (!$group->hasField($field)) {
                        break;
                    }
                    // though unexpected, some fields (Group) can include invalid UTF-8 sequences,
                    // so we filter them, otherwise json encoding could crash with an error
                    $value        = $utf8->filter($group->get($field));
                    $field        = lcfirst($field === ucfirst(IGroup::GROUP) ? IGroup::FIELD_ID : $field);
                    $data[$field] = $value;
                }
            }
            $result[] = $data;
        }
        return $result;
    }
}
