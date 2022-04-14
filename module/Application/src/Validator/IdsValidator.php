<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Application\I18n\TranslatorFactory;
use Interop\Container\ContainerInterface;
use Laminas\Validator\AbstractValidator;

class IdsValidator extends AbstractValidator
{
    private $services;
    private $field;

    const INVALID = "arrayOrStringInvalid";

    /**
     * IdsValidator constructor.
     * @param ContainerInterface $services  application services
     * @param String             $field     The model or field this ids validation is for.
     */
    public function __construct(ContainerInterface $services, string $field)
    {
        parent::__construct();
        $this->services = $services;
        $this->field    = $field;
    }
    /**
     * Valid that if is array of strings or a single string.
     * @param mixed     $value      The array or string.
     * @return bool true if valid
     */
    public function isValid($value): bool
    {
        $valid = false;

        if (is_string($value)) {
            $valid = true;
        }
        if (is_array(($value))) {
            $valid = array_sum(array_map('is_string', $value)) == count($value);
        }
        if (!$valid) {
            $translator                                       = $this->services->get(TranslatorFactory::SERVICE);
            $this->abstractOptions['messages'][self::INVALID] =
                $translator->t($this->field ." ids must be valid string or array of strings");
        }
        return $valid;
    }
}
