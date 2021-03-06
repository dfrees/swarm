<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Api\IRequest;
use Application\Config\Services;
use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Saml\Controller\SamlApi;
use Saml\Service\Saml;

return [
    'saml' => [
        // The saml-agent uses chromium which generates its own stdout messages
        // the work around is that we add a header for the pertinent data for the trigger to look at.
        // This is the header value that should match that used in any trigger
        'header' => 'saml-response: ',

        // Saml settings and optional advanced settings should be configured in config.php
        // according to a customers particular configuration. Any trigger installed must
        // match elements of this configuration to successfully validate.

        // If 'strict' is True, then the PHP Toolkit will reject unsigned
        // or unencrypted messages if it expects them signed or encrypted
        // Also will reject the messages if not strictly follow the SAML
        // standard: Destination, NameId, Conditions ... are validated too.
        'strict' => false,

        // Enable debug mode (to print errors)
        'debug' => false,

        // Set a BaseURL to be used instead of try to guess
        // the BaseURL of the view that process the SAML Message.
        // Ex. http://sp.example.com/
        //     http://example.com/sp/
        'baseurl' => null,

        // Service Provider Data that we are deploying
        'sp' => [
            // Identifier of the SP entity  (must be a URI)
            'entityId' => '',
            // Specifies info about where and how the <AuthnResponse> message MUST be
            // returned to the requester, in this case our SP.
            'assertionConsumerService' => [
                // URL Location where the <Response> from the IdP will be returned, this
                // should be set to the host/port for the SP, Swarm will append API
                // endpoint details.
                // For example http://<host>:<port> or http://<host> which will become
                // http://<host>:<port>/sso/login or http://<host>/sso/login
                'url' => '',
                // SAML protocol binding to be used when returning the <Response>
                // message.  Onelogin Toolkit supports for this endpoint the
                // HTTP-Redirect binding only
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            ],
            // If you need to specify requested attributes, set a
            // attributeConsumingService. nameFormat, attributeValue and
            // friendlyName can be omitted. Otherwise remove this section.
            "attributeConsumingService"=> [
                "ServiceName" => "SP test",
                "serviceDescription" => "Test Service",
                "requestedAttributes" => [
                    [
                        "name" => "",
                        "isRequired" => false,
                        "nameFormat" => "",
                        "friendlyName" => "",
                        "attributeValue" => ""
                    ]
                ]
            ],
            // Specifies info about where and how the <Logout Response> message MUST be
            // returned to the requester, in this case our SP.
            'singleLogoutService' => [
                // URL Location where the <Response> from the IdP will be returned
                'url' => 'http://localhost',
                // SAML protocol binding to be used when returning the <Response>
                // message.  Onelogin Toolkit supports for this endpoint the
                // HTTP-Redirect binding only
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            // Specifies constraints on the name identifier to be used to
            // represent the requested subject.
            // Take a look on lib/Saml2/Constants.php to see the NameIdFormat supported
            'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',

            // Usually x509cert and privateKey of the SP are provided by files placed at
            // the certs folder. But we can also provide them with the following parameters
            'x509cert' => '',
            'privateKey' => '',

            /*
             * Key rollover
             * If you plan to update the SP x509cert and privateKey
             * you can define here the new x509cert and it will be
             * published on the SP metadata so Identity Providers can
             * read them and get ready for rollover.
             */
            // 'x509certNew' => '',
        ],

        // Identity Provider Data that we want connect with our SP
        'idp' => [
            // Identifier of the IdP entity  (must be a URI)
            'entityId' => '',
            // SSO endpoint info of the IdP. (Authentication Request protocol)
            'singleSignOnService' => [
                // URL Target of the IdP where the SP will send the Authentication Request Message
                'url' => '',
                // SAML protocol binding to be used when returning the <Response>
                // message.  Onelogin Toolkit supports for this endpoint the
                // HTTP-POST binding only
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            // SLO endpoint info of the IdP.
            'singleLogoutService' => [
                // URL Location of the IdP where the SP will send the SLO Request
                'url' => '',
                // SAML protocol binding to be used when returning the <Response>
                // message.  Onelogin Toolkit supports for this endpoint the
                // HTTP-Redirect binding only
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            // Public x509 certificate of the IdP
            'x509cert' => '',
            /*
             *  Instead of using the whole x509cert you can use a fingerprint in
             *  order to validate the SAMLResponse, but we don't recommend to use
             *  that method on production since is exploitable by a collision
             *  attack.
             *  (openssl x509 -noout -fingerprint -in "idp.crt" to generate it,
             *   or add for example the -sha256 , -sha384 or -sha512 parameter)
             *
             *  If a fingerprint is provided, then the certFingerprintAlgorithm is required in order to
             *  let the toolkit know which Algorithm was used. Possible values: sha1, sha256, sha384 or sha512
             *  'sha1' is the default value.
             */
            // 'certFingerprint' => '',
            // 'certFingerprintAlgorithm' => 'sha1',

            /* In some scenarios the IdP uses different certificates for
             * signing/encryption, or is under key rollover phase and more
             * than one certificate is published on IdP metadata.
             * In order to handle that the toolkit offers that parameter.
             * (when used, 'x509cert' and 'certFingerprint' values are
             * ignored).
             */
            // 'x509certMulti' => array(
            //      'signing' => array(
            //          0 => '<cert1-string>',
            //      ),
            //      'encryption' => array(
            //          0 => '<cert2-string>',
            //      )
            // ),
        ],
    ],
    'service_manager' => [
        'aliases'   => [
            Services::SAML => Saml::class
        ],
        'factories' => [
            Saml::class => InvokableServiceFactory::class,
        ]
    ],
    'controllers' => [
        'factories' => [
            SamlApi::class => IndexControllerFactory::class
        ]
    ],
    'router' => [
        'routes' => [
            'api' => [
                'type' => 'literal',
                'options' => [
                    'route' => SamlApi::API_BASE,
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'samlApi' => [
                        'type' => 'Laminas\Router\Http\Segment',
                        'options' => [
                            'route' => '/:version/saml/login[/]',
                            'constraints' => [IRequest::VERSION => 'v1([0-1])'],
                        ],
                        'child_routes' => [
                            'samlApiLogin' => [
                                'type' => 'Laminas\Router\Http\Method',
                                'options' => [
                                    'verb' => 'post',
                                    'defaults' => [
                                        'controller' => SamlApi::class,
                                        'action'     => 'login'
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]
];
