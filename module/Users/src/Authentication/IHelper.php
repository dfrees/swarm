<?php
/**
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Authentication;

use Application\Config\ConfigException;
use P4\Connection\ConnectionInterface;
use P4\Spec\PluralAbstract;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;

/**
 * Interface IHelper describing the responsibilities of a service to help with authentication
 * @package Users\Authentication
 */
interface IHelper
{
    const COOKIE_LIFETIME            = 'cookie_lifetime';
    const REMEMBERED_COOKIE_LIFETIME = 'remembered_cookie_lifetime';
    const REMEMBER                   = 'remember';
    const SESSION                    = 'session';
    const USERNAME                   = 'username';
    const USER                       = 'user';
    const PASSWORD                   = 'password';
    const ADAPTER                    = 'adapter';
    const ERROR                      = 'error';
    const IS_VALID                   = 'isValid';
    const AUTH_USER                  = 'authUser';
    const SAML_REQUEST               = 'SAMLResponse';
    const RELAY_STATE                = 'RelayState';
    const LOG_ID                     = 'Users:Authentication:Helper:: ';
    const PARAM_REDIRECT             = 'redirect';
    const BASE_URL                   = 'baseURL';
    const PROTOCOL                   = 'protocol';
    const HTTPS                      = 'https';
    const URL                        = 'url';

    const CODE_LOGIN_SUCCESSFUL  = 'user-login-successful';
    const TEXT_LOGIN_SUCCESSFUL  = 'User logged in.';
    const CODE_LOGIN_INCORRECT   = 'user-login-incorrect';
    const TEXT_LOGIN_INCORRECT   = 'User name or password incorrect.';
    const CODE_SWARM_CONNECTION  = 'swarm-connection-error';
    const TEXT_SWARM_CONNECTION  = "An error has occurred with the P4 admin connection '[%s]'";
    const CODE_LOGIN_MISSING     = 'user-login-missing-data';
    const TEXT_LOGIN_MISSING     = 'User name and password must be provided.';
    const CODE_LOGOUT_ERROR      = 'user-logout-error';
    const CODE_LOGOUT_SUCCESSFUL = 'user-logged-out';
    const TEXT_LOGOUT_SUCCESSFUL = 'Successful Logout.';
    const CODE_NOT_LOGGED_IN     = 'user-not-logged-in';
    const TEXT_NOT_LOGGED_IN     = 'User not logged in';
    const CODE_REQUIRE_LOGIN     = 'user-require-login';
    const TEXT_REQUIRE_LOGIN     = 'Required to login';
    const USER_ERROR             = 'user-error';

    const SERVICE_P4_USER_NOT_CREATED = "Service with name \"p4_user\" could not be created";

    /**
     * Get authenticated user object and invalidate user cache if
     * authenticated user is not in cache - this most likely means
     * that user has been added to Perforce but the form-commit
     * user trigger was not fired
     * @param string    $authUser the user identifier
     * @param ConnectionInterface $p4Admin admin connection
     * @return PluralAbstract
     */
    public function invalidateCache($authUser, ConnectionInterface $p4Admin);

    /**
     * Attempts to authenticate each candidate in turn breaking at the first
     * success.
     * @param string $userName the password
     * @param string $password the user name
     * @param bool   $saml whether to use Saml
     * @return array results
     * @throws ConfigException
     * @see getLoginCandidates()
     */
    public function authenticateCandidates($userName, $password, $saml = false);

    /**
     * Gets a list of login candidates. In most cases it will simply be the user name. If the user name
     * happens to represent an email address (contains '@') then the list returned will contain all the
     * user ids that match that email address. Any user names that are blocked by configuration settings
     * are filtered out. Candidates returned will be the values on the user spec that have been found
     * based on the user name or email provided.
     * @param mixed    $userName   user name provided to the request
     * @return array    list of candidate users
     * @throws ConfigException
     */
    public function getLoginCandidates($userName) : array;

    /**
     * Determines and sets cookie information for a successful login.
     * @param array $data containing details relevant to setting cookies
     * @throws ConfigException
     */
    public function setCookieInformation($data);

    /**
     * Builds an error message for failed login based on whether it was a user
     * issue or a Swarm configured user issue.
     * @param mixed                 $response
     * @param ConnectionInterface   $p4Admin
     * @param string                $userName
     * @return null
     */
    public function createErrorMessage($response, ConnectionInterface $p4Admin, $userName);

    /**
     * Handles the callback from an IDP to authenticate the Saml response
     * with the P4 connection (and hence the configured trigger)
     * @return array results
     */
    public function handleSamlResponse();

    /**
     * Checks the server configuration to see if SSO is enabled.
     * @param mixed $serverId the server id to test.
     * @return string (enabled|disabled|optional) checks sso if present else sso_enabled server configuration
     * @throws ConfigException
     */
    public function getSSO($serverId);

    /**
     * Handles SAML login using the PHP SAML library to redirect to the IDP.
     * @param mixed     $request    the current request
     * @return JsonModel returns a model with the URL for redirect if a redirect parameter on the request
     * specifies false
     */
    public function handleSamlLogin($request);

    /**
     * Gets the referrer that initiated the login request. If that referrer is
     * the actual login api call then null is returned to let the caller decide
     * where to go to avoid a potential looping call between Swarm and an IDP.
     * @return string the value of the referrer if set and not the API call path,
     * otherwise null
     */
    public function getLoginReferrer();

    /**
     * Utility to handle log out for web and api controllers. Will attempt to redirect to the referrer or
     * a custom logout page unless the parameter 'redirect=false' is requested.
     * @param Request   $request    current request
     * @param Response  $response   current response
     * @return Response
     * @throws ConfigException
     */
    public function logout(Request $request, Response $response);
}
