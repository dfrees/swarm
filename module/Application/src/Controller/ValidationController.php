<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Controller;

use Laminas\Validator\EmailAddress;
use Laminas\View\Model\JsonModel;

/**
 * Class ValidationController
 *
 * This is a placeholder for any validation actions that will be used by ajax front end calls. Currently
 * supported validations are:
 *
 *  - email address
 *
 * @package Application\Controller
 */
class ValidationController extends AbstractIndexController
{
    public function emailAddressAction()
    {
        // Get any configured validation
        $config                 = $this->services->get('config');
        $emailValidationOptions = isset($config['mail']['validator']['options'])
            ? $config['mail']['validator']['options'] : [];

        // Allow post and get
        $emailAddress = $this->getRequest()->isPost()
            ? $this->getRequest()->getPost('emailAddress')
            : $this->getRequest()->getQuery('emailAddress');

        $validator = new EmailAddress($emailValidationOptions);
        return new JsonModel(
            ["valid" => $validator->isValid($emailAddress), "messages" => $validator->getMessages()]
        );
    }
}
