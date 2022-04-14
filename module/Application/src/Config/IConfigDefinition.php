<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Config;

use Workflow\Model\IWorkflow;

/**
 * Interface IConfigDefinition supporting constant definitions for configuration
 * @package Application\Config
 */
interface IConfigDefinition
{
    const ALLOW_EDITS                             = 'allow_edits';
    const HOURS_MINUTES_24_PATTERN                = '/^(([01][0-9]|2[0-3]):([0-5][0-9]))$/';
    const CONFIG                                  = 'config';
    const ENVIRONMENT                             = 'environment';
    const LOGOUT_URL                              = 'logout_url';
    const BASE_URL                                = 'base_url';
    const ASSET_BASE_PATH                         = 'asset_base_path';
    const VENDOR                                  = 'vendor';
    const EMOJI_PATH                              = 'emoji_path';
    const ENVIRONMENT_LOGOUT_URL                  = 'environment.logout_url';
    const ENVIRONMENT_EMOJI_URL                   = 'environment.vendor.emoji_path';
    const ENVIRONMENT_BASE_URL                    = self::ENVIRONMENT . '.' . self::BASE_URL;
    const ENVIRONMENT_ASSET_BASE_PATH             = self::ENVIRONMENT . '.' . self::ASSET_BASE_PATH;
    const ENVIRONMENT_MODE                        = self::ENVIRONMENT . '.' . self::MODE;
    const P4                                      = 'p4';
    const FILES                                   = 'files';
    const SSO                                     = 'sso';
    const SSO_ENABLED                             = 'sso_enabled';
    const PROXY_MODE                              = 'proxy_mode';
    const USERS                                   = 'users';
    const P4_PROXY_MODE                           = self::P4 . '.' . self::PROXY_MODE;
    const P4_SSO                                  = 'p4.sso';
    const P4_SSO_ENABLED                          = 'p4.sso_enabled';
    const MAX_CHANGELIST_FILES                    = 'max_changelist_files';
    const P4_MAX_CHANGELIST_FILES                 = self::P4 . '.' . self::MAX_CHANGELIST_FILES;
    const DIFFS                                   = 'diffs';
    const CONTEXT_LINES                           = 'context_lines';
    const MAX_DIFFS                               = 'max_diffs';
    const DIFF_MAX_DIFFS                          = self::DIFFS . '.' . self::MAX_DIFFS;
    const DIFF_CONTEXT_LINES                      = self::DIFFS . '.' . self::CONTEXT_LINES;
    const FILES_DOWNLOAD_TIMEOUT                  = self::FILES . '.' .'download_timeout';
    const MAX_SIZE                                = 'max_size';
    const FILES_MAX_SIZE                          = self::FILES . '.' . self::MAX_SIZE;
    const FILES_ALLOW_EDITS                       = self::FILES . '.' . self::ALLOW_EDITS;
    const EXPAND_ALL_FILE_LIMIT                   = 'expand_all_file_limit';
    const REVIEWS_EXPAND_ALL                      = self::REVIEWS . '.' . self::EXPAND_ALL_FILE_LIMIT;
    const DISABLE_APPROVE_WHEN_TASKS_OPEN         = 'disable_approve_when_tasks_open';
    const REVIEWS_DISABLE_APPROVE_WHEN_TASKS_OPEN = self::REVIEWS . '.' . self::DISABLE_APPROVE_WHEN_TASKS_OPEN;
    const EXPAND_GROUP_REVIEWERS                  = 'expand_group_reviewers';
    const REVIEWS_EXPAND_GROUP_REVIEWERS          = self::REVIEWS . '.' . self::EXPAND_GROUP_REVIEWERS;
    const FILTERS                                 = 'filters';
    const RESULT_SORTING                          = 'result_sorting';
    const REVIEWS_FILTERS_RESULT_SORTING          = self::REVIEWS . '.' . self::FILTERS . '.' . self::RESULT_SORTING;
    const DATE_FIELD                              = 'date_field';
    const REVIEWS_FILTERS_DATE_FIELD              = self::REVIEWS . '.'. self::FILTERS . '.' . self::DATE_FIELD;
    const UPGRADE_BATCH_SIZE                      = 'upgrade.batch_size';
    const UPGRADE_STATUS_REFRESH_INTERVAL         = 'upgrade.status_refresh_interval';
    const AVATARS_HTTP                            = 'avatars.http_url';
    const AVATARS_HTTPS                           = 'avatars.https_url';
    const SECURITY_REQUIRE_LOGIN                  = 'security.require_login';
    const QUEUE_WORKER_CHANGE_SAVE_DELAY          = self::QUEUE . '.' . self::WORKER_CHANGE_SAVE_DELAY;
    const TRANSLATOR                              = 'translator';
    const TRANSLATOR_DETECT_LOCALE                = self::TRANSLATOR . '.detect_locale';
    const NON_UTF8_ENCODINGS                      = 'non_utf8_encodings';
    const TRANSLATOR_NON_UTF8_ENCODINGS           = self::TRANSLATOR . '.' . self::NON_UTF8_ENCODINGS;
    const UTF8_CONVERT                            = 'utf8_convert';
    const TRANSLATOR_UTF8_CONVERT                 = self::TRANSLATOR . '.' . self::UTF8_CONVERT;
    const DASHBOARD_MAX_ACTIONS                   = self::USERS . '.maximum_dashboard_actions';
    const DASHBOARD_REFRESH_INTERVAL              = self::USERS . '.dashboard_refresh_interval';
    const DISPLAY_FULLNAME                        = 'display_fullname';
    const USER_DISPLAY_FULLNAME                   = self::USERS . '.' . self::DISPLAY_FULLNAME;
    const README_MODE                             = 'readme_mode';
    const RUN_TESTS_ON_UNCHANGED_SUBMIT           = 'run_tests_on_unchanged_submit';
    const PROJECTS_README_MODE                    = self::PROJECTS . '.' . self::README_MODE;
    const PROJECTS_RUN_TESTS_ON_UNCHANGED_SUBMIT  = self::PROJECTS . '.' . self::RUN_TESTS_ON_UNCHANGED_SUBMIT;
    const MAX_README_SIZE                         = 'max_readme_size';
    const PROJECTS_MAX_README_SIZE                = self::PROJECTS . '.' . self::MAX_README_SIZE;
    const FETCH                                   = 'fetch';
    const MAXIMUM                                 = 'maximum';
    const PROJECTS_FETCH_MAXIMUM                  = self::PROJECTS . '.'.self::FETCH.'.'. self::MAXIMUM;
    const MAINLINES                               = 'mainlines';
    const PROJECTS_MAINLINES                      = self::PROJECTS . '.' . self::MAINLINES;
    const COMMENT_SHOW_ID                         = 'comments.show_id';
    const COMMENT_THREADING_MAX_DEPTH             = 'comments.threading.max_depth';
    const COMMENT_NOTIFICATION_DELAY_TIME         = 'comments.notification_delay_time';
    const JIRA                                    = 'jira';
    const RELATIONSHIP                            = 'relationship';
    const LINK_TO_JOBS                            = 'link_to_jobs';
    const DELAY_JOB_LINKS                         = 'delay_job_links';
    const MAX_JOB_FIXES                           = 'max_job_fixes';
    const JOB_FIELD                               = 'job_field';
    const HOST                                    = 'host';
    const USER                                    = 'user';
    const DEFAULT                                 = 'default';
    const ENABLED                                 = 'enabled';
    const DISABLED                                = 'disabled';
    const OPTIONAL                                = 'optional';
    const AUTO                                    = 'auto';
    const COMMIT_TIMEOUT                          = 'commit_timeout';
    const COMMIT_CREDIT_AUTHOR                    = 'commit_credit_author';
    const REOPEN_FILES                            = 'reopenFiles';
    const PASSWORD                                = 'password';
    const REACT_ENABLED                           = 'react_enabled';
    const API_HOST                                = 'api_host';
    const JIRA_LINK_TO_JOBS                       = self::JIRA . '.' . self::LINK_TO_JOBS;
    const JIRA_DELAY_JOB_LINKS                    = self::JIRA . '.' . self::DELAY_JOB_LINKS;
    const JIRA_MAX_JOB_FIXES                      = self::JIRA . '.' . self::MAX_JOB_FIXES;
    const JIRA_API_HOST                           = self::JIRA . '.' . self::API_HOST;
    const JIRA_HOST                               = self::JIRA . '.' . self::HOST;
    const JIRA_USER                               = self::JIRA . '.' . self::USER;
    const JIRA_PASSWORD                           = self::JIRA . '.' . self::PASSWORD;
    const JIRA_JOB_FIELD                          = self::JIRA . '.' . self::JOB_FIELD;
    const JIRA_RELATIONSHIP                       = self::JIRA . '.' . self::RELATIONSHIP;
    const PROCESS_SHELF_DELETE_WHEN               = 'process_shelf_delete_when';
    const REVIEWS_PROCESS_SHELF_DELETE_WHEN       = self::REVIEWS . '.' . self::PROCESS_SHELF_DELETE_WHEN;
    const MORE_CONTEXT_LINES                      = 'more_context_lines';
    const REVIEWS_MORE_CONTEXT_LINES              = self::REVIEWS . '.' . self::MORE_CONTEXT_LINES;
    const MAX_BOTTOM_CONTEXT_LINES                = 'max_bottom_context_lines';
    const REVIEWS_MAX_BOTTOM_CONTEXT_LINES        = self::REVIEWS . '.' . self::MAX_BOTTOM_CONTEXT_LINES;
    const ALLOW_AUTHOR_CHANGE                     = 'allow_author_change';
    const REVIEWS_ALLOW_AUTHOR_CHANGE             = self::REVIEWS . '.' . self::ALLOW_AUTHOR_CHANGE;
    const ALLOW_AUTHOR_OBLITERATE                 = 'allow_author_obliterate';
    const REVIEWS_ALLOW_AUTHOR_OBLITERATE         = self::REVIEWS . '.' . self::ALLOW_AUTHOR_OBLITERATE;
    const DISABLE_SELF_APPROVE                    = 'disable_self_approve';
    const REVIEWS_DISABLE_SELF_APPROVE            = self::REVIEWS . '.' . self::DISABLE_SELF_APPROVE;
    const DISABLE_COMMIT                          = 'disable_commit';
    const REVIEWS_DISABLE_COMMIT                  = self::REVIEWS . '.' . self::DISABLE_COMMIT;
    const MODERATOR_APPROVAL                      = 'moderator_approval';
    const REVIEWS_MODERATOR_APPROVAL              = self::REVIEWS . '.' . self::MODERATOR_APPROVAL;
    const END_STATES                              = 'end_states';
    const REVIEWS_END_STATES                      = self::REVIEWS . '.' . self::END_STATES;
    const CLEANUP                                 = 'cleanup';
    const REVIEWS_CLEANUP                         = self::REVIEWS . '.' . self::CLEANUP;
    const REVIEWS_CLEANUP_MODE                    = self::REVIEWS . '.' . self::CLEANUP . '.' . self::MODE;
    const REVIEWS_CLEANUP_DEFAULT                 = self::REVIEWS . '.' . self::CLEANUP . '.' . self::DEFAULT;
    const REVIEWS_CLEANUP_REOPEN_FILES            = self::REVIEWS . '.' . self::CLEANUP . '.' . self::REOPEN_FILES;
    const REVIEWS_COMMIT_TIMEOUT                  = self::REVIEWS . '.' . self::COMMIT_TIMEOUT;
    const REVIEWS_COMMIT_CREDIT_AUTHOR            = self::REVIEWS . '.' . self::COMMIT_CREDIT_AUTHOR;
    const REVIEWS_REACT_ENABLED                   = self::REVIEWS . '.' . self::REACT_ENABLED;
    const REVIEWS_ALLOW_EDITS                     = self::REVIEWS . '.' . self::ALLOW_EDITS;
    const MAX_SECONDARY_NAV_ITEMS                 = 'max_secondary_navigation_items';
    const REVIEWS_MAX_SECONDARY_NAV_ITEMS         = self::REVIEWS . '.' . self::MAX_SECONDARY_NAV_ITEMS;
    const STATISTICS                              = 'statistics';
    const COMPLEXITY                              = 'complexity';
    const HIGH                                    = 'high';
    const LOW                                     = 'low';
    const CALCULATION                             = 'calculation';
    const REVIEWS_STATISTICS_COMPLEXITY           = self::REVIEWS . '.' .
                                                    self::STATISTICS . '.' .
                                                    self::COMPLEXITY;
    const REVIEWS_COMPLEXITY_CALCULATION          = self::REVIEWS . '.' .
                                                    self::STATISTICS . '.' .
                                                    self::COMPLEXITY . '.' .
                                                    self::CALCULATION;
    const REVIEWS_COMPLEXITY_HIGH                 = self::REVIEWS . '.' .
                                                    self::STATISTICS . '.' .
                                                    self::COMPLEXITY . '.' .
                                                    self::HIGH;
    const REVIEWS_COMPLEXITY_LOW                  = self::REVIEWS . '.' .
                                                    self::STATISTICS . '.' .
                                                    self::COMPLEXITY . '.' .
                                                    self::LOW;
    const FETCH_MAX                               = 'fetch-max';
    const FILTER_MAX                              = 'filter-max';
    const REVIEWS_FILTERS_FETCH_MAX               = self::REVIEWS . '.' .
                                                    self::FILTERS . '.' .
                                                    self::FETCH_MAX;
    const REVIEWS_FILTERS_FILTER_MAX              = self::REVIEWS . '.' .
                                                    self::FILTERS . '.' .
                                                    self::FILTER_MAX;
    const PREVENT_LOGIN                           = 'prevent_login';
    const SECURITY_PREVENT_LOGIN                  = self::SECURITY.'.'. self::PREVENT_LOGIN;
    const SECURITY_HTTPS_STRICT                   = 'security.https_strict';
    const MENTIONS                                = 'mentions';
    const USERS_BLACKLIST                         = 'usersBlacklist';
    const GROUPS_BLACKLIST                        = 'groupsBlacklist';
    const USERS_EXCLUDE_LIST                      = 'user_exclude_list';
    const GROUPS_EXCLUDE_LIST                     = 'group_exclude_list';
    const MODE                                    = 'mode';
    const MENTIONS_USERS_BLACKLIST                = self::MENTIONS . '.' . self::USERS_BLACKLIST;
    const MENTIONS_GROUPS_BLACKLIST               = self::MENTIONS . '.' . self::GROUPS_BLACKLIST;
    const MENTIONS_USERS_EXCLUDE_LIST             = self::MENTIONS . '.' . self::USERS_EXCLUDE_LIST;
    const MENTIONS_GROUPS_EXCLUDE_LIST            = self::MENTIONS . '.' . self::GROUPS_EXCLUDE_LIST;
    const MENTIONS_MODE                           = self::MENTIONS . '.' . self::MODE;
    const LOG                                     = 'log';
    const REFERENCE_ID                            = 'reference_id';
    const LOG_REFERENCE_ID                        = self::LOG . '.' . self::REFERENCE_ID;
    const EVENT_TRACE                             = 'event_trace';
    const LOG_EVENT_TRACE                         = self::LOG . '.' . self::EVENT_TRACE;
    const MARKDOWN                                = 'markdown';
    const FILE_EXTENSIONS                         = 'file_extensions';
    const MARKDOWN_FILE_EXTENSIONS                = self::MARKDOWN . '.' . self::FILE_EXTENSIONS;
    const MARKDOWN_MARKDOWN                       = self::MARKDOWN . '.' . self::MARKDOWN;
    const FILE                                    = 'file';
    const LOG_FILE                                = self::LOG . '.' . self::FILE;
    const PRIORITY                                = 'priority';
    const LOG_PRIORITY                            = self::LOG . '.' . self::PRIORITY;
    const DEPOT_STORAGE                           = 'depot_storage';
    const BASE_PATH                               = 'base_path';
    const DEPOT_STORAGE_BASE_PATH                 = self::DEPOT_STORAGE . '.' . self::BASE_PATH;
    const SECURITY                                = 'security';
    const ADD_PROJECT_ADMIN_ONLY                  = 'add_project_admin_only';
    const PROJECTS                                = 'projects';
    const ADD_ADMIN_ONLY                          = 'add_admin_only';
    const ADD_GROUPS_ONLY                         = 'add_groups_only';
    const ADD_PROJECT_GROUPS                      = 'add_project_groups';
    const SECURITY_ADD_PROJECT_ADMIN_ONLY         = self::SECURITY . '.' . self::ADD_PROJECT_ADMIN_ONLY;
    const SECURITY_ADD_PROJECT_GROUPS             = self::SECURITY . '.' . self::ADD_PROJECT_GROUPS;
    const PROJECTS_ADD_ADMIN_ONLY                 = self::PROJECTS . '.' . self::ADD_ADMIN_ONLY;
    const PROJECTS_ADD_GROUPS_ONLY                = self::PROJECTS . '.' . self::ADD_GROUPS_ONLY;
    const QUEUE                                   = 'queue';
    const PATH                                    = 'path';
    const WORKERS                                 = 'workers';
    const WORKER_LIFETIME                         = 'worker_lifetime';
    const WORKER_TASK_TIMEOUT                     = 'worker_task_timeout';
    const WORKER_MEMORY_LIMIT                     = 'worker_memory_limit';
    const DISABLE_TRIGGER_DIAGNOSTICS             = 'disable_trigger_diagnostics';
    const QUEUE_PATH                              = self::QUEUE . '.' . self::PATH;
    const QUEUE_WORKERS                           = self::QUEUE . '.' . self::WORKERS;
    const QUEUE_WORKER_LIFETIME                   = self::QUEUE . '.' . self::WORKER_LIFETIME;
    const QUEUE_WORKER_TASK_TIMEOUT               = self::QUEUE . '.' . self::WORKER_TASK_TIMEOUT;
    const QUEUE_WORKER_MEMORY_LIMIT               = self::QUEUE . '.' . self::WORKER_MEMORY_LIMIT;
    const QUEUE_DISABLE_TRIGGER_DIAGNOSTICS       = self::QUEUE . '.' . self::DISABLE_TRIGGER_DIAGNOSTICS;
    const WORKER_CHANGE_SAVE_DELAY                = 'worker_change_save_delay';
    const REVIEWS                                 = 'reviews';
    const SYNC_DESCRIPTIONS                       = 'sync_descriptions';
    const REVIEWS_SYNC_DESCRIPTIONS               = self::REVIEWS . '.' . self::SYNC_DESCRIPTIONS;
    const DEFAULT_UI                              = 'default_ui';
    const REVIEWS_DEFAULT_UI                      = self::REVIEWS . '.' . self::DEFAULT_UI;
    const EMULATE_IP_PROTECTIONS                  = 'emulate_ip_protections';
    const SECURITY_EMULATE_IP_PROTECTIONS         = self::SECURITY . '.' . self::EMULATE_IP_PROTECTIONS;
    const P4_EMULATE_IP_PROTECTIONS               = self::P4 . '.' . self::EMULATE_IP_PROTECTIONS;
    const REDIS                                   = 'redis';
    const OPTIONS                                 = 'options';
    const NAMESPACE                               = 'namespace';
    const REDIS_OPTIONS_NAMESPACE                 = self::REDIS . '.' . self::OPTIONS . '.' . self::NAMESPACE;
    const MAIL                                    = 'mail';
    const VALIDATOR                               = 'validator';
    const MAIL_VALIDATOR_OPTIONS                  = self::MAIL . '.' . self::VALIDATOR . '.' . self::OPTIONS;
    const POPULATION_LOCK_TIMEOUT                 = 'population_lock_timeout';
    const CHECK_INTEGRITY                         = 'check_integrity';
    const ITEMS_BATCH_SIZE                        = 'items_batch_size';
    const INVALID_KEY_CHARS                       = 'invalid_key_chars';
    const REDIS_POPULATION_LOCK_TIMEOUT           = self::REDIS . '.' . self::POPULATION_LOCK_TIMEOUT;
    const REDIS_CHECK_INTEGRITY                   = self::REDIS . '.' . self::CHECK_INTEGRITY;
    const REDIS_ITEMS_BATCH_SIZE                  = self::REDIS . '.' . self::ITEMS_BATCH_SIZE;
    const REDIS_INVALID_KEY_CHARS                 = self::REDIS . '.' . self::INVALID_KEY_CHARS;
    const ALLOW_VIEW_SETTINGS                     = 'allow_view_settings';
    const PROJECTS_ALLOW_VIEW_SETTINGS            = self::PROJECTS . '.' . self::ALLOW_VIEW_SETTINGS;
    const GROUPS                                  = 'groups';
    const SUPER_ONLY                              = 'super_only';
    const GROUPS_SUPER_ONLY                       = self::GROUPS . '.' . self::SUPER_ONLY;
    const JS_DEBUG                                = 'js_debug';
    const LOG_JS_DEBUG                            = self::LOG . '.' . self::JS_DEBUG;
    const ARCHIVES                                = 'archives';
    const ARCHIVE_TIMEOUT                         = 'archive_timeout';
    const CACHE_LIFETIME                          = 'cache_lifetime';
    const ARCHIVES_ARCHIVE_TIMEOUT                = self::ARCHIVES . '.' . self::ARCHIVE_TIMEOUT;
    const ARCHIVES_CACHE_LIFETIME                 = self::ARCHIVES . '.' . self::CACHE_LIFETIME;

