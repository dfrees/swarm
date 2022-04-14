<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Filter;

use Laminas\Filter\AbstractFilter;

/**
 * Class DefaultValue. Filter to help with defaulting of values
 * @package Application\Filter
 */
class DefaultValue extends AbstractFilter
{
    const DEFAULT_WHEN   = 'defaultWhen';
    const DEFAULT        = 'default';
    private $default     = null;
    private $defaultWhen = [null];

    /**
     * DefaultValue constructor.
     * @param null $options self::DEFAULT => <a default value> : the value to default to when criteria is met
     *                      self::DEFAULT_WHEN => [] : values that trigger a default. Defaults to null meaning that
     *                      if the value is null it will be defaulted. ['', null] would default if the value was
     *                      '' or null.
     */
    public function __construct($options = null)
    {
        if (isset($options[self::DEFAULT])) {
            $this->default = $options[self::DEFAULT];
        }
        if (isset($options[self::DEFAULT_WHEN])) {
            $this->defaultWhen = $options[self::DEFAULT_WHEN];
        }
    }

    /**
     * Filter the value. See constructor comments
     * @param mixed $value  value to filter
     * @return mixed|null
     */
    public function filter($value)
    {
        foreach ((array)$this->defaultWhen as $defaultWhen) {
            if ($value === $defaultWhen && $this->default !== null) {
                return $this->default;
            }
        }
        return $value;
    }
}
