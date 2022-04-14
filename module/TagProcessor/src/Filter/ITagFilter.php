<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace TagProcessor\Filter;

use Application\Factory\InvokableService;

/**
 * Interface ITagFilter
 *
 * @package TagProcessor\Filter
 */
interface ITagFilter extends InvokableService
{
    const PATTERNS    = "patterns";
    const WIP_KEYWORD = "wip_keywords";

    /**
     * Filter to see if string contains the pattern.
     *
     * @param   string  $string     text that potentially contains keyword(s)
     * @return  string   string of found keyword values (empty if none)
     */
    public function filter($string): string;

    /**
     * Set the patterns being used.
     *
     * @param string|null     $patterns the patterns to use or null
     * @return  TagFilter    to maintain a fluent interface
     */
    public function setPatterns(string $patterns = null): TagFilter;

    /**
     * Returns the currently specified array of keyword patterns.
     * See setPatterns for details.
     *
     * @return  array   array of patterns
     */
    public function getPatterns(): array;

    /**
     * Returns if the filter should be disabled, e.g. when the pattern is empty
     *
     * @return bool If the filter is disabled
     */
    public function isDisabled(): bool;

    /**
     * If the string contains the pattern return the patch
     * @param string $string The string to be match against.
     * @return bool If patten match return true.
     */
    public function hasMatches(string $string): bool;
}
