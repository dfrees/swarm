<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

/**
 * Definition of service aliases that classes should use to look up
 * @package Application\Config
 */
interface Services
{
    const GROUP_FILTER            = 'groupFilter';
    const AUTH_HELPER             = 'authHelper';
    const SAML                    = 'php-saml';
    const AFFECTED_PROJECTS       = 'affectedProjects';
    const CHANGE_SERVICE          = 'changeService';
    const CHANGE_COMPARATOR       = 'changeComparator';
    const FILE_SERVICE            = 'fileService';
    const CONFIG_CHECK            = 'config_check';
    const GET_PROJECT_README      = 'getProjectReadme';
    const TRANSITIONS             = 'transitions';
    const WORKFLOW_MANAGER        = 'workflowManager';
    const REDIS_CACHE_VERIFY      = 'redisCacheVerify';
    const VOTE_INPUT_FILTER       = 'voteInputFilter';
    const ARCHIVER                = 'archiver';
    const PERMISSIONS             = 'permissions';
    const WORKFLOW_FILTER         = 'workflowFilter';
    const GLOBAL_WORKFLOW_FILTER  = 'globalWorkflowFilter';
    const LINKIFY                 = 'linkify';
    const GET_REVIEWS_FILTER      = 'getReviewsFilter';
    const PROJECTS_FOR_USER       = 'projectsForUser';
    const GET_PROJECTS_FILTER     = 'getProjectsFilter';
    const GET_USERS_FILTER        = 'getUsersFilter';
    const SWARM_REQUEST           = 'Request';
    const GET_GROUPS_FILTER       = 'getGroupsFilter';
    const FILE_READ_UNREAD_FILTER = 'fileReadUnReadFilter';
}
