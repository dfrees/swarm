<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Filter;

use Application\I18n\TranslatorFactory;
use Interop\Container\ContainerInterface;

/**
 * Class EditComment. Filter to validate content when a comment is updated
 * @package Comments\Filter
 */
class EditComment extends Comment implements IComment
{
    /**
     * EditComment constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->addBodyValidator();
        $this->addTaskStateValidator();
    }
}
