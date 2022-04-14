<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jobs\Model;

/**
 * Interface IJob, common job fields
 * @package Jobs\Model
 */
interface IJob
{
    const FIELD_JOB                  = 'job';
    const FIELD_DESCRIPTION          = 'description';
    const FIELD_DESCRIPTION_MARKDOWN = 'descriptionMarkdown';
    const FIELD_LINK                 = 'link';
    const FIELD_FIX_STATUS           = 'fixStatus';
}
