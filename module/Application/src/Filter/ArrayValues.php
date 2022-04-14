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

class ArrayValues extends AbstractFilter
{
    /**
     * If the input $value is an array, return its values without keys.
     * If the $value is null or an empty string, convert it to an empty array.
     * All other values are returned unmodified.
     *
     * @param  mixed    $value
     * @return mixed
     */
    public function filter($value)
    {
        // we do this because commonly the filter is used with an array validator
        // and form inputs with no value often end up as empty strings on post
        $value = $value === '' || $value === null ? [] : $value;

        // if value is an array, return it, throwing away any provided keys
        // otherwise, return the original value
        return is_array($value) ? array_values($value) : $value;
    }

    /**
     * Return the values from a single column in the input array. This is a
     * alternate to array_column, due to array_column not existing in older
     * versions of PHP.
     *
     * @param   array           $array      The array to be processed
     * @param   string          $field      The field we are interested in.
     * @param   null            $idField    If field has ID pass this into
     * @return array|bool       The filtered array or boolean value.
     */
    public static function getFieldData($array, $field, $idField = null)
    {
        $_out = [];
        if (is_array($array)) {
            if ($idField === null) {
                foreach ($array as $value) {
                    if (isset($value[$field])) {
                        $_out[] = $value[$field];
                    }
                }
            } else {
                foreach ($array as $value) {
                    if (isset($value[$field]) && isset($value[$idField])) {
                        $_out[$value[$idField]] = $value[$field];
                    }
                }
            }
        }
        return $_out;
    }
}
