<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TagProcessor\Filter;

use Interop\Container\ContainerInterface;
use Laminas\Filter\AbstractFilter;

/**
 * Class Keywords
 *
 * @package TagProcessor\Filter
 */
abstract class TagFilter extends AbstractFilter implements ITagFilter
{
    protected $patterns = null;

    /**
     * Constructor for the service.
     * @param ContainerInterface $services application services
     * @param array $options    options['patterns'] = Regex to be used.
     *                          at present single pattern but later expand to multiple if required.
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $patterns = $options[ITagFilter::PATTERNS];
        $this->setPatterns($patterns);
    }

    /**
     * @inheritDoc
     */
    public function filter($string): string
    {
        return $string;
    }

    /**
     * @inheritDoc
     */
    public function setPatterns(string $patterns = null): TagFilter
    {
        $this->patterns = (array) $patterns;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPatterns(): array
    {
        return (array) $this->patterns;
    }

    /**
     * @inheritDoc
     */
    public function isDisabled(): bool
    {
        foreach ($this->getPatterns() as $pattern) {
            if ($pattern !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function hasMatches(string $string): bool
    {
        foreach ($this->getPatterns() as $pattern) {
            if (preg_match($pattern, $string, $matches)) {
                return true;
            }
        }
        return false;
    }
}