    const TEST_DEFINITIONS                              = 'test_definitions';
    const PROJECT_AND_BRANCH_SEPARATOR                  = 'project_and_branch_separator';
    const TEST_DEFINITIONS_PROJECT_AND_BRANCH_SEPARATOR = self::TEST_DEFINITIONS .'.'
                                                            . self::PROJECT_AND_BRANCH_SEPARATOR;

    // User setting.
    const USER_SETTINGS = 'users.settings';
    // User preferences settings.
    const USER_SETTINGS_REVIEW_PREF_SHOW_COMMENTS     = "users.settings.review_preferences.show_comments_in_files";
    const USER_SETTINGS_REVIEW_PREF_SIDE_BY_SIDE      = "users.settings.review_preferences.view_diffs_side_by_side";
    const USER_SETTINGS_REVIEW_PREF_IGNORE_WHITESPACE = "users.settings.review_preferences.ignore_whitespace";
    const USER_SETTINGS_TIME_DISPLAY                  = 'users.settings.time.display';
    // Due to php5.3 not liking constants being put together in a new constant.
    const USER_SETTINGS_REVIEW_PREF_SHOW_SPACE = "users.settings.review_preferences.show_space_and_new_line_characters";

    const SETTINGS = 'settings';

    // Workflow
    // Convenient path prefixes
    const WORKFLOW_ON_SUBMIT_WITH_REVIEW    = 'workflow_rules.on_submit.with_review';
    const WORKFLOW_ON_SUBMIT_WITHOUT_REVIEW = 'workflow_rules.on_submit.without_review';
    const WORKFLOW_END_RULES_UPDATE         = 'workflow_rules.end_rules.update';
    const WORKFLOW_AUTO_APPROVE             = 'workflow_rules.auto_approve';
    // Full workflow paths
    const WORKFLOW_ENABLED                       = 'workflow.enabled';
    const WORKFLOW_ON_SUBMIT_WITH_REVIEW_RULE    = 'workflow_rules.on_submit.with_review.rule';
    const WORKFLOW_GROUP_EXCLUSIONS_RULE         = IWorkflow::WORKFLOW_RULES . '.'
                                                    . IWorkflow::GROUP_EXCLUSIONS . '.'
                                                    . IWorkflow::RULE;
    const WORKFLOW_USER_EXCLUSIONS_RULE          = IWorkflow::WORKFLOW_RULES . '.'
                                                    . IWorkflow::USER_EXCLUSIONS . '.'
                                                    . IWorkflow::RULE;
    const WORKFLOW_GROUP_EXCLUSIONS_MODE         = IWorkflow::WORKFLOW_RULES . '.'
                                                    . IWorkflow::GROUP_EXCLUSIONS . '.'
                                                    . IWorkflow::MODE;
    const WORKFLOW_USER_EXCLUSIONS_MODE          = IWorkflow::WORKFLOW_RULES . '.'
                                                    . IWorkflow::USER_EXCLUSIONS . '.'
                                                    . IWorkflow::MODE;
    const WORKFLOW_ON_SUBMIT_WITHOUT_REVIEW_RULE = 'workflow_rules.on_submit.without_review.rule';
    const WORKFLOW_ON_SUBMIT_WITH_REVIEW_MODE    = 'workflow_rules.on_submit.with_review.mode';
    const WORKFLOW_ON_SUBMIT_WITHOUT_REVIEW_MODE = 'workflow_rules.on_submit.without_review.mode';
    const WORKFLOW_END_RULES_UPDATE_RULE         = 'workflow_rules.end_rules.update.rule';
    const WORKFLOW_END_RULES_UPDATE_MODE         = 'workflow_rules.end_rules.update.mode';
    const WORKFLOW_AUTO_APPROVE_RULE             = 'workflow_rules.auto_approve.rule';
    const WORKFLOW_AUTO_APPROVE_MODE             = 'workflow_rules.auto_approve.mode';
    const WORKFLOW_COUNTED_VOTES_RULE            = 'workflow_rules.counted_votes.rule';
    const WORKFLOW_COUNTED_VOTES_MODE            = 'workflow_rules.counted_votes.mode';
    const LINKIFY                                = 'linkify';
    const WORD_LENGTH_LIMIT                      = 'word_length_limit';
    const LINKIFY_WORD_LENGTH_LIMIT              = self::LINKIFY . '.' . self::WORD_LENGTH_LIMIT;
    const LINKIFY_MARKDOWN_PATTERNS              = self::LINKIFY . '.' . self::MARKDOWN;
    const ID                                     = 'id';
    const REGEX                                  = 'regex';
    const URL                                    = 'url';
    const JOBS                                   = 'jobs';
    const SWARM                                  = 'swarm';

