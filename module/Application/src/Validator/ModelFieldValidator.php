<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

/**
 * Class ModelFieldValidator to validate an array of fields against model fields
 * @package Application\Validator
 */
class ModelFieldValidator
{
    /**
     * Validates that all the fields are model fields
     * @param array     $fields     fields to validate
     * @param mixed     $model      model instance to validate against
     * @return bool true if all fields in $fields are listed as fields for $model, otherwise false
     */
    public function isValid(array $fields, $model) : bool
    {
        return sizeof(array_intersect($model->getFields(), $fields)) === sizeof($fields);
    }
}
