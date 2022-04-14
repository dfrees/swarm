<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Authentication\Storage;

use Laminas\Authentication\Storage\NonPersistent;
use Laminas\Stdlib\RequestInterface;

/**
 * BasicAuth storage provider can interpret a request and retrieve basic authentication credentials
 *
 * @package Users\Authentication\Storage
 */
class BasicAuth extends NonPersistent
{
    /**
     * Checks the request for basic-auth credentials and writes them to NonPersistent storage if found
     *
     * @param RequestInterface $request
     */
    public function __construct(RequestInterface $request)
    {
        $authHeader = $request->getHeaders('authorization');

        if ($authHeader) {
            $authValue                = $authHeader->getFieldValue();
            list($type, $credentials) = explode(' ', $authValue, 2) + [null, null];

            if (strtolower($type) == 'basic') {
                $credentials               = base64_decode(trim($credentials), true);
                list($username, $password) = explode(':', $credentials, 2) + [null, null];

                $this->write(['id' => $username, 'ticket' => $password]);
            }
        }
    }
}
