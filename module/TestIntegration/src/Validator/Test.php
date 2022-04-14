<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TestIntegration\Validator;

use Application\Factory\InvokableService;
use Laminas\Validator\AbstractValidator;
use Interop\Container\ContainerInterface;

/**
 * Class Test. Validates the 'test' value of a test run
 * @package TestIntegration\Validator
 */
class Test extends AbstractValidator implements InvokableService
{
    private $services;
    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        parent::__construct($options);
    }

    /**
     * Validates that if the value is numeric it links to a test definition in key data. Non-numeric are assumed to
     * be valid (could link to an old project test definition that is not in key data)
     * @param mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        $valid = true;
        if (is_numeric($value)) {
            $existsValidator = new TestDefinitionExists($this->services);
            $valid           = $existsValidator->isValid([$value]);
            if (!$valid) {
                $this->abstractOptions['messages'] += $existsValidator->getMessages();
            }
        }
        return $valid;
    }
}
