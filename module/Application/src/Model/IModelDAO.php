<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Model;

use Application\Exception\NotImplementedException;
use P4\Connection\ConnectionInterface;
use Application\Config\IDao;

/**
 * Interface for all Swarm DAOs to implement
 * @package Application\Model
 */
interface IModelDAO extends IDao
{
    const FETCHALL       = 'fetchAll';
    const FETCH          = 'fetch';
    const FETCH_NO_CACHE = 'noCache';
    // Only fetch the model summary data
    const FETCH_SUMMARY   = 'summary';
    const FILTER_PRIVATES = 'filterPrivates';
    // Option to specify sort fields when fetching all
    const SORT = 'sort';

    /**
     * Tests if a record exists.
     * @param string            $id         the id of the record, for example a user id.
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed
     */
    public function exists($id, ConnectionInterface $connection = null);

    /**
     * Examines the array of ids and returns those that exist
     * @param array                     $ids            ids to examine
     * @param ConnectionInterface|null  $connection     the connection
     * @return array an array of ids that exist, or an empty array if none are valid
     */
    public function existIds(array $ids, ConnectionInterface $connection = null) : array;

    /**
     * Fetches all records.
     * @param array             $options    fetch all options.
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed
     */
    public function fetchAll(array $options = [], ConnectionInterface $connection = null);

    /**
     * Fetch a record by its id.
     * @param string            $id         the id of the record, for example a user id.
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed
     * @deprecated Use fetchById
     */
    public function fetch($id, ConnectionInterface $connection = null);

    /**
     * Fetch a record by its id. This call will return the result from the model fetch directly
     * without applying any restrictions as to what is returned. For example in the case of
     * reviews the review will be returned without checking access due to private projects etc.
     *
     * @param mixed            $id         the id of the record, for example a user id.
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed
     */
    public function fetchByIdUnrestricted($id, ConnectionInterface $connection = null);

    /**
     * Fetch a record by its id.
     *
     * @param string            $id         the id of the record, for example a user id.
     * @param ConnectionInterface|null $connection the P4 connection
     * @return mixed
     */
    public function fetchById($id, ConnectionInterface $connection = null);

    /**
     * Save the model.
     * @param mixed     $model  the Perforce model class to save
     * @return mixed
     */
    public function save($model);

    /**
     * Delete the model.
     * @param mixed     $model  the Perforce model class to save
     * @return mixed
     */
    public function delete($model);

    /**
     * Place a task for processing in the queue
     * @param mixed         $model  the Perforce model
     * @param array|null    $data   data for the task to add to or replace the default data built
     * @throws NotImplementedException by default the abstract class does not implement this functionality it is
     * the responsibility of the sub-classes to implement
     * @return mixed
     */
    public function queueTask($model, $data = null);
}
