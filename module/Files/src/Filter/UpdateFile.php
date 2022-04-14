<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Filter;

use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\IsString;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Class File. Filter for file updates
 * @package Files\Filter
 */
class UpdateFile extends InputFilter implements IFile
{
    private $translator;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addContentFilter();
        $this->addDescriptionFilter();
        $this->addCommentFilter();
        $this->addChangeActionFilter();
        $this->addFileNameFilter();
    }

    /**
     * Make sure a file name is specified
     */
    private function addFileNameFilter()
    {
        $input = new Input(self::FILE_NAME);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }

    /**
     * Action must be a valid change action
     */
    private function addChangeActionFilter()
    {
        $input = new Input(self::ACTION);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new ChangeActionValidator($this->translator));
        $this->add($input);
    }

    /**
     * Description must be present and be a string minimum 1 character
     */
    private function addDescriptionFilter()
    {
        $input = new Input(self::DESCRIPTION);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }

    /**
     * Optional comment field must be a string minimum 1 character if provided
     */
    private function addCommentFilter()
    {
        $input = new DirectInput(self::COMMENT);
        $input->setRequired(false);
        $input->getValidatorChain()
            ->attach(new IsString())
            ->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }

    /**
     * Ensure content is provided as a string
     */
    private function addContentFilter()
    {
        $input = new DirectInput(self::CONTENT);
        $input->setRequired(true);
        $input->getValidatorChain()->attach(new IsString());
        $this->add($input);
    }
}
