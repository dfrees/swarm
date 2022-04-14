<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Workflow\Validator;

use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\Validator\ArrayValuesValidator;
use Application\Validator\IsArray;
use Laminas\Validator\AbstractValidator;
use Interop\Container\ContainerInterface;
use TestIntegration\Validator\TestDefinitionExists;
use Workflow\Model\IWorkflow;

/**
 * Class Tests
 * @package Workflow\Validator
 */
class Tests extends AbstractValidator implements InvokableService
{
    private $services;
    const INVALID_KEYS = 'invalidKeys';
    const VALID_KEYS   = [IWorkflow::TEST_ID => '', IWorkflow::EVENT => '', IWorkflow::BLOCKS => ''];
    const VALID_EVENTS = [IWorkflow::EVENT_ON_SUBMIT, IWorkflow::EVENT_ON_UPDATE, IWorkflow::EVENT_ON_DEMAND];
    const VALID_BLOCKS = [IWorkflow::NOTHING, IWorkflow::APPROVED];

    protected $messageTemplates = [
        self::INVALID_KEYS => ''
    ];

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $translator     = $services->get(TranslatorFactory::SERVICE);

        $this->messageTemplates[self::INVALID_KEYS] =
            $translator->t(
                "Each test should be an array with mandatory keys [%s], [%s] and optionally [%s] only",
                [
                    IWorkflow::TEST_ID,
                    IWorkflow::EVENT,
                    IWorkflow::BLOCKS
                ]
            );
        parent::__construct($options);
    }

    /**
     * Validate that the array of tests has the correct values
     * @param mixed $value  test values
     * @return bool
     */
    public function isValid($value) : bool
    {
        $valid = empty($value);
        foreach ($value as $test) {
            $arrayValidator = new IsArray();
            $valid          = $arrayValidator->isValid($test);
            if (!$valid) {
                $this->error(self::INVALID_KEYS);
                break;
            }
            $diffFromDatum = array_diff_key(self::VALID_KEYS, $test);
            $valid         = empty($diffFromDatum) || $diffFromDatum === [IWorkflow::BLOCKS => ""];
            if ($valid) {
                $extraKeys = array_diff_key($test, self::VALID_KEYS);
                $valid     = empty($extraKeys);
            }
            if ($valid) {
                $eventsValid = $this->validateArray($test, self::VALID_EVENTS, IWorkflow::EVENT);
                $blocksValid = $this->validateArray($test, self::VALID_BLOCKS, IWorkflow::BLOCKS);
                $valid       = $eventsValid && $blocksValid;
                if (!$valid) {
                    break;
                }
            } else {
                $this->error(self::INVALID_KEYS);
                break;
            }
        }
        if ($valid) {
            $existsValidator = new TestDefinitionExists($this->services);
            $ids             = array_column($value, IWorkflow::TEST_ID);
            $valid           = $existsValidator->isValid($ids);
            if (!$valid) {
                $this->abstractOptions['messages'] += $existsValidator->getMessages();
            }
        }
        return $valid;
    }

    /**
     * Validate an array of values
     * @param array     $test           test values
     * @param array     $validValues    valid values
     * @param string    $key            key for field and errors
     * @return bool if the values are valid
     */
    private function validateArray(array $test, array $validValues, string $key) : bool
    {
        $validator = new ArrayValuesValidator(
            $this->services->get(TranslatorFactory::SERVICE),
            $validValues,
            $key,
            $key
        );
        $valid     = $validator->isValid($test[$key]);
        if (!$valid) {
            $this->abstractOptions['messages'] += $validator->getMessages();
        }
        return $valid;
    }
}
