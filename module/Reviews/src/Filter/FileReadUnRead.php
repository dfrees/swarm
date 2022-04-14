<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\BetweenInt;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\StringLength;

/**
 * Defines filters for file read and unread operation.
 * @package Reviews\Filter
 */
class FileReadUnRead extends InputFilter implements IFileReadUnRead
{

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->addVersionFilter();
        $this->addFilePathFilter();
    }

    /**
     * Add a version filter for version field
     */
    private function addVersionFilter()
    {
            $input = new DirectInput(self::VERSION);
            $input->setRequired(true);
            $input->getValidatorChain()->attach(new BetweenInt(['min' => 0, 'max' => PHP_INT_MAX]));
            $this->add($input);
    }

    /**
     * Add a string filter for path field
     */
    private function addFilePathFilter()
    {
        $input = new DirectInput(self::PATH);
        $input->setRequired(true);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }
}
