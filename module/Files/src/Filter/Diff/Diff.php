<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Files\Filter\Diff;

use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\ArrayValuesValidator;
use Application\Validator\BetweenInt;
use Application\Validator\GreaterThanInt;
use Files\Filter\RevisionValidator;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Class TestRun to filter and validate TestRun
 * @package TestIntegration\Filter
 */
class Diff extends InputFilter implements IDiff
{
    private $services;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->addFromFilter();
        $this->addToFilter();
        $this->addContextLinesFilter();
        $this->addIgnoreWSFilter();
        $this->addMaxSizeFilter();
        $this->addMaxDiffsFilter();
        $this->addOffsetFilter();
        $this->addTypeFilter();
        $this->addFromFileFilter();
    }

    /**
     * Add filters/validation for 'from' revision
     */
    private function addFromFilter()
    {
        $input = new DirectInput(self::FROM);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new RevisionValidator($this->services->get(TranslatorFactory::SERVICE)));
        $this->add($input);
    }

    /**
     * Add filters/validation for 'to' version
     */
    private function addToFilter()
    {
        $input = new Input(self::TO);
        $input->getValidatorChain()->attach(new RevisionValidator($this->services->get(TranslatorFactory::SERVICE)));
        $this->add($input);
    }

    /**
     * Add filters/validation for lines
     */
    private function addContextLinesFilter()
    {
        $input = new DirectInput(self::LINES);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => -1], $this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for ignore_ws
     */
    private function addIgnoreWSFilter()
    {
        $input = new DirectInput(self::IGNORE_WS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new BetweenInt(['min' => 0, 'max' => 3], $this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for max
     */
    private function addMaxSizeFilter()
    {
        $input = new DirectInput(self::MAX_SIZE);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => -2], $this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for max_diffs
     */
    private function addMaxDiffsFilter()
    {
        $input = new DirectInput(self::MAX_DIFFS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => -2], $this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for offset
     */
    private function addOffsetFilter()
    {
        $input = new DirectInput(self::OFFSET);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => -1], $this->services));
        $this->add($input);
    }

    /**
     * Add filters/validation for type
     * Only allow 'file' and 'stream' as valid types
     */
    private function addTypeFilter()
    {
        $valid = ['file', 'stream'];
        $input = new DirectInput(self::TYPE);
        $input->setRequired(false);
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        $validator  = new ArrayValuesValidator($translator, $valid, self::TYPE_ERROR_KEY, self::TYPE);
        $input->getValidatorChain()->attach($validator);
        $this->add($input);
    }

    /**
     * Add filters/validation for fromFile
     */
    private function addFromFileFilter()
    {
        $input = new DirectInput(self::FROM_FILE);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }
}
