<?php

namespace Jobs\Filter;

use Application\Filter\FilterTrait;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\StringLength;

/**
 * A filter/validator to check query parameters passed to a 'get jobs' call
 */
class GetJobs extends InputFilter implements IGetJobs
{
    use FilterTrait;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->addInt(self::FETCH_MAX);
        $this->addFilter();
    }

    /**
     * Adds a filter for jobs. This is an optional field but if provided should be a string with minimum length 1.
     */
    private function addFilter()
    {
        $input = new DirectInput(self::FILTER_PARAMETER);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }
}
