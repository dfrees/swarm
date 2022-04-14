<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\Filter;

use Api\IRequest;
use Application\Filter\FormBoolean;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;
use Application\Connection\ConnectionFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Users\Validator\Users as UserValidator;

/**
 * Defines filters to run for getting users.
 * @package Users\Filter
 */
class GetUsers extends InputFilter implements IGetUsers
{
    private $connectionOption;

    /**
     * Get users filter constructor.
     *
     * @param mixed $services services to get connection etc.
     * @param array $options  If p4 connection provided then it will use else fallback to admin connection
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->connectionOption['connection'] = (isset($options['connection']) && $options['connection'])
            ? $options['connection']
            : $services->get(ConnectionFactory::P4_ADMIN);
        $this->addUsersFilter();
        $this->addIgnoreExcludeListFilter();
    }
    /**
     * Add the users filter to validate the users being passes are valid.
     */
    private function addUsersFilter()
    {
        $input = new DirectInput(IGetUsers::IDS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new UserValidator($this->connectionOption));
        $this->add($input);
    }
    /**
     * Add the ignoreExcludeList filter to validate the value are valid.
     */
    private function addIgnoreExcludeListFilter()
    {
        $input = new DirectInput(IRequest::IGNORE_EXCLUDE_LIST);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }
}
