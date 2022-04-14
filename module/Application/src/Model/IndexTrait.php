<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Model;

/**
 * Trait IndexTrait. Provide common functions for model indexing
 * @package Application\Model
 */
trait IndexTrait
{

    /**
     * Run a command to add the values from an index
     * @param int   $code       the index code
     * @param array $values     values to index
     * @param bool  $encode     whether to encode the value before removing (defaults to true)
     * @param mixed $class      class to use to call the encoding function (defaults to this)
     */
    protected function setIndexValue(int $code, array $values, bool $encode = true, $class = null)
    {
        $this->getConnection()->run(
            'index',
            ['-a', $code, $this->id],
            implode(' ', $this->processIndexValue($values, $encode, $class))
        );
    }

    /**
     * Run a command to remove the values from an index
     * @param int   $code       the index code
     * @param array $remove     values to remove
     * @param bool  $encode     whether to encode the value before removing (defaults to true)
     * @param mixed $class      class to use to call the encoding function (defaults to this)
     * @return mixed the result of the remove command
     */
    protected function removeIndexValue(int $code, array $remove, bool $encode = true, $class = null)
    {
        return $this->getConnection()->run(
            'index',
            ['-a', $code, '-d', $this->id],
            implode(' ', $this->processIndexValue($remove, $encode, $class))
        );
    }

    /**
     * Process the values ready for indexing
     * @param array     $values     values to process
     * @param bool      $encode     whether to encode the value before removing
     * @param mixed     $class      class to use to call the encoding function (defaults to this)
     * @return array
     */
    private function processIndexValue(array $values, bool $encode, $class = null)
    {
        $class = $class ? $class : get_class($this);
        if ($encode) {
            $values = array_map([$class, 'encodeIndexValue'], $values);
        }
        return $values;
    }
}
