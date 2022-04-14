<?php

namespace Reviews\Filter;

use Application\Filter\FilterTrait;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;

/**
 * Filter to validate the body values for append/replace change list call
 */
class AppendReplaceChange extends InputFilter implements IAppendReplaceChange
{
    use FilterTrait;

    /**
     * Construct the filter
     * @param ContainerInterface $services services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->addInt(self::CHANGE_ID, null, PHP_INT_MAX, true);
        $this->addBool(self::PENDING);
    }
}
