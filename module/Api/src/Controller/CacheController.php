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
use Application\Cache\ICacheStatus;
use Application\Connection\ConnectionFactory;
use Application\Permissions\Permissions;
use InvalidArgumentException;
use Redis\Manager;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;

/**
 * API controller to handle interaction with the Swarm cache
 * @package Api\Controller
 */
class CacheController extends AbstractApiController implements ICacheController
{
    /**
     * Delete a cache based on id. Requires administrator privilege.
     *
     * $id will be used to build an alias '$id-cache' to look up the service to carry
     * out the operation
     *
     * @param mixed     $id     the id of the cache to delete
     * @return mixed|JsonModel
     */
    public function delete($id)
    {
        $this->services->get(Permissions::PERMISSIONS)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
        try {
            // Pass any query params through as options
            $results = $this->services->get($id . self::ALIAS_SUFFIX)
                ->delete($id, $this->getRequest()->getQuery()->toArray());
            $model   = new JsonModel(
                [
                    self::IS_VALID => true,
                    self::MESSAGES => $results
                ]
            );
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
        } catch (\Throwable $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $model = new JsonModel(
                [
                    self::IS_VALID => false,
                    self::MESSAGES => [$e->getMessage()]
                ]
            );
        }
        return $model;
    }

    /**
     * A post call to kick off the integrity check. This requires Admin or great.
     * @return JsonModel
     */
    public function integrityAction()
    {
        $this->services->get(Permissions::PERMISSIONS)->enforce([Permissions::ADMIN, Permissions::AUTHENTICATED]);
        $response  = [
            self::IS_VALID => true,
        ];
        $options   = $this->getRequest()->getQuery()->toArray();
        $id        = $this->getEvent()->getRouteMatch()->getParam('id');
        $serviceId = $id.self::ALIAS_SUFFIX;
        // Set the user into the options.
        $options[Manager::USER_ID] = $this->services->get(ConnectionFactory::P4_USER)->getUser();
        try {
            $response[self::MESSAGES] = $this->services->get($serviceId)->queueCacheIntegrityTask($id, $options);
            $response[self::DATA]     = [
                self::STATE => ICacheStatus::STATUS_QUEUED,
                self::PROGRESS => false
            ];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
        } catch (InvalidArgumentException $err) {
            $response[self::IS_VALID] = false;
            $response[self::MESSAGES] = $err->getMessage();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        return new JsonModel($response);
    }

    /**
     * A endpoint for you to get the status of a given context of cache you want to see
     * the status of.
     *
     * @return JsonModel
     */
    public function integrityStatusAction()
    {
        $id       = $this->getEvent()->getRouteMatch()->getParam('id');
        $response = [
            self::IS_VALID => true
        ];
        $options  = $this->getRequest()->getQuery()->toArray();
        try {
            $response[self::DATA] = $this->services->get($id . self::ALIAS_SUFFIX)->verifyStatus($id, $options);
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
        } catch (InvalidArgumentException $err) {
            $response[self::IS_VALID] = false;
            $response[self::MESSAGES] = $err->getMessage();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        return new JsonModel($response);
    }
}
