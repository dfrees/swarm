<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api;

/**
 * Defines common field names used for JSON results
 * @package Api
 */
interface IModelFields
{
    const IS_VALID = 'isValid';
    const MESSAGES = 'messages';
    const DATA     = 'data';
    const PROGRESS = 'progress';
    const STATE    = 'state';
}
