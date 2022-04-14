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
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use P4\Exception;
use Users\Authentication\IHelper;
use Users\Model\User;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;

/**
 * Swarm Login API
 *
 */
class LoginController extends AbstractApiController
{
    const RESULTS = 'results';
    const METHOD  = 'method';
    const METHODS = 'methods';
    const CODE    = 'code';
    const OPTION  = 'option';
    const ERROR   = 'error';
    const TOKEN   = 'token';
    const POLL    = 'poll';
    const PROMPT  = 'prompt';
    const TRIGGER = 'trigger';

    const NEXTSTATE  = 'nextState';
    const SUCCESSMSG = 'successMsg';
    const MFASTATUS  = 'mfaStatus';
    const CHALLENGE  = 'challenge';

    const RESPONSEMETHOD = 'responseMethod';

    const MFASUCCESS     = "mfaSuccess";
    const MFAPOLLPENDING = "mfaPending";
    const MFAFAILED      = "mfaFailed";
    const CHECK_AUTH     = 'check-auth';

    // Session values
    const COOKIE_LIFETIME            = 'cookie_lifetime';
    const REMEMBERED_COOKIE_LIFETIME = 'remembered_cookie_lifetime';

    // Provide a consistent set of fields to return from this API controller
    const RESPONSE_FIELDS = [User::ID_FIELD, User::EMAIL_FIELD, User::FULL_NAME_FIELD, User::TYPE_FIELD];
    /**
     * Provide a list of 2-factor authentication methods
     * @return mixed
     */
    public function listMethodsAction()
    {
        $services = $this->services;
        $p4User   = $services->get('p4_user');

        // Set the flags to get list of methods
        $flags = ['-S', 'list-methods'];

        try {
            // Run the command to fetch the data
            $commandResults = $p4User->run('login2', $flags, null, true);
            $commandData    = $commandResults->getData();
            $isMfa          = sizeof($commandData) > 1;
            $option         = $isMfa ? $commandData[1] : null;
            // If we don't get returned data[1] then we can assume the command failed.
            if (!$option) {
                if ($this->isMfaCommandError($commandData[0])) {
                    return new JsonModel(
                        [
                            static::CODE    => 200,
                            static::RESULTS => $commandData[0]
                        ]
                    );
                } else {
                    throw new Exception("Missing required data.");
                }
            }
            // Collect methods and option
            $options = $option;
            $methods = $commandData[0];

            // If we are returned 1 or more methods return them to the page.
            if (count($methods) > 0) {
                $results[static::RESULTS][static::METHODS] = $this->listMethodSort($methods);
            }

            // Set the option and code vaules to be returned.
            $results[static::OPTION] = $options;
            $results[static::CODE]   = Response::STATUS_CODE_200;

            return $this->prepareSuccessModel($results);
        } catch (Exception $e) {
            // set message to the error from P4 and just return it.
            $message = $e->getMessage();
        }

        // If we hit here then we have failed and should return the error if we have one.
        return new JsonModel(
            [
                'isValid'     => false,
                static::CODE  => Response::STATUS_CODE_401,
                static::ERROR => $message
            ]
        );
    }

    /**
     * Tests to see if the command result is due to MFA not in use.
     * @param string $commandResult the command result
     * @return bool true if the command specifies MFA is not in use
     */
    private function isMfaCommandError($commandResult)
    {
        $matches = [];
        preg_match('/does not use \bsecond\b|\bmulti\b factor authentication./', $commandResult, $matches);
        return sizeof($matches) > 0;
    }

