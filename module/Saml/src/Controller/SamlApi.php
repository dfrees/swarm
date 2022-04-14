<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Saml\Controller;

use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Config\Services;
use Laminas\View\Model\JsonModel;

class SamlApi extends AbstractRestfulController implements IRequest
{
    /**
     * Process the login. This is basically a wrapper around the auth helper to return a model in the new api format.
     * @return JsonModel
     */
    public function loginAction()
    {
        $authHelper = $this->services->get(Services::AUTH_HELPER);
        $jsonModel  = $authHelper->handleSamlLogin($this->getRequest());
        $data       = $jsonModel->getVariables();
        $jsonModel->clearVariables();
        $jsonModel->setVariables(
            [
                'data'     => $data,
                'messages' => [],
                'error'    => null
            ]
        );
        return $jsonModel;
    }
}
