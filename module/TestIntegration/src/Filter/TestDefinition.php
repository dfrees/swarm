<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\Config\IDao;
use Application\Filter\FormBoolean;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\FlatArray;
use Application\Validator\IsArray;
use Application\Validator\IsBool;
use Application\Validator\UniqueForField;
use Interop\Container\ContainerInterface;
use TestIntegration\Model\TestDefinition as Model;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Application\Validator\GreaterThanInt;
use Laminas\Validator\StringLength;
use Application\Validator\Owners;
use TestIntegration\Model\ITestDefinition as IModelTestDefinition;

/**
 * Class TestDefinitionFilter to filter and validate TestDefinition
 * @package TestIntegration\Filter
 */
class TestDefinition extends InputFilter implements ITestDefinition
{
    private $translator;
    private $services;
    private $existingId;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        // We need to be aware of an existing id so that if a name is changed we can determine we are not changing it
        // to the same as a different record
        $this->existingId = isset($options[IModelTestDefinition::FIELD_ID])
            ? $options[IModelTestDefinition::FIELD_ID]
            : null;
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addHeaderFilter();
        $this->addEncodingFilter();
        $this->addNameFilter();
        $this->addBodyFilter();
        $this->addUrlFilter();
        $this->addTimeoutFilter();
        $this->addOwnerFilter();
        $this->addSharedFilter();
        $this->addDescriptionFilter();
        $this->addIterateFilter();
    }

    /**
     * Set up the filter for 'owners'
     */
    private function addOwnerFilter()
    {
        $input = new Input(Model::FIELD_OWNERS);
        $input->getValidatorChain()
            ->attach(new IsArray(), true)
            ->attach(new Owners($this->services));
        $this->add($input);
    }

    /**
     * Set up the filter for 'shared'
     */
    protected function addSharedFilter()
    {
        $input = new DirectInput(Model::FIELD_SHARED);
        $input->setRequired(true);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Set up the filter for 'description'
     */
    protected function addDescriptionFilter()
    {
        $input = new DirectInput(Model::FIELD_DESCRIPTION);
        $input->setRequired(true);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 0]));
        $this->add($input);
    }

    /**
     * Add to the chain to filter and validate encoding
     */
    private function addEncodingFilter()
    {
        $input = new Input(Model::FIELD_ENCODING);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 3, 'max' => 4]));
        $input->getValidatorChain()->attach(new EncodingValidator($this->translator));
        $this->add($input);
    }

    /**
     * Add to the chain to filter and validate headers
     */
    private function addHeaderFilter()
    {
        $input = new Input(Model::FIELD_HEADERS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new FlatArray);
        $this->add($input);
    }

    /**
     * Add to the chain to filter and validate timeout
     */
    private function addTimeoutFilter()
    {
        $input = new Input(Model::FIELD_TIMEOUT);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => 0, 'inclusive' => true]));
        $this->add($input);
    }

    /**
     * Add to the chain to filter and validate body
     */
    private function addBodyFilter()
    {
        $input = new DirectInput(Model::FIELD_BODY);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new StringLength(['min' => 0]))
            ->attach(new BodyValidator($this->services));
        $this->add($input);
    }

    /**
     * Add to the chain to filter and validate url
     */
    private function addUrlFilter()
    {
        $input = new Input(Model::FIELD_URL);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1, 'max' => 1024]));
        $this->add($input);
    }

    /**
     * Set up the filter for 'iterate'
     */
    protected function addIterateFilter()
    {
        $input = new DirectInput(Model::FIELD_ITERATE_PROJECT_BRANCHES);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => true]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }


    /**
     * Add to the chain to filter and validate name
     */
    private function addNameFilter()
    {
        $input = new Input(Model::FIELD_NAME);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new StringLength(['min' => 1, 'max' => 32]), true)
            ->attach(
                new UniqueForField(
                    $this->services,
                    'test definition',
                    IDao::TEST_DEFINITION_DAO,
                    IModelTestDefinition::FIELD_NAME,
                    [IModelTestDefinition::FIELD_ID => $this->existingId]
                )
            );
        $this->add($input);
    }
}