    /**
     * Initiate a 2-factor authentication process
     * @param POST
     * @return JsonModel
     */
    public function initAuthAction()
    {
        $request   = $this->getRequest();
        $services  = $this->services;
        $p4User    = $services->get('p4_user');
        $method2fa = $request->getPost(static::METHOD);

        // Set the flags to init auth method
        $flags = ['-S', 'init-auth', '-m', $method2fa];

        try {
            // Run the command to fetch the data
            $commandResults = $p4User->run('login2', $flags, null, true);
            $initAuthData   = $commandResults->getData(0);
            if (!is_array($initAuthData)
                && $this->isMfaCommandError($initAuthData)) {
                return new JsonModel(
                    [
                        static::CODE    => 200,
                        static::RESULTS => $initAuthData
                    ]
                );
            }

            // Set the trigger and successmsg that we need to display.
            $results[static::RESULTS] = [
                static::TRIGGER    => $initAuthData[static::TRIGGER],
                static::SUCCESSMSG => $initAuthData[static::SUCCESSMSG]
            ];

            if (isset($initAuthData[static::CHALLENGE])) {
                $results[static::RESULTS][static::CHALLENGE] = $initAuthData[static::CHALLENGE];
            }

            // The option that tells us if we are prompt or poll
            $results[static::OPTION] = [
                static::RESPONSEMETHOD => $initAuthData[static::RESPONSEMETHOD],
                static::NEXTSTATE      => $initAuthData[static::NEXTSTATE]
            ];

            // Set the code to successful.
            $results[static::CODE] = Response::STATUS_CODE_200;

            return $this->prepareSuccessModel($results);
        } catch (Exception $e) {
            // set message to the error from P4 and just return it.
            $message = $e->getMessage();
        }
        // If we hit here then we have failed and should return the error if we have one.
        return new JsonModel(
            [
                'isValid'     => false,
                static::CODE  => Response::STATUS_CODE_401,
                static::ERROR => $message
            ]
        );
    }

    /**
     * Check the 2-factor authentication state
     * @param POST
     * @return JsonModel
     */
    public function checkAuthAction()
    {
        $request  = $this->getRequest();
        $services = $this->services;
        $p4User   = $services->get('p4_user');
        $prompt   = $request->getPOST(static::TOKEN);

        // Set the flags to check auth
        $flags = ['-S', static::CHECK_AUTH];

        if ($prompt !== null) {
            try {
                // Run the command to fetch the data
                $commandResults = $p4User->run('login2', $flags, $prompt, true);
                $checkAuthData  = $commandResults->getData();

                // Ensure the data has been set.
                if ($checkAuthData === false) {
                    $checkAuthData = $commandResults->getData(0);
                }
                // Set the code and results to be returned.
                $results[static::RESULTS]   = $checkAuthData;
                $results[static::CODE]      = Response::STATUS_CODE_200;
                $results[static::MFASTATUS] = static::MFASUCCESS;

                return $this->prepareSuccessModel($results);
            } catch (Exception $e) {
                // set message to the error from P4 and just return it.
                $message = $e->getMessage();
            }
        } else {
            try {
                $commandResults = $p4User->run('login2', $flags, $prompt, true);
                $checkAuthData  = $commandResults->getData();
                $message        = $checkAuthData[0];
                if ($this->isMfaCommandError($message)) {
                    return new JsonModel(
                        [
                            static::CODE      => 200,
                            static::RESULTS   => $message,
                            static::MFASTATUS => static::MFASUCCESS
                        ]
                    );
                }
            } catch (Exception $e) {
                // set message to the error from P4 and just return it.
                $message = $e->getMessage();
            }
        }
        // If we hit here then we have failed and should return the error if we have one.
        return new JsonModel(
            [
                'isValid'         => false,
                static::CODE      => Response::STATUS_CODE_401,
                static::ERROR     => $message,
                static::MFASTATUS => static::MFAFAILED
            ]
        );
    }

