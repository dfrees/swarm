<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Controller;

use Laminas\View\Model\JsonModel;

/**
 * Interface IActivityApi. Define responsibilities and common values for the activity API.
 * @package Activity\Controller
 */
interface IActivityApi
{
    // Label for data returned
    const DATA_ACTIVITY = 'activity';
    // Valid stream values
    const REVIEWS_STREAM = 'reviews';

    const STREAM_ID = 'streamId';
    const STREAM    = 'stream';

    const GROUPHYPHEN = 'group-';
    /**
     * Get activity by type. Type can be any value but will typically be 'review', 'job', 'change', or similar.
     * The type is part of the path, for example /api/<version>/activity/<type>
     * @return JsonModel
     * @see ActivityApi::getList() for result example
     */
    public function getByTypeAction() : JsonModel;

    /**
     * Get activity by stream and stream id. Stream must be a valid value, for example 'reviews'. The stream id should
     * be an id of an stream entity.
     * Stream and stream id are part of the path, for example /api/<version>/<stream>/<streamId>/activity. In the case
     * of 'reviews' as a stream value the stream id must be a valid review id
     * @return JsonModel
     * @see ActivityApi::getList() for result example
     */
    public function getByStreamAction() : JsonModel;
}
