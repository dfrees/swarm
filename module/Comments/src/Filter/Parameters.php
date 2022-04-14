<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Filter;

use Api\IRequest;
use Application\Filter\FilterTrait;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\InputFilter;
use Comments\Validator\TaskState;
use Interop\Container\ContainerInterface;
use Application\InputFilter\DirectInput;
use P4\Counter\AbstractCounter;
use Comments\Model\IComment;

/**
 * Class Parameters. Validates query parameters that may be part of a request
 * @package Comments\Filter
 */
class Parameters extends InputFilter implements IParameters
{
    use FilterTrait;
    private $translator;

    /**
     * Parameters constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        foreach ([AbstractCounter::FETCH_AFTER] as $field) {
            $this->addInt($field);
        }
        foreach ([IRequest::IGNORE_ARCHIVED, IRequest::TASKS_ONLY] as $field) {
            $this->addBool($field);
        }
        $this->addInt(AbstractCounter::FILTER_MAX, 100);
        $this->addTaskState();
    }

    /**
     * Validate task states
     */
    private function addTaskState()
    {
        $input = new DirectInput(IComment::TASK_STATE);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new TaskState($this->translator));
        $this->add($input);
    }
}
