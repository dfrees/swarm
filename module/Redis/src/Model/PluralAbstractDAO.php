<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

/**
 * Abstract DAO for handling plural abstract models.
 * @package Redis\Model
 */
class PluralAbstractDAO extends AbstractDAO
{

    /**
     * @inheritDoc
     */
    protected function generateModelKeys($models, $rebuild = false)
    {
        $modelKeys = [];
        // Default implementation is to create no extra indices
        foreach ($models as $model) {
            // Make sure the model is fully populated when we cache it
            $model->populate();
            $modelKeys[$this->buildModelKeyId($model)] = $model;
        }
        $this->addToSet(array_keys($modelKeys));
        return $modelKeys;
    }
}
