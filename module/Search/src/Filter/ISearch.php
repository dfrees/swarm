<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Search\Filter;

use Application\Config\ConfigManager;
use Application\Config\IDao;

/**
 * Interface ISearch
 * @package Search\Filter
 */
interface ISearch
{
    const SEARCH_FILTER    = 'searchFilter';
    const TERM             = 'term';
    const CONTEXT          = 'context';
    const LIMIT            = 'limit';
    const STARTS_WITH_ONLY = 'starts_with_only';
    const FILE_CONTEXTS    = ['filePath', 'fileContent'];
    const PATH             = 'path';

    // Contexts that support searching
    const DAO_CONTEXTS = [
        'user'  => [
            'dao' => IDao::USER_DAO,
            'excludeList' => ConfigManager::MENTIONS_USERS_EXCLUDE_LIST
        ],
        'group' => [
            'dao' => IDao::GROUP_DAO,
            'excludeList' => ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST
        ],
        'project' => [
            'dao' => IDao::PROJECT_DAO
        ],
    ];
}
