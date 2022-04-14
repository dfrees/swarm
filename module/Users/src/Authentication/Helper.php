<?php
/**
 * Functions to help with login.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Authentication;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Model\ServicesModelTrait;
use Interop\Container\ContainerInterface;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\ServiceNotFoundException;
use Record\Exception\NotFoundException;
use Laminas\Authentication\Result;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Exception;
use InvalidArgumentException;
use Users\Filter\User as UserFilter;

class Helper implements InvokableService, IHelper
{
    const LOG_PREFIX = Helper::class;
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     */
    public function invalidateCache($authUser, ConnectionInterface $p4Admin)
    {
        $userDao = $this->services->get(IModelDAO::USER_DAO);
        // Get authenticated user object and invalidate user cache if
        // authenticated user is not in cache - this most likely means
        // that user has been added to Perforce but the form-commit
        // user trigger was not fired
        if (!$userDao->exists($authUser, $p4Admin)) {
            try {
                $userDao->fetchByIdAndSet($authUser);
            } catch (ServiceNotFoundException $e) {
                // No cache? nothing to invalidate
            }
        }
        return $userDao->fetchById($authUser, $p4Admin);
    }

    /**
     * @inheritDoc
     */
    public function authenticateCandidates($userName, $password, $saml = false)
    {
        $services = $this->services;
        $config   = $services->get(ConfigManager::CONFIG);
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $auth     = $services->get(Service::AUTH);
        $logger   = $services->get(SwarmLogger::SERVICE);
        $authUser = null;
        $adapter  = null;
        $error    = null;
        // loop through all login candidates, stop on first success
        foreach ($this->getLoginCandidates($userName) as $user) {
            $logger->info(sprintf("[%s]: Trying to log in as [%s]", self::LOG_PREFIX, $user));
            $adapter = new Adapter($user, $password, $p4Admin, $config, $saml);

            try {
                // break if we hit a working candidate
                if ($auth->authenticate($adapter)->getCode() === Result::SUCCESS) {
                    $authUser = $user;
                    $logger->info(sprintf("[%s]: Authenticated user [%s]", self::LOG_PREFIX, $user));
                    break;
                }
            } catch (Exception $e) {
                // we skip any failed accounts; better luck next try :)
                $logger->info(
                    sprintf(
                        "[%s]: Attempt to log in as [%s] failed with [%s]",
                        self::LOG_PREFIX,
                        $user,
                        $e->getMessage()
                    )
                );
                $error = $e->getMessage();
            }
        }
        return [
            self::AUTH_USER => $authUser,
            self::USERNAME  => $userName,
            self::ADAPTER   => $adapter,
            self::IS_VALID  => $authUser !== null,
            self::ERROR     => $error
        ];
    }

    /**
     * @inheritDoc
     */
    public function getLoginCandidates($userName) : array
    {
        $services = $this->services;
        $config   = $services->get(ConfigManager::CONFIG);
        $userDao  = $services->get(IModelDAO::USER_DAO);
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $blocked  = ConfigManager::getValue($config, ConfigManager::SECURITY_PREVENT_LOGIN, []);
        $logger   = $services->get(SwarmLogger::SERVICE);

        // If we are passed an email (anything with an @) find all matching accounts.
        // Otherwise, simply fetch the passed id.
        $candidates = [];
        $logger->info(sprintf("[%s]: Getting login candidates for [%s]", self::LOG_PREFIX, $userName));
        if (strpos($userName, '@')) {
            foreach ($userDao->fetchAll([], $p4Admin) as $candidate) {
                $logger->trace(
                    sprintf(
                        "[%s]: Looking for match user email [%s], user id [%s]",
                        self::LOG_PREFIX,
                        $candidate->getEmail(),
                        $candidate->getId()
                    )
                );
                // For email, we always use a case-insensitive comparison
                if (mb_strtolower($candidate->getEmail()) === mb_strtolower($userName)) {
                    $logger->info(
                        sprintf(
                            "[%s]: Match found for email [%s], user id [%s]",
                            self::LOG_PREFIX,
                            $userName,
                            $candidate->getId()
                        )
                    );
                    $candidates[] = $candidate->getId();
                }
            }
        } else {
            try {
                // If we are provided with a user name it will be equal to what the user
                // entered. We want to look up the User value from the spec and use that
                // instead so that for example issues with case sensitivity are handled.
                // For example on a case insensitive server where a user had been created
                // with a value of 'Bruno' logging in as 'bruno', 'Bruno', or 'BRUNO' should
                // all result in 'Bruno' as the value to use for the user connection. That way it
                // should match fields such as author and participants that have been
                // added to reviews. This will also then behave in the way as if an email had
                // been provided
                $filter       = new UserFilter($services);
                $candidates[] = $filter->filter($userName);
            } catch (InvalidArgumentException $e) {
                // Invalid user, auth module will generate the response
                $candidates[] = $userName;
            }
        }
        return array_diff($candidates, $blocked);
    }

    /**
     * @inheritDoc
     */
    public function setCookieInformation($data)
    {
        $services = $this->services;
        $path     = $data[self::BASE_URL];
        $isHTTPS  = isset($data[self::HTTPS]);
        $config   = $services->get(ConfigManager::CONFIG);
        $session  = $services->get(self::SESSION);
        // Remember was an option passed from the 'old' login. Not sure if it will
        // be kept so we check to see if we have it
        $remember = isset($data[self::REMEMBER]) ?: null;
        // The 'remember' setting may have changed; ensure the session cookie
        // is set for the appropriate lifetime before we regenerate its id
        $config[self::SESSION] +=
            [self::REMEMBERED_COOKIE_LIFETIME => null, self::COOKIE_LIFETIME => null];
        $session->getConfig()->setStorageOption(
            self::COOKIE_LIFETIME,
            $config[self::SESSION][$remember ? self::REMEMBERED_COOKIE_LIFETIME : self::COOKIE_LIFETIME]
        );
        // Regenerate our id since they logged in; this avoids session fixation and also
        // allows any lifetime changes to take affect.
        // As the session was already started there's a Set-Cookie entry for it
        // and regenerating would normally add a second. to avoid two entries (harmless
        // but off-putting) we first clear all Set-Cookie headers.
        header_remove('Set-Cookie');
        session_regenerate_id(true);
        $strict = ConfigManager::getValue($config, ConfigManager::SECURITY_HTTPS_STRICT, false);
        if ($remember) {
            // This cookie sticks around for a year. We don't use the session lifetime
            // here as you want the user id to fill in when the session expires (if remember
            // me was checked). if we shared lifetimes with the session, the user id would
            // never be auto-filled/remembered when you actually needed it.
            $expires = time() + 365 * 24 * 60 * 60;
            headers_sent() ?: setcookie(
                self::REMEMBER,
                $data[self::USERNAME],
                $expires,
                $path,
                '',
                $strict || $isHTTPS,
                true
            );
        } elseif (isset($_COOKIE[self::REMEMBER])) {
            headers_sent() ?: setcookie(
                self::REMEMBER,
                null,
                -1,
                $path,
                '',
                $strict || $isHTTPS,
                true
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function createErrorMessage($response, ConnectionInterface $p4Admin, $userName)
    {
        $services   = $this->services;
        $userDao    = $services->get(IModelDAO::USER_DAO);
        $translator = $services->get(TranslatorFactory::SERVICE);
        $logger     = $services->get(SwarmLogger::SERVICE);
        $error      = null;
        // Login has failed - most likely due to the user supplying bad credentials
        // but there may have been Swarm admin connection changes that if invalid
        // would always stop any user logging in. If we can still use the admin
        // connection then the user details were wrong
        $logger->info($translator->t("Failed login attempt for user '[%s]'", [$userName]));
        try {
            $userDao->fetchById($p4Admin->getUser(), $p4Admin);
            $error = $translator->t("User name or password incorrect.");
        } catch (Exception $le) {
            $logger->err(
                $translator->t(
                    "An error has occurred with the P4 admin connection '[%s]'",
                    [$le->getMessage()]
                )
            );
            $error = $translator->t(
                "There is a configuration problem. Please contact your Swarm administrator."
            );
        }
        $response->setStatusCode(Response::STATUS_CODE_401);
        return $error;
    }

    /**
     * @inheritDoc
     */
    public function handleSamlResponse() : array
    {
        $error    = null;
        $userId   = null;
        $adapter  = null;
        $services = $this->services;
        $logger   = $services->get(SwarmLogger::SERVICE);
        try {
            $saml = $services->get(Services::SAML);
            $logger->debug(sprintf("[%s]: handleSamlResponse processing response", self::LOG_PREFIX));
            // Need to process the response to get the user name
            $saml->processResponse(null);
            $userId = $saml->getNameId();
            $logger->debug(sprintf("[%s]: handleSamlResponse user id [%s]", self::LOG_PREFIX, $userId));
            $errors = $saml->getErrors();
            if (empty($errors)) {
                // We still want to authenticate with candidates in case an email was returned
                // by the IDP
                return $this->authenticateCandidates($userId, '', true);
            } else {
                $logger->err(
                    sprintf(
                        "[%s]: handleSamlResponse has errors [%s]",
                        self::LOG_PREFIX,
                        var_export($errors, true)
                    )
                );
                $error = $saml->getLastErrorReason();
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $logger->err(sprintf("[%s]: An error occurred using Saml login [%s]", self::LOG_PREFIX, $error));
        }
        return [
            self::AUTH_USER => $userId,
            self::USERNAME  => $userId,
            self::ADAPTER   => $adapter,
            self::ERROR     => $error,
            self::IS_VALID  => $error === null
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSSO($serverId)
    {
        $config = $this->services->get(ConfigManager::CONFIG);
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $sso    = ConfigManager::DISABLED;

        try {
            if ($serverId) {
                try {
                    $logger->debug(
                        sprintf("[%s]: Get [%s] for server id [%s]", self::LOG_PREFIX, ConfigManager::SSO, $serverId)
                    );
                    $sso = ConfigManager::getValue(
                        $config,
                        (ConfigManager::P4 . '.' . $serverId . '.' . ConfigManager::SSO),
                        ConfigManager::DISABLED
                    );
                } catch (ConfigException $e) {
                    $logger->debug(
                        sprintf(
                            "[%s]: Get [%s] for server id [%s]",
                            self::LOG_PREFIX,
                            ConfigManager::SSO_ENABLED,
                            $serverId
                        )
                    );
                    $sso = ConfigManager::getValue(
                        $config,
                        (ConfigManager::P4 . '.' . $serverId . '.' . ConfigManager::SSO_ENABLED),
                        false
                    );
                }
            } else {
                $sso =  ConfigManager::getValue($config, ConfigManager::P4_SSO, ConfigManager::DISABLED);
            }
            if (is_bool($sso)) {
                $sso = $sso ? ConfigManager::ENABLED : ConfigManager::DISABLED;
            }
            return $sso;
        } catch (ConfigException $e) {
            if ($e->getCode() === ConfigException::PATH_DOES_NOT_EXIST) {
                // This can be expected
                return $sso;
            }
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function handleSamlLogin($request)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $logger->debug(Helper::LOG_ID . 'getting php-saml library');
        $saml = $this->services->get(Services::SAML);
        $logger->debug(Helper::LOG_ID . 'php-saml library success');
        $logger->debug(Helper::LOG_ID . 'Referrer ' . $request->getServer('HTTP_REFERER'));
        // After the IDP has returned we set up to return to the page
        // that we were on originally. For example if we login from
        // the reviews page we want to end up on the reviews page
        $redirect = $request->getQuery(Helper::PARAM_REDIRECT);
        if ($redirect === false || $redirect === 'false') {
            $logger->debug(Helper::LOG_ID . 'saml login without redirect');
            $url = $saml->login($request->getServer('HTTP_REFERER'), [], false, false, true);
            return new JsonModel(['url' => $url, 'isValid' => true]);
        } else {
            $logger->debug(Helper::LOG_ID . 'login with redirect');
            $saml->login($request->getServer('HTTP_REFERER'));
            new JsonModel(['isValid' => true]);
        }
    }

    /**
     * @inheritDoc
     */
    public function getLoginReferrer()
    {
        $referrer = null;
        if ($_REQUEST && isset($_REQUEST[self::RELAY_STATE])) {
            $loginMatch = preg_match('/.*\/api\/v[0-9]+\/login\/saml*/', $_REQUEST[self::RELAY_STATE]);
            if (!$loginMatch) {
                $referrer = $_REQUEST[self::RELAY_STATE];
            }
        }
        return $referrer;
    }

    /**
     * @inheritDoc
     */
    public function logout(Request $request, Response $response)
    {
        $services = $this->services;
        $logger   = $services->get(SwarmLogger::SERVICE);
        $session  = $services->get(self::SESSION);
        $auth     = $services->get(Service::AUTH);

        // clear identity and all other session data on logout
        // note we need to explicitly restart the session (it's closed by default)
        $session->start();
        $auth->clearIdentity();
        $session->destroy(['send_expire_cookie' => true, 'clear_storage' => true]);
        $session->writeClose();
        $redirect = $request->getQuery(Helper::PARAM_REDIRECT);
        if ($redirect === false || $redirect === 'false') {
            $logger->debug(Helper::LOG_ID . 'logged out without redirect');
            return $response;
        } else {
            $logger->debug(Helper::LOG_ID . 'logging out with redirect');
            return $this->customRedirect($request, $response);
        }
    }

    /**
     * Build a array that can be used in response details with the URL to redirect to for API v10 onwards. For other
     * cases do the redirect as before to preserve backward compatibility
     * @param Response  $response   the response
     * @param Request   $request    the current request
     * @param mixed     $url        the url
     * @return mixed a redirected response for v9 or with a key of 'url' and a value of the URL to redirect to
     */
    private function redirect($url, Response $response, Request $request)
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $logger->debug(Helper::LOG_ID . 'log out redirect url [' . $url . ']');
        if ($this->isLegacyRedirect($request)) {
            $response->getHeaders()->addHeaderLine('Location', $url);
            $response->setStatusCode(302);
            return $response;
        } else {
            return [self::URL => $url];
        }
    }

    /**
     * Tests if the request should be redirected (legacy)
     * @param Request $request
     * @return bool true if an API call prior to v10 or from a non-API call (web controller)
     */
    public function isLegacyRedirect(Request $request) : bool
    {
        $legacyApiVersions = ['v1', 'v1.1', 'v1.2', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9'];
        $parts             = explode('/', parse_url($request->getUriString())['path']);
        // Api string could be in two positions depending on whether this is single or multiple P4D
        $isApi = sizeof($parts) >= 3 && ($parts[1] === 'api' || $parts[2] === 'api');
        // Version string could be in two positions depending on whether this is single or multiple P4D
        $isLegacyApi = $isApi
            && (in_array($parts[2], $legacyApiVersions) || in_array($parts[3], $legacyApiVersions));
        // Detect a web controller logout, this will not be an API call and will end in 'logout'
        $isLogout = !$isApi && end($parts) === 'logout';
        return $isLegacyApi || $isLogout;
    }

    /**
     * Redirects on logout to either the referrer or a custom logout url if set in config.
     * @param Request   $request    current request
     * @param Response  $response   current response
     * @return mixed
     * @throws ConfigException
     */
    private function customRedirect(Request $request, Response $response)
    {
        $services     = $this->services;
        $logger       = $services->get(SwarmLogger::SERVICE);
        $config       = $services->get(ConfigManager::CONFIG);
        $customLogout = ConfigManager::getValue($config, ConfigManager::ENVIRONMENT_LOGOUT_URL);

        if ($customLogout) {
            return $this->redirect($customLogout, $response, $request);
        } else {
            $projectDAO = ServicesModelTrait::getProjectDao();
            // If a referrer is set and it appears to point at us; i want to go to there
            $referrer = $request->getServer('HTTP_REFERER');
            if (strpos($referrer, $request->getUriString()) !== false) {
                // Redirecting to here would result in a circular redirect error
                $logger->warn(Helper::LOG_ID . "Averting circular redirect to  $referrer");
                return $response;
            };
            $scheme = $request->getUri()->getScheme();
            $host   = $scheme ? $scheme : 'http' . '://' . $request->getUri()->getHost();
            // Check the url address for project in it if found check project really exist and then check if its private
            $urlParts  = explode('/', parse_url($referrer)['path']);
            $projectID = sizeof($urlParts) >= 3 && $urlParts[1] === 'projects' ? $urlParts[2] : null;
            if ($projectID) {
                $p4Admin = $services->get('p4_admin');
                try {
                    // May contain encoded UTF-8 characters so we need to get the decoded id
                    $projectID = urldecode($projectID);
                    // If we are on project settings we want to direct to '/' as you must be logged in
                    // If the project is private we do not want to redirect to the project on logout
                    $referrer = (isset($urlParts[3]) && $urlParts[3] === 'settings')
                        || $projectDAO->fetchById($projectID, $p4Admin)->isPrivate() ? '/' : $referrer;
                } catch (NotFoundException $e) {
                    $referrer = '/';
                } catch (\InvalidArgumentException $e) {
                    $referrer = '/';
                }
            }
            $logger->debug(Helper::LOG_ID . 'redirect referrer [' . $referrer . ']');
            if ($referrer !== '/' && stripos($referrer, $host) === 0) {
                return $this->redirect($referrer, $response, $request);
            }
        }
        return $this->redirect($request->getBaseUrl() . '/', $response, $request);
    }
}
