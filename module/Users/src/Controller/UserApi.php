<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\View\Helper\Avatar;
use Exception;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Model\Fielded\Iterator;
use P4\Spec\Exception\NotFoundException;
use Redis\Model\IModelDAO;
use Users\Filter\IGetUsers;
use Users\Model\User;

/**
 * Class UserApi
 * @package Users\Controller
 */
class UserApi extends AbstractRestfulController
{
    const DATA_USERS = 'users';

    /**
     * Gets a user
     * Example success response
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "users": [
     *          {
     *              "id": "Joe_Coder",
     *              "type":"standard",
     *              "email":"jcoder@p4demo.com",
     *              "update":"2008/05/30 19:30:36",
     *              "access":"2021/02/11 16:38:47",
     *              "fullName":"Joe_Coder",
     *              "jobView":"status=open Assigned=Joe_Coder"
     *              "password":null,
     *              "authMethod":"perforce",
     *              "reviews":[],
     *          }
     *       ]
     *    }
     * }
     *
     * Query parameters supported:
     *  fields - return only the fields listed
     *
     * Example error response
     *
     * Unauthorized response 401, if require_login is true
     * {
     *   "error": "Unauthorized"
     * }
     *
     * Unknown user response 404
     * {
     *   "error": 404,
     *   "messages": {
     *      "code": 404,
     *      "text": "Cannot fetch user jgarcia. Record does not exist."
     *   },
     *   "data": null
     * }
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @param mixed $id The Project ID
     * @return mixed|JsonModel
     */
    public function get($id): JsonModel
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $dao     = $this->services->get(IModelDAO::USER_DAO);
        $config  = $this->services->get(ConfigManager::CONFIG);
        $errors  = null;
        $query   = $this->getRequest()->getQuery();
        try {
            $userModel = $dao->fetchById($id, $p4Admin);
            $fields    = $query->get(IRequest::FIELDS);
            $fields    = is_string($fields) ? explode(',', $fields) : $fields;
            $users     = $this->normaliseUserDataFields([$userModel], $fields, $config);
        } catch (NotFoundException $e) {
            // User does not exist
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_USERS => $users,
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Get a list of Swarm users, with options to query by users and to limit the fields returned
     * Example success response
     * {
     *  "error": null,
     *  "messages": [],
     *  "data": {
     *        "users": [
     *          {
     *               "id": "superman",
     *               "type": "standard",
     *               "email": "superman@p4demo.com",
     *               "update": "2000/11/02 15:42:37",
     *               "access": "2000/11/11 14:42:32",
     *               "fullName": "Super Man",
     *               "jobView": null,
     *               "password": null,
     *               "authMethod": "perforce",
     *               "reviews": []
     *          },
     *          ...
     *          ...
     *         ]
     *    }
     * }
     *
     * Query parameters supported:
     *  ids    - filter data by singe or multiple user
     *  fields - response contains specific field
     *  ignoreExcludeList - To ignore the user_exclude_list filter.
     *
     * Example error response
     *
     * Unauthorized response 401, if require_login is true
     * {
     *   "error": "Unauthorized"
     * }
     *
     * 500 error response
     * {
     *   "error": 500,
     *   "messages": [
     *       {
     *           "code": 500,
     *           "text": "Something really bad happened"
     *       }
     *   ],
     *   "data": null
     * }
     * @return mixed|JsonModel
     */
    public function getList(): JsonModel
    {
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $errors  = null;
        $request = $this->getRequest();
        $query   = $request->getQuery();
        $users   = [];
        try {
            $options = $query->toArray();
            $fields  = $query->get(IRequest::FIELDS);
            $fields  = is_string($fields) ? explode(',', $fields) : $fields;
            $dao     = $this->services->get(IModelDAO::USER_DAO);
            $config  = $this->services->get(ConfigManager::CONFIG);
            $ids     = $options[IGetUsers::IDS]??null;
            // fetchAll uses FETCH_BY_NAME not 'id'
            $userOptions[User::FETCH_BY_NAME] = $ids;
            if (isset($options[IRequest::IGNORE_EXCLUDE_LIST])) {
                $userOptions[IRequest::IGNORE_EXCLUDE_LIST] = $options[IRequest::IGNORE_EXCLUDE_LIST];
            }
            $usersData = $dao->fetchAll($userOptions, $p4Admin);
            if (count($usersData)<count((array)$ids)) {
                // There were unknown users in the request, stub them out as id/name
                foreach (array_diff((array)$ids, array_keys($usersData->toArray())) as $missingUser) {
                    $usersData[$missingUser] = (new User())->set(['User' => $missingUser, 'FullName' => $missingUser]);
                }
            }
            $users = $this->normaliseUserDataFields($usersData, $fields, $config);
        } catch (Exception $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_USERS => $users,
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Convert the data in an array of models into a dataset that contains name/value pairs that
     * comply with our standard api format. This will result in fields being set to their camelCase
     * equivalent and the primary key (User) renamed to (id).
     * @param $usersData  array an array of User models
     * @param $fields     array a list of fields to be included
     * @param $config     array the current effective swarm configuration
     * @return array            the normalised key/value pair user data
     * @throws ConfigException
     */
    protected function normaliseUserDataFields($usersData, $fields, $config)
    {
        return $this->limitFieldsForAll(
            $this->filterAnyInvalidField(
                $usersData,
                $fields,
                $config
            ),
            isset($fields)  ? array_replace(
                $fields,
                array_fill_keys(
                    array_keys(
                        array_map(
                            function ($fieldName) {
                                return $fieldName == IGetUsers::USER ? strtolower($fieldName) : $fieldName;
                            },
                            $fields
                        ), strtolower(IGetUsers::USER)
                    ),
                    IGetUsers::ID
                )
            ) : $fields
        );
    }

    /**
     * Filter out any invalid fields from the users
     * @param Iterator|array $users         iterator of users
     * @param array          $fields        fields need to be display in output array
     * @param array          $config        This is the config manager
     * @return array
     * @throws ConfigException
     */
    private function filterAnyInvalidField($users, $fields, $config): array
    {
        $usersArray = [];
        $utf8       = new Utf8Filter;
        $fields     = is_string($fields) ? explode(',', $fields) : $fields;
        if (isset($fields)) {
            $fields = array_replace(
                $fields,
                array_fill_keys(
                    array_keys($fields, IGetUsers::ID),
                    IGetUsers::USER
                )
            );
            $fields = array_map(
                function ($fieldName) {
                    return $fieldName !== IGetUsers::AVATAR ? ucfirst($fieldName) : $fieldName;
                },
                $fields
            );
        }
        foreach ($users as $user) {
            // though unexpected, some fields (User or FullName) can include invalid UTF-8 sequences
            // so we filter them, otherwise json encoding could crash with an error
            $data   = [];
            $fields = $fields ? $fields : $user->getFields();
            foreach ($fields as $field) {
                try {
                    if (strtolower($field) === IGetUsers::AVATAR) {
                        $data[strtolower($field)] = Avatar::getAvatarDetails(
                            $config, $user->getId(), $user->getEmail()
                        );
                    } else {
                        $data[$field]    = $utf8->filter($user->get($field));
                        $newField        = lcfirst($field === IGetUsers::USER ? IGetUsers::ID : $field);
                        $data[$newField] = $data[$field];
                        unset($data[$field]);
                    }
                } catch (\P4\Exception $e) {
                    // We have encountered fields we don't know about.
                    $logger = $this->services->get(SwarmLogger::SERVICE);
                    $logger->info("UserApi: We have encountered invalid field: $field");
                }
            }
            $usersArray[] = $data;
        }
        return array_values($usersArray);
    }
}
