<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Filter;

use Laminas\Filter\AbstractFilter;

class ProjectList extends AbstractFilter
{
    /**
     * Normalize the input projects (and their associated branches).
     *
     * The input can be provided as a string, as a simple array with project ids as values,
     * or as an array with project ids as keys and their associated branches as values.
     *
     * For example:
     *  'project1'
     * or
     *  array(
     *      'project1',
     *      'project2' => 'main',
     *      'project3' => array('main', 'dev')
     *  )
     *
     * @param   array|string    $projects   the projects to normalize
     * @return  array           normalized list of projects and their associated branches
     * @throws  \InvalidArgumentException   if input is not correctly formatted.
     */
    public function filter($projects)
    {
        $projects = (array) $projects;
        $filtered = [];
        foreach ($projects as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $filtered += [$value => []];
            } elseif (is_array($value) || is_string($value)) {
                $filtered      += [$key => []];
                $filtered[$key] = array_values(array_unique(array_merge($filtered[$key], (array) $value)));
            } else {
                throw new \InvalidArgumentException(
                    'Expecting array of projects or project keys with branch values.'
                );
            }
        }

        return $filtered;
    }

    /**
     * Normalize and merge two project/branch lists into one.
     *
     * @param   string|array    $a  projects to normalize/merge
     * @param   string|array    $b  projects to normalize/merge
     * @return  array           the normalized and merged projects/branches list.
     */
    public function merge($a, $b)
    {
        $a = $this->filter($a);
        $b = $this->filter($b);
        foreach ($b as $project => $branches) {
            $a          += [$project => []];
            $a[$project] = array_values(array_unique(array_merge($a[$project], $branches)));
        }

        return $a;
    }
}
