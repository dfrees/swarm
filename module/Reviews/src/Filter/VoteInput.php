<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;
use Reviews\Model\Review as ReviewModel;
use Laminas\InputFilter\Input;

/**
 * Class VoteInput. Input field field for votes so filters and validation can be run
 * @package Reviews\Filter
 */
class VoteInput extends InputFilter implements InvokableService
{
    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $input = new Input(ReviewModel::FIELD_VOTE);
        $input->getFilterChain()->attach(new Vote());
        $input->getValidatorChain()->attach(
            new VoteValidator($services->get(TranslatorFactory::SERVICE))
        );
        $this->add($input);
    }
}
