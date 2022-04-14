<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Groups\Filter;

use Application\Filter\ArrayValues;
use Application\Filter\FormBoolean;
use Application\Filter\ReplaceString;
use Application\Validator\IdsValidator;
use Application\InputFilter\DirectInput;
use Application\Validator\IsBool;
use Groups\Model\IGroup;
use Interop\Container\ContainerInterface;
use Laminas\InputFilter\InputFilter;
use Groups\Model\Config;

/**
* Defines filters to run for getting groups.
* @package Groups\Filter
*/
class GetGroups extends InputFilter implements IGetGroups
{
    private $services;
    /**
     * Get groups filter constructor.
     *
     * @param ContainerInterface $services service to access properties.
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->addExpandFilter();
        $this->addIdFilter();
    }

    /**
     * Add a filter for ids and ensure they are array's of string or a string.
     */
    private function addIdFilter()
    {
        $input = new DirectInput(IGroup::FETCH_BY_ID);
        $input->setRequired(false);
        $input->getFilterChain()
            ->attach(new ArrayValues)
            ->attach(new ReplaceString(Config::KEY_PREFIX));
        $input->getValidatorChain()->attach(new IdsValidator($this->services, "Groups"));
        $this->add($input);
    }

    /**
     * Add a filter for if we should expand the groups or not.
     */
    private function addExpandFilter()
    {
        $input = new DirectInput(IGroup::FETCH_BY_EXPAND);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }
}