    const SAML_HEADER = 'saml.header';

    const HTTP_STRING             = 'httpString';
    const STRING                  = 'string';
    const INT                     = 'int';
    const INT_OR_HOURS_MINUTES_24 = 'int_or_hours_minutes_24';
    const BOOLEAN                 = 'boolean';
    const ARRAY                   = 'array';
    const ARRAY_OF_STRINGS        = 'arrayOfStrings';
    const SWARM_SETTING           = 'swarmSetting';
    const TYPE                    = 'type';
    const VALID_VALUES            = 'valid_values';
    const ALLOW_NULL              = 'allowNull';
    // For an array of strings we can define metadata that forces string conversion so that an array such
    // as array('1', 2) will be allowed
    const FORCE_STRING = 'force_string';

    // Environment modes;
    const DEVELOPMENT = 'development';
    const PRODUCTION  = 'production';

    // Case sensitivity levels
    const CASE_SENSITIVITY = 'case_sensitivity';
    const CASE_INSENSITIVE = 1;
    const CASE_SENSITIVE   = 0;

    // Some valid value constants
    const VALUE_ANY  = 'any';
    const VALUE_EACH = 'each';

    // Paths listed here are arrays that will have their values replaced rather than merged if specified in the file
    // glob config and also in defaults (glob config defaults override rather than merge)
    const ARRAY_REPLACE_PATHS = [self::REVIEWS_END_STATES];

    const GLOBAL_TESTS = 'global_tests';

    const SEARCH                    = 'search';
    const P4_SEARCH_HOST            = 'p4_search_host';
    const P4_SEARCH_API_PATH        = 'p4_search_api_path';
    const SEARCH_P4_SEARCH_HOST     = self::SEARCH . '.' . self::P4_SEARCH_HOST;
    const SEARCH_P4_SEARCH_API_PATH = self::SEARCH . '.' . self::P4_SEARCH_API_PATH;

    const TAG_PROCESSOR    = 'tag_processor';
    const TAGS             = 'tags';
    const WORK_IN_PROGRESS = 'wip';
    const REVIEW_WIP       = self::TAG_PROCESSOR . '.' . self::TAGS . '.' . self::WORK_IN_PROGRESS;

    // For the preview button
    const PREVIEW   = 'preview';
    const CLASSIC   = 'classic';
    const REVIEW_UI = 'review_ui';
}
