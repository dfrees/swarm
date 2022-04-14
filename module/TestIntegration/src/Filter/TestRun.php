<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\Filter\ArrayOfStringFilter;
use Application\Filter\ArraySliceFilter;
use Application\Filter\ArrayValues;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\FlatArray;
use Interop\Container\ContainerInterface;
use TestIntegration\Model\TestRun as Model;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Digits;
use Laminas\Validator\GreaterThan;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use TestIntegration\Validator\Test;

/**
 * Class TestRun to filter and validate TestRun
 * @package TestIntegration\Filter
 */
class TestRun extends InputFilter implements ITestRun
{
    // Messages limits
    const ARRAY_ELEMENTS = 10;
    const MESSAGE_LENGTH = 80;

    private $translator;
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addChangeFilter();
        $this->addVersionFilter();
        $this->addTestFilter();
        $this->addStartTimeFilter();
        $this->addCompletionTimeFilter();
        $this->addStatusFilter();
        $this->addMessagesFilter();
        $this->addUrlFilter();
        $this->addUuidFilter();
        $this->addTitleFilter();
        $this->addBranchesFilter();
    }

    /**
     * Add filters/validation for branches. Must be a string if provided
     */
    private function addBranchesFilter()
    {
        $input = new DirectInput(Model::FIELD_BRANCHES);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 0]));
        $this->add($input);
    }

    /**
     * Add filters/validation for change
     */
    private function addChangeFilter()
    {
        $input = new Input(Model::FIELD_CHANGE);
        $input->getValidatorChain()->attach(new Digits);
        $this->add($input);
    }

    /**
     * Add filters/validation for version
     */
    private function addVersionFilter()
    {
        $input = new Input(Model::FIELD_VERSION);
        $input->getValidatorChain()->attach(new Digits);
        $this->add($input);
    }

    /**
     * Add filters/validation for test
     */
    private function addTestFilter()
    {
        $input = new Input(Model::FIELD_TEST);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new Regex(['pattern' => "/^[-_A-Za-z0-9]/"]), true)
            ->attach(new StringLength(['min' => 1, 'max' => 1000]), true)
            ->attach(new Test($this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for start time
     */
    private function addStartTimeFilter()
    {
        $input = new Input(Model::FIELD_START_TIME);
        $input->getValidatorChain()->attach(new GreaterThan(['min' => 0]));
        $this->add($input);
    }

    /**
     * Add filters/validation for completion time
     */
    private function addCompletionTimeFilter()
    {
        // Use DirectInput for not required field to run validators. If Input is used
        // and required is false Zend will not run a provided 'null' through validation
        // and a 500 error may occur if called with null
        $input = new DirectInput(Model::FIELD_COMPLETED_TIME);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThan(['min' => 0]));
        $this->add($input);
    }

    /**
     * Add filters/validation for status
     */
    private function addStatusFilter()
    {
        $input = new Input(Model::FIELD_STATUS);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 4, 'max' => 10]));
        $input->getValidatorChain()->attach(new StatusValidator($this->translator));
        $this->add($input);
    }

    /**
     * Add filters/validation for messages
     */
    private function addMessagesFilter()
    {
        // Use DirectInput for not required field to run validators. If Input is used
        // and required is false Zend will not run a provided 'null' through validation
        // and a 500 error may occur if called with null
        $input = new DirectInput(Model::FIELD_MESSAGES);
        $input->setRequired(false);
        $input->getFilterChain()
            ->attach(new ArrayValues)
            ->attach(new ArraySliceFilter(self::ARRAY_ELEMENTS))
            ->attach(new ArrayOfStringFilter(self::MESSAGE_LENGTH));
        $input->getValidatorChain()->attach(new FlatArray);
        $this->add($input);
    }

    /**
     * Add filters/validation for url
     */
    private function addUrlFilter()
    {
        // Use DirectInput for not required field to run validators. If Input is used
        // and required is false Zend will not run a provided 'null' through validation
        // and a 500 error may occur if called with null
        $input = new DirectInput(Model::FIELD_URL);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1, 'max' => 1024]));
        $this->add($input);
    }

    /**
     * Add filters/validation for title
     */
    private function addTitleFilter()
    {
        // Use DirectInput for not required field to run validators. If Input is used
        // and required is false Zend will not run a provided 'null' through validation
        // and a 500 error may occur if called with null
        $input = new DirectInput(Model::FIELD_TITLE);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1, 'max' => 1048]));
        $this->add($input);
    }

    /**
     * Add filters/validation for url
     */
    private function addUuidFilter()
    {
        // Use DirectInput for not required field to run validators. If Input is used
        // and required is false Zend will not run a provided 'null' through validation
        // and a 500 error may occur if called with null
        $input = new DirectInput(Model::FIELD_UUID);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 32, 'max' => 64]));
        $this->add($input);
    }

    /**
     * Checks data to remove any optional fields that are set in data but have a null value.
     * @param array $data
     */
    public function removeOptional(array &$data)
    {
        $inputs = $this->getInputs();
        foreach ($inputs as $input) {
            if (!$input->isRequired() && !isset($data[$input->getName()])) {
                unset($data[$input->getName()]);
            }
        }
    }
}
