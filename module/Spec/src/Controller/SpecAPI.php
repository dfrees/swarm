<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Spec\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Permissions\Permissions;
use P4\Connection\Exception\CommandException;
use Spec\Model\ISpec;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Exception;

/**
 * Class SpecAPI, API controller for P4 spec information
 * @package Spec\Controller
 */
class SpecAPI extends AbstractRestfulController
{

    /**
     * Gets the spec fields from the spec type in the path, for example
     *
     * http://localhost/api/<version>/specs/jobs/fields (optional fields parameter to limit fields returned)
     *
     * Authentication is required, non authenticated requests will result in a 401 Unauthorized error
     *
     * Example of success:
     *
     * {
                "error": null,
                "messages": [],
                "data": {
                    "job": {
                        "Job": {
                            "code": "101",
                            "dataType": "word",
                            "displayLength": "32",
                            "fieldType": "required"
                        },
                        "Status": {
                            "code": "102",
                            "dataType": "select",
                            "displayLength": "10",
                            "fieldType": "required",
                            "options": [
                                "open",
                                "suspended",
                                "fixed",
                                "closed"
                            ],
                            "default": "open"
                        },
                        ... more fields
                    }
                }
            }
     * @return JsonModel
     */
    public function fieldsAction()
    {
        $specArray = null;
        $errors    = null;
        $specType  = null;
        $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
        try {
            // Fail early if not authenticated
            $this->services->get(Services::CONFIG_CHECK)->enforce([Permissions::AUTHENTICATED]);
            $fields     = $this->getRequest()->getQuery(IRequest::FIELDS);
            $specType   = $this->getEvent()->getRouteMatch()->getParam(ISpec::TYPE);
            $specDao    = $this->services->get(IDao::SPEC_DAO);
            $specFields = $specDao->fetch($specType, $p4Admin)->getFields();
            $specArray  = $this->limitFields($specFields, $fields);
        } catch (CommandException $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_400, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        } catch (Exception $e) {
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success([$specType => $specArray]);
        }
        return $json;
    }
}
