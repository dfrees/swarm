<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Config;

interface IDao
{
    // Aliases for DAOs
    const USER_DAO            = 'userDAO';
    const GROUP_DAO           = 'groupDAO';
    const PROJECT_DAO         = 'projectDAO';
    const WORKFLOW_DAO        = 'workflowDAO';
    const KEY_DAO             = 'keyDAO';
    const CHANGE_DAO          = 'changeDAO';
    const TEST_DEFINITION_DAO = 'testDefinitionDao';
    const TEST_RUN_DAO        = 'testRunDao';
    const MENU_DAO            = 'menuDAO';
    const REVIEW_DAO          = 'reviewDAO';
    const JOB_DAO             = 'jobDAO';
    const SPEC_DAO            = 'specDAO';
    const FILE_DAO            = 'fileDAO';
    const COMMENT_DAO         = 'commentDAO';
    const ACTIVITY_DAO        = 'activityDAO';
    const FILE_INFO_DAO       = 'fileInfoDAO';
}