    /**
     * Poll for a 2-factor authentication state change
     * @return JsonModel
     */
    public function checkAuthPollAction()
    {
        $services = $this->services;
        $p4User   = $services->get('p4_user');
        $message  = '';

        // Set the flags to check auth poll
        $flags = ['-S', static::CHECK_AUTH];
        try {
            // Run the command to fetch the data
            $commandResults    = $p4User->run('login2', $flags, null, true);
            $checkAuthPollData = $commandResults->getData();

            // Set the code and results to be returned.
            $results[static::RESULTS]   = $checkAuthPollData;
            $results[static::CODE]      = Response::STATUS_CODE_200;
            $results[static::MFASTATUS] = static::MFASTATUS;

            return $this->prepareSuccessModel($results);
        } catch (Exception $e) {
            // set message to the error from P4 and just return it.
            $message  = $e->getMessage();
            $pending  = (strpos($e->getMessage(), "authentication approval is pending.") > 0);
            $rejected = (strpos($e->getMessage(), "authentication was rejected.") > 0);

            if ($pending || $rejected) {
                $checkAuthPollData = array_merge($e->getResult()->getData(), $e->getResult()->getErrors());

                // Set the code and results to be returned.
                $results[static::RESULTS] = $checkAuthPollData;
                $results[static::CODE]    = Response::STATUS_CODE_200;

                if ($pending) {
                    $results[ static::MFASTATUS ] = static::MFAPOLLPENDING;
                } elseif ($rejected) {
                    $results[static::MFASTATUS] = static::MFAFAILED;
                }

                return $this->prepareSuccessModel($results);
            }
        }
        // If we hit here then we have failed and should return the error if we have one.
        return new JsonModel(
            [
                'isValid'         => false,
                static::CODE      => Response::STATUS_CODE_401,
                static::ERROR     => $message,
                static::MFASTATUS => static::MFAFAILED
            ]
        );
    }

