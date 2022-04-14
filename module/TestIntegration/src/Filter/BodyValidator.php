<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\I18n\TranslatorFactory;
use Interop\Container\ContainerInterface;
use Laminas\Validator\AbstractValidator;
use TestIntegration\Model\TestDefinition as Model;

/**
 * Class BodyValidator, tests that the body of a global test is valid
 * @package TestIntegration\Filter
 */
class BodyValidator extends AbstractValidator
{
    const JSON_INVALID = "jsonInvalid";
    private $services;

    /**
     * BodyValidator constructor.
     * @param ContainerInterface $services  application services
     */
    public function __construct(ContainerInterface $services)
    {
        parent::__construct();
        $this->services = $services;
    }

    /**
     * Valid that if JSON encoding is specified the body should parse as valid JSON
     * @param mixed     $value      body value
     * @param null      $context    context will additional fields from the input
     * @return bool true if valid
     */
    public function isValid($value, $context = null)
    {
        if (isset($context[Model::FIELD_ENCODING]) &&
            strcasecmp($context[Model::FIELD_ENCODING], EncodingValidator::JSON) === 0) {
            // Encoding specified as JSON, ensure that any body parses as valid JSON
            if ($value && !json_decode($value, true)) {
                $translator = $this->services->get(TranslatorFactory::SERVICE);

                $this->abstractOptions['messages'][self::JSON_INVALID] =
                    $translator->t(
                        "Test configuration body must be valid JSON with JSON encoding [%s]",
                        [$value]
                    );
                return false;
            }
        }
        return true;
    }
}
