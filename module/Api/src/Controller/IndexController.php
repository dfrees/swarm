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
use Api\IRequest;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;

/**
 * Basic API controller providing a simple version action
 */
class IndexController extends AbstractApiController
{
    const API_BASE     = '/api';
    const API_VERSIONS = [1, 1.1, 1.2, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
    /**
     * Return version info
     * @return  JsonModel
     */
    public function versionAction()
    {
        if (!$this->getRequest()->isGet()) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_405);
            return;
        }

        $data = [
            'version'   => VERSION,
            'year'      => current(explode('.', VERSION_RELEASE)),
        ];

        // include a list of supported api versions for v1.1 and up
        if ($this->getEvent()->getRouteMatch()->getParam(IRequest::VERSION) !== "v1") {
            $data['apiVersions'] = static::API_VERSIONS;
        }

        return new JsonModel($this->sortEntityFields($data));
    }
}
