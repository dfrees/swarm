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
use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Filter\FormBoolean;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\BasicAuthFailedException;
use Application\Permissions\Exception\UnauthorizedException;
use Exception;
use P4\Connection\Exception\ServiceNotFoundException;
use Users\Authentication\Helper;
use Users\Authentication\IHelper;
use Users\Model\IUser;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Stdlib\Parameters;
use Laminas\View\Model\JsonModel;

/**
 * Class SessionApi. API controller for Sessions
 * @package User\Controller
 */
class SessionApi extends AbstractRestfulController
{

    /**
     * Get the authentication details relating to the current session
     *
     * @return mixed
     * @throws ConfigException
     */
    public function getList()
    {
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        try {
            $user = $this->currentUser();
            return self::success([IUser::USER => [$user]]);
        } catch (UnauthorizedException $error) {
            $config       = $services->get(ConfigManager::CONFIG);
            $requireLogin = ConfigManager::getValue(
                $config,
                ConfigManager::SECURITY_REQUIRE_LOGIN,
                true
            );
            if ($requireLogin) {
                $msg[] = self::buildMessage(IHelper::CODE_REQUIRE_LOGIN, $translator->t(IHelper::TEXT_REQUIRE_LOGIN));
            } else {
                $msg[] = self::buildMessage(IHelper::CODE_NOT_LOGGED_IN, $translator->t($error->getMessage()));
            }
        } catch (Exception $error) {
            $msg[] = self::buildMessage(IHelper::USER_ERROR, $translator->t($error->getMessage()));
        }
        $this->getResponse()->setStatusCode($error->getCode());
        $authHelper = $services->get(Services::AUTH_HELPER);
        $sso        = $authHelper->getSSO(P4_SERVER_ID);
        return self::error($msg, $this->getResponse()->getStatusCode(), ['sso' => $sso]);
    }

    /**
     * Delete the current session, i.e. fully logout
     * @param mixed $data
     * @return mixed|JsonModel
     */
    public function deleteList($data)
    {
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        $request    = $this->getRequest();
        // If we want to be redirected do it.
        $filter   = new FormBoolean([FormBoolean::NULL_AS_FALSE => false]);
        $redirect =  $filter->filter($request->getQuery(Helper::PARAM_REDIRECT));
        $request->setQuery(new Parameters([Helper::PARAM_REDIRECT => $redirect]));
        $authHelper = $services->get(Services::AUTH_HELPER);
        $msg        = [];
        try {
            $data = $authHelper->logout($request, $this->getResponse());
        } catch (\Exception $e) {
            $msg[] = self::buildMessage(IHelper::CODE_LOGOUT_ERROR, $e->getMessage());
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            return self::error($msg, $this->getResponse()->getStatusCode());
        }
        $msg[] = self::buildMessage(
            IHelper::CODE_LOGOUT_SUCCESSFUL,
            $translator->t(IHelper::TEXT_LOGOUT_SUCCESSFUL)
        );
        return self::success($data, $msg);
    }

    /**
     * Login, note this uses the content body and ignores any data provided
     * @param mixed $requestData - ignored
     * @return mixed|JsonModel
     */
    public function create($requestData)
    {
        $data       = $this->getData();
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        $msg        = [];
        try {
            $this->loginAction($data);
            $msg[] = self::buildMessage(
                IHelper::CODE_LOGIN_SUCCESSFUL,
                $translator->t(IHelper::TEXT_LOGIN_SUCCESSFUL)
            );
            $user  = $this->currentUser();
            return self::success([IUser::USER => [$user]], $msg);
        } catch (BasicAuthFailedException $error) {
            $msg[] = self::buildMessage(IHelper::CODE_LOGIN_INCORRECT, $translator->t($error->getMessage()));
        } catch (UnauthorizedException $error) {
            $msg[] = self::buildMessage(IHelper::CODE_NOT_LOGGED_IN, $translator->t($error->getMessage()));
        } catch (ServiceNotFoundException $error) {
            $msg[] = self::buildMessage(IHelper::CODE_SWARM_CONNECTION, $translator->t($error->getMessage()));
        } catch (\P4\Exception $error) {
            $msg[] = self::buildMessage(IHelper::CODE_LOGIN_MISSING, $translator->t($error->getMessage()));
        } catch (Exception $error) {
            $msg[] = self::buildMessage(IHelper::USER_ERROR, $translator->t($error->getMessage()));
        }

        $this->getResponse()->setStatusCode($error->getCode());
        return self::error($msg, $this->getResponse()->getStatusCode());
    }

