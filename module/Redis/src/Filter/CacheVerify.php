<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Filter;

use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use Redis\Manager;
use Laminas\Filter\AbstractFilter;

/**
 * Filter to handle requests to cache verify
 * @package Api\Filter
 */
class CacheVerify extends AbstractFilter implements InvokableService
{

    private $services   = null;
    private $translator = null;
    

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->translator = $services->get(TranslatorFactory::SERVICE);
    }

    /**
     * @param $context
     * @return array|mixed
     */
    public function filter($context)
    {
        if (empty($context)) {
            return Manager::CONTEXTS;
        }
        $context = str_replace(' ', '', explode(',', $context));
        $diff    = array_diff($context, Manager::CONTEXTS);
        if ($diff) {
            throw new InvalidArgumentException(
                $this->translator->t(
                    "Invalid context [%s], must contain only [%s]",
                    [implode(', ', $context), implode(', ', Manager::CONTEXTS)]
                )
            );
        }
        return $context;
    }
}
