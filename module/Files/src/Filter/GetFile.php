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
 * Class File. Filter for file get
 * @package Files\Filter
 */
class GetFile extends InputFilter implements IFile
{
    private $translator;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addRevisionFilter();
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
     * Revision is optional but if provided must be a non-zero length string that
     * has a known revision specifier followed by a change list number
     */
    private function addRevisionFilter()
    {
        $input = new DirectInput(self::REVISION);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new StringLength(['min' => 1]))
            ->attach(new RevisionValidator($this->translator));
        $this->add($input);
    }
}
