<?php
/**
 * Provides a common interface for models that utilize fields.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\Model\Fielded;

interface FieldedInterface
{
    /**
     * Get the model data as an array excluding hidden fields.
     * @return  array   the model data as an array.
     */
    public function toArray();

    /**
     * Get the model data as array including all fields.
     * @return array
     */
    public function toArrayStore();

    /**
     * Check if given field is valid model field.
     *
     * @param  string  $field  model field to check
     * @return boolean
     */
    public function hasField($field);

    /**
     * Return array with all model fields.
     *
     * @return array
     */
    public function getFields();

    /**
     * Return value of given field of the model.
     *
     * @param  string  $field  model field to retrieve
     * @return mixed
     */
    public function get($field);
}
