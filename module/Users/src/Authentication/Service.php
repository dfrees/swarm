<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Authentication;

use Application\Factory\InvokableService;
use Application\Session\SwarmSession;
use Interop\Container\ContainerInterface;
use Laminas\Authentication\AuthenticationService;
use Users\Authentication\Storage\BasicAuth;
use Users\Authentication\Storage\Session;

/**
 * Users authentication service
 * @package Users\Authentication
 */
class Service extends AuthenticationService implements InvokableService
{
    // The name for this service
    const AUTH = 'auth';
    // Key for passing storage in options
    const STORAGE = 'storage';

    /**
     * Service constructor.
     * @param ContainerInterface    $services   application services
     * @param array|null            $options    options may contain an alternative storage option specified with
     *                                          $options[Service::STORAGE => <storage option>]
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        // always use basic-auth credentials if they are specified
        // note: credentials, both basic and session, are not validated here, only retrieved
        if (isset($options[self::STORAGE])) {
            $storage = $options[self::STORAGE];
        } else {
            $storage = new BasicAuth($services->get('request'));
            $storage = $storage->read()
                ? $storage
                : new Session(null, null, $services->get(SwarmSession::SESSION));
        }
        parent::__construct($storage);
    }
}
