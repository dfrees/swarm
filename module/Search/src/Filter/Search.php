<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Search\Filter;

use Api\IRequest;
use Application\Factory\InvokableService;
use Application\Filter\FormBoolean;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\GreaterThanInt;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;
use Search\Validator\Context;

/**
 * A filter to validate the inputs to the search API
 */
class Search extends InputFilter implements ISearch, InvokableService
{
    private $translator;

    /**
     * Search constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addTermFilter();
        $this->addContextFilter();
        $this->addLimitFilter();
        $this->addStartsWithOnlyFilter();
        $this->addIgnoreExcludeListFilter();
        $this->addPathFilter();
    }

    /**
     * Add validation for 'term'
     */
    private function addTermFilter()
    {
        $input = new Input(self::TERM);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }

    /**
     * Add validation for 'context'
     */
    private function addContextFilter()
    {
        $input = new Input(self::CONTEXT);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new Context($this->translator));
        $this->add($input);
    }

    /**
     * Add validation for 'limit'
     */
    private function addLimitFilter()
    {
        $input = new DirectInput(self::LIMIT);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['inclusive'=> true, 'nullable' => true, 'min' => 0]));
        $this->add($input);
    }

    /**
     * Add validation for 'starts with only'
     */
    private function addStartsWithOnlyFilter()
    {
        $input = new DirectInput(self::STARTS_WITH_ONLY);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean());
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Add validation for 'ignore exclude list'
     */
    private function addIgnoreExcludeListFilter()
    {
        $input = new DirectInput(IRequest::IGNORE_EXCLUDE_LIST);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean());
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Add validation for 'path'
     */
    private function addPathFilter()
    {
        $input = new DirectInput(self::PATH);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }
}
