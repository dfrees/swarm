<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Model;

/**
 * Interface IActivity. Define values and responsibilities for a Activity
 * @package Activity\Model
 */
interface IActivity
{

    const ID          = 'id';
    const TYPE        = 'type';
    const LINK        = 'link';
    const USER        = 'user';
    const ACTION      = 'action';
    const TARGET      = 'target';
    const PREPOSITION = 'preposition';
    const DESCRIPTION = 'description';
    const DETAILS     = 'details';
    const DEPOTFILE   = 'depotFile';
    const TIME        = 'time';
    const BEHALFOF    = 'behalfOf';
    const PROJECTS    = 'projects';
    const STREAMS     = 'streams';
    const CHANGE      = 'change';
    const TOPIC       = 'topic';
    const FOLLOWERS   = 'followers';
}
