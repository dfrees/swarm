<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

/**
 * Class KeywordsFields. A class to help determine the fields that are allowed in keywords fields queries
 * @package Application
 */
class KeywordsFields
{
    /**
     * Gets the allowed keywords fields for a model. Allowed fields are all those that are indexed on the model plus
     * the 'id' field.
     * @param mixed $model  the model
     * @return string[] the allowed keywords fields
     */
    public static function getKeywordsFields($model) : array
    {
        $fields  = array_filter((array)$model->getFields(), [$model, 'getIndexCode']);
        $fields += ['id'];
        return $fields;
    }
}
