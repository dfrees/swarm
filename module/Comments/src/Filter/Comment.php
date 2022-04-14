<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Filter;

use Application\Factory\InvokableService;
use Application\InputFilter\DirectInput;
use Application\Validator\IsString;
use Application\InputFilter\InputFilter;
use Comments\Model\IComment as ModelInterface;
use Comments\Validator\TaskState;
use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Class Comment
 * @package Comments\Filter
 */
abstract class Comment extends InputFilter implements InvokableService
{
    protected $translator;

    /**
     * Validate body value
     */
    protected function addBodyValidator($mandatory = false)
    {
        $input = new DirectInput(ModelInterface::BODY);
        $input->setRequired($mandatory);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min'=>1]));
        $this->add($input);
    }

    /**
     * Validate task state value
     */
    protected function addTaskStateValidator()
    {
        $input = new DirectInput(ModelInterface::TASK_STATE);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new TaskState($this->translator, [TaskState::SUPPORT_ARRAYS=>false]));
        $this->add($input);
    }
}