    /**
     * Login to Swarm using the credentials provided
     * @return JsonModel
     * @throws \Application\Config\ConfigException
     */
    public function loginAction()
    {
        // This isn't setting session data correctly so login not remembered when going to server context
        $request = $this->getRequest();
        if ($this->requestHasContentType($request, self::CONTENT_TYPE_JSON)) {
            $data = json_decode($request->getContent(), true);
        } else {
            $data = $request->getPost()->toArray();
        }
        // Clear any existing session data on login, we need to explicitly restart the session
        $services   = $this->services;
        $translator = $services->get(TranslatorFactory::SERVICE);
        $session    = $services->get(IHelper::SESSION);
        $error      = null;
        $authUser   = null;
        $userDetail = null;
        $adapter    = null;
        $authHelper = $services->get(Services::AUTH_HELPER);

        $session->start();
        $session->getStorage()->clear();
        if (isset($data[IHelper::USERNAME]) && isset($data[IHelper::PASSWORD])) {
            $userName = $data[IHelper::USERNAME];
            $password = $data[IHelper::PASSWORD];
            $p4Admin  = $services->get('p4_admin');
            $candAuth = $authHelper->authenticateCandidates($userName, $password);
            $authUser = $candAuth[IHelper::AUTH_USER];
            $adapter  = $candAuth[IHelper::ADAPTER];
            if ($candAuth[IHelper::IS_VALID]) {
                $data[IHelper::BASE_URL] = $request->getBaseUrl();
                $data[IHelper::HTTPS]    = $request instanceof Request
                    ? $request->getUri()->getScheme() == 'https' : false;
                $authHelper->setCookieInformation($data);
                $authUser = $authHelper->invalidateCache($authUser, $p4Admin);
            } else {
                $error = $authHelper->createErrorMessage($this->getResponse(), $p4Admin, $userName);
            }
        } else {
            $error = $translator->t("User name and password must be provided.");
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_401);
        }
        $session->writeClose();
        return $this->buildLoginResponse($adapter, $authUser, $error);
    }

    /**
     * Logout of Swarm
     * @return JsonModel
     */
    public function logoutAction()
    {
        $services   = $this->services;
        $authHelper = $services->get(Services::AUTH_HELPER);
        $request    = $this->getRequest();
        $error      = null;
        try {
            $authHelper->logout($request, $this->getResponse());
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
        }
        return $this->buildLogoutResponse($error);
    }

    /**
     * Builds a JSON model response for logout.
     * @param mixed     $error  any error message to include.
     * @return JsonModel
     */
    private function buildLogoutResponse($error)
    {
        $response = [
            'isValid'  => $error ? false : true,
            'messages' => $error ? [$error] : [],
        ];
        return new JsonModel($response);
    }

    /**
     * Builds a JSON response based on results.
     * @param mixed  $adapter the authentication adapter used to auth the user
     * @param string $authUser the user identifier
     * @param string $error an error (may be null)
     * @return JsonModel
     */
    private function buildLoginResponse($adapter, $authUser, $error)
    {
        $response = [
            'isValid'  => $authUser ? true : false,
            'messages' => $error ? [$error] : [],
        ];

        if ($authUser) {
            $loginValues = array_intersect_key($authUser->getValuesArray(), array_flip(self::RESPONSE_FIELDS));
            $response    = $response + [
                    'user' =>
                        $loginValues +
                        [
                            'isAdmin' => $adapter->getUserP4()->isAdminUser(true),
                            'isSuper' => $adapter->getUserP4()->isSuperUser(),
                        ]
                ];
        }
        return new JsonModel($response);
    }

    /**
     * Login to Swarm via an external saml provider
     * @return mixed
     */
    public function samlLoginAction()
    {
        $authHelper = $this->services->get(Services::AUTH_HELPER);
        return $authHelper->handleSamlLogin($this->getRequest());
    }

    /**
     * Get the authentication details relating to the current session
     * @return mixed
     */
    public function getList()
    {
        return $this->forward(
            UsersController::class,
            null,
            null,
            ['current' => true, 'fields' => self::RESPONSE_FIELDS],
            null
        );
    }

    /**
     * Delete the current session, i.e. fully logout
     * @param mixed $data
     * @return mixed|JsonModel
     */
    public function deleteList($data)
    {
        return $this->logoutAction();
    }

    /**
     * Login, note this uses the content body and ignores any data provided
     * @param mixed $data - ignored
     * @return mixed|JsonModel
     * @throws \Application\Config\ConfigException
     */
    public function create($data)
    {
        return $this->loginAction();
    }

    // TODO samlResponseAction
    // samlResponseAction() is here ready for when we use the API to handle the saml
    // response (when all the login actions are using the API rather than the web
    // controller). Currently it is planned that the classic login will go through
    // the web controller, not sure yet if we need it so untested for now
    /**
     * Endpoint to handle the return from the IDP to validate Saml details.
     * @return JsonModel
     */
    public function samlResponseAction()
    {
        $services   = $this->services;
        $authHelper = $services->get(Services::AUTH_HELPER);
        $error      = null;
        $authUser   = null;
        $p4Admin    = $services->get(ConnectionFactory::P4_ADMIN);
        $logger     = $services->get(SwarmLogger::SERVICE);
        $session    = $services->get(IHelper::SESSION);
        $session->start();
        $session->getStorage()->clear();
        $result  = $authHelper->handleSamlResponse();
        $userId  = $result[IHelper::USERNAME];
        $adapter = $result[IHelper::ADAPTER];
        try {
            if ($result[IHelper::IS_VALID]) {
                $data                    = [];
                $request                 = $this->getRequest();
                $data[IHelper::USERNAME] = $userId;
                $data[IHelper::BASE_URL] = $request->getBaseUrl();
                $data[IHelper::HTTPS]    = $request instanceof Request
                    ? $request->getUri()->getScheme() == 'https' : false;
                $authHelper->setCookieInformation($data);
                $authUser = $authHelper->invalidateCache($userId, $p4Admin);
            } else {
                $error = $authHelper->createErrorMessage($this->getResponse(), $p4Admin, $userId);
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $logger->err("An error occurred using Saml login " . $error);
        }
        $session->writeClose();
        return $this->buildLoginResponse($adapter, $authUser, $error);
    }

    /**
     * Take an array of methods element sort them into separate array within an array
     *
     * @param $methods    Single dimension array
     * @return array      Multi dimension array
     */
    protected function listMethodSort($methods)
    {
        $results = [];
        $last    = 0; // Keep track of which method we last saw

        // loop though each of the data sent back and create an array to send in json
        foreach ($methods as $key => $method) {
            preg_match("/(.*)([0-9]+)$/U", $key, $spilt);
            if (isset($spilt[1]) && isset($spilt[2])) {
                $field   = $spilt[1]; // This is the name of the field
                $current = $spilt[2]; // This is the current method number we are at

                // If we are still on the same method then add the next field to it.
                if ($current === $last) {
                    $results[$current][$field] = $method;
                } else {
                    // else we set current and create the next method in the array.
                    $last = $current;

                    $results[$current][$field] = $method;
                }
            }
        }
        return $results;
    }
}
