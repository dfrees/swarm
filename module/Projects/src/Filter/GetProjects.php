<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Filter;

use Api\IRequest;
use Application\Connection\ConnectionFactory;
use Application\Filter\FormBoolean;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;

/**
 * Defines filters to run for getting projects.
 * @package Projects\Filter
 */
class GetProjects extends InputFilter implements IGetProjects
{
    private $translator;
    private $connectionOption;

    /**
     * Get projects filter constructor.
     *
     * @param mixed $services services to get connection etc.
     * @param array $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator                     = $services->get(TranslatorFactory::SERVICE);
        $this->connectionOption['connection'] = (isset($options['connection']) && $options['connection'])
            ? $options['connection']
            : $services->get(ConnectionFactory::P4_ADMIN);
        $this->addMetadataFilter();
        $this->addIdsFilter();
    }

    /**
     * Adds a filter for metadata to ensure if present the value is boolean or can be converted to boolean.
     */
    private function addMetadataFilter()
    {
        $input = new DirectInput(IRequest::METADATA);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Add the ids filter to validate the project ids being passes are valid.
     */
    private function addIdsFilter()
    {
        $input = new DirectInput(IGetProjects::IDS);
        $input->setRequired(false);
        $this->add($input);
    }
}
