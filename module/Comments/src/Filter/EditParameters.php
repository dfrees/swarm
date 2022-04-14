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
use Application\I18n\TranslatorFactory;
use Application\InputFilter\InputFilter;
use Comments\Validator\Notify;
use Interop\Container\ContainerInterface;
use Application\InputFilter\DirectInput;

/**
 * Class EditParameters. A filter to handle parameters that may be passed as part of comment editing
 * @package Comments\Filter
 */
class EditParameters extends InputFilter implements InvokableService
{
    private $translator;

    /**
     * EditParameters constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addNotify();
    }

    /**
     * Validate notify
     */
    private function addNotify()
    {
        $input = new DirectInput(Notify::NOTIFY_FIELD);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new Notify($this->translator));
        $this->add($input);
    }
}
