<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Filter;

use Application\Filter\FilterTrait;
use Application\Filter\FormBoolean;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\StringLength;

class GetChanges extends InputFilter implements IChange
{
    use FilterTrait;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        // Pending is optional
        $this->addPendingFilter();
        // Root path will be optional, length > 1
        $this->addRootPathFilter();
        // User id will be optional, length > 1
        $this->addUserIdFilter();
        // Last seen changelist number must be an integer greater zero where provided
        $this->addInt(self::LAST_SEEN);
    }

    /**
     * Validate that when pending is passed, it is a valid boolean
     */
    private function addPendingFilter()
    {
        $input = new DirectInput(self::PENDING);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE=>false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Validate that, where a user id is provided, it is at least size of 1
     */
    private function addUserIdFilter()
    {
        $input = new DirectInput(self::USER);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min'=>1]));
        $this->add($input);
    }

    /**
     * Validate that, where a root path is provided, it is at least size of 1
     */
    private function addRootPathFilter()
    {
        $input = new DirectInput(self::ROOT_PATH);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min'=>1]));
        $this->add($input);
    }
}