    /**
     * Get the data provided and put into a format to be used.
     * @return mixed
     */
    protected function getData()
    {
        // This isn't setting session data correctly so login not remembered when going to server context
        $request = $this->getRequest();
        if ($this->requestHasContentType($request, self::CONTENT_TYPE_JSON)) {
            $requestData = json_decode($request->getContent(), true);
        } else {
            $requestData = $request->getPost()->toArray();
        }
        // Get the baseurl and scheme for the authHelper so we do not have to pass reqest in.
        $requestData[IHelper::BASE_URL] = $request->getBaseUrl();
        $requestData[IHelper::HTTPS]    = $request instanceof Request
            ? $request->getUri()->getScheme() == 'https' : false;
        return $requestData;
    }

    /**
     * Login to Swarm using the credentials provided
     *
     * @param $data
     * @return boolean
     * @throws ServiceNotFoundException
     * @throws \P4\Exception
     * @throws BasicAuthFailedException
     */
    protected function loginAction($data)
    {
        $services   = $this->services;
        $session    = $services->get(IHelper::SESSION);
        $authHelper = $services->get(Services::AUTH_HELPER);
        // Clear any existing session data on login, we need to explicitly restart the session
        $session->start();
        $session->getStorage()->clear();
        $isSamlRequest = isset($_REQUEST[IHelper::SAML_REQUEST]);
        if ($isSamlRequest || (isset($data[IHelper::USERNAME]) && isset($data[IHelper::PASSWORD]))) {
            $userName = $data[IHelper::USERNAME];
            $password = $data[IHelper::PASSWORD];
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            if ($isSamlRequest) {
                $candAuth = $authHelper->handleSamlResponse();
            } else {
                $candAuth = $authHelper->authenticateCandidates($userName, $password);
            }
            $authUser = $candAuth[IHelper::AUTH_USER];
            if ($candAuth[IHelper::IS_VALID]) {
                $authHelper->setCookieInformation($data);
                $authHelper->invalidateCache($authUser, $p4Admin);
            } else {
                try {
                    // verify that the admin connection is still valid if so then the user credentials
                    // provided are incorrect.
                    $userDao = $services->get(IModelDAO::USER_DAO);
                    $userDao->fetchById($p4Admin->getUser(), $p4Admin);
                    throw new BasicAuthFailedException(IHelper::TEXT_LOGIN_INCORRECT, Response::STATUS_CODE_401);
                } catch (Exception $le) {
                    if ($le instanceof BasicAuthFailedException) {
                        throw $le;
                    }
                    throw new ServiceNotFoundException(IHelper::TEXT_SWARM_CONNECTION, Response::STATUS_CODE_500);
                }
            }
        } else {
            throw new \P4\Exception(IHelper::TEXT_LOGIN_MISSING, Response::STATUS_CODE_400);
        }
        $session->writeClose();
        if ($isSamlRequest) {
            // Redirect to the page that the login was initiated from, or if that is not set
            // redirect to the route defined in routes as 'home'
            $url = $authHelper->getLoginReferrer();
            if (!$url) {
                $url = $this->services->get('ViewHelperManager')->get('url')->__invoke('home');
            }
            $this->getResponse()->getHeaders()->addHeaderLine('Location', $url);
            $this->getResponse()->setStatusCode(302);
        }
        return true;
    }

    /**
     * Check if the user is logged in and then return the user object
     *
     * @return JsonModel
     * @throws UnauthorizedException
     * @throws Exception
     */
    protected function currentUser()
    {
        $userDao = $this->services->get(IModelDAO::USER_DAO);
        try {
            $p4User = $this->services->get(ConnectionFactory::P4_USER);
            if ($p4User->isAuthenticated()) {
                $authUser = $userDao->fetchAuthUser($p4User->getUser(), $p4User);
            } else {
                throw new UnauthorizedException(IHelper::TEXT_NOT_LOGGED_IN, Response::STATUS_CODE_401);
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), IHelper::SERVICE_P4_USER_NOT_CREATED) === 0
                || $e instanceof UnauthorizedException) {
                throw new UnauthorizedException(IHelper::TEXT_NOT_LOGGED_IN, Response::STATUS_CODE_401);
            } else {
                throw new \Exception($e->getMessage(), Response::STATUS_CODE_500);
            }
        }
        return $authUser;
    }
}
