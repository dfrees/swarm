<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Config;

use Application\Config\IConfigDefinition as IDef;
use Markdown\Settings as MarkdownSettings;
use Reviews\Model\Review;
use Users\Settings\ReviewPreferences;
use Users\Settings\TimePreferences;
use Workflow\Model\IWorkflow;

trait ConfigMetadata
{
    // Permitted valid values for the SWARM_SETTING type
    public static $swarmSettings = [
        Setting::FORCED_DISABLED,
        Setting::FORCED_ENABLED,
        Setting::DISABLED,
        Setting::ENABLED
    ];

    // Metadata to define types etc (structure should agree with the configuration array)
    public static $configMetaData = [
        IDef::ARCHIVES => [
            IDef::ARCHIVE_TIMEOUT => [IDef::TYPE => IDef::INT],
            IDef::CACHE_LIFETIME => [IDef::TYPE => IDef::INT]
        ],
        'comments' => [
            'threading' => [
                'max_depth' => [IDef::TYPE => IDef::INT]
            ],
            'show_id'                 => [IDef::TYPE => IDef::BOOLEAN],
            'notification_delay_time' => [IDef::TYPE => IDef::INT]
        ],
        'diffs'   => [
            'max_diffs'             => [IDef::TYPE => IDef::INT],
            'context_lines'         => [IDef::TYPE => IDef::INT]
        ],
        IDef::FILES   => [
            'max_size'              => [IDef::TYPE => IDef::INT],
            'download_timeout'      => [IDef::TYPE => IDef::INT],
            IDef::ALLOW_EDITS       => [IDef::TYPE => IDef::BOOLEAN]
        ],
        IDef::REVIEWS => [
            IDef::DEFAULT_UI => [IDef::TYPE => IDef::STRING, IDef::VALID_VALUES => [IDef::CLASSIC, IDef::PREVIEW]],
            IDef::STATISTICS => [
                IDef::COMPLEXITY => [
                    IDef::CALCULATION => [
                        IDef::TYPE  => IDef::STRING, IDef::VALID_VALUES => [IDef::DEFAULT, IDef::DISABLED]
                    ],
                    IDef::HIGH => [IDef::TYPE => IDef::INT],
                    IDef::LOW  => [IDef::TYPE => IDef::INT]
                ]
            ],
            IDef::ALLOW_EDITS       => [IDef::TYPE => IDef::BOOLEAN],
            IDef::SYNC_DESCRIPTIONS => [IDef::TYPE => IDef::BOOLEAN],
            IDef::EXPAND_ALL_FILE_LIMIT => [IDef::TYPE => IDef::INT],
            IDef::FILTERS               => [
                IDef::RESULT_SORTING  => [IDef::TYPE => IDef::BOOLEAN],
                IDef::DATE_FIELD      =>
                    [IDef::TYPE => IDef::STRING, IDef::VALID_VALUES => ['created', 'updated']],
                IDef::FETCH_MAX => [IDef::TYPE => IDef::INT],
                IDef::FILTER_MAX => [IDef::TYPE => IDef::INT]
            ],
            IDef::EXPAND_GROUP_REVIEWERS          => [IDef::TYPE => IDef::BOOLEAN],
            IDef::DISABLE_APPROVE_WHEN_TASKS_OPEN => [IDef::TYPE => IDef::BOOLEAN],
            IDef::PROCESS_SHELF_DELETE_WHEN       => [
                IDef::TYPE                 => IDef::ARRAY_OF_STRINGS,
                IDef::VALID_VALUES         => [
                    Review::STATE_APPROVED,
                    Review::STATE_APPROVED_COMMIT,
                    Review::STATE_ARCHIVED,
                    Review::STATE_NEEDS_REVIEW,
                    Review::STATE_NEEDS_REVISION,
                    Review::STATE_REJECTED
                ],
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::MORE_CONTEXT_LINES       => [IDef::TYPE => IDef::INT],
            IDef::COMMIT_TIMEOUT           => [IDef::TYPE => IDef::INT],
            IDef::MAX_BOTTOM_CONTEXT_LINES => [IDef::TYPE => IDef::INT],
            IDef::ALLOW_AUTHOR_OBLITERATE  => [IDef::TYPE => IDef::BOOLEAN],
            IDef::COMMIT_CREDIT_AUTHOR     => [IDef::TYPE => IDef::BOOLEAN],
            IDef::ALLOW_AUTHOR_CHANGE      => [IDef::TYPE => IDef::BOOLEAN],
            IDef::DISABLE_SELF_APPROVE     => [IDef::TYPE => IDef::BOOLEAN],
            IDef::DISABLE_COMMIT           => [IDef::TYPE => IDef::BOOLEAN],
            IDef::MODERATOR_APPROVAL       =>
                [IDef::TYPE  => IDef::STRING, IDef::VALID_VALUES => [IDef::VALUE_ANY, IDef::VALUE_EACH]],
            IDef::CLEANUP       => [
                IDef::MODE => [
                    IDef::TYPE  => IDef::STRING,
                    IDef::VALID_VALUES => [IDef::USER, IDef::DEFAULT, IDef::AUTO],
                    IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
                ],
                IDef::DEFAULT      => [IDef::TYPE => IDef::BOOLEAN],
                IDef::REOPEN_FILES => [IDef::TYPE => IDef::BOOLEAN]
            ],
                IDef::END_STATES => [
                    IDef::TYPE                 => IDef::ARRAY_OF_STRINGS,
                    IDef::VALID_VALUES         => [
                        Review::STATE_APPROVED,
                        Review::STATE_APPROVED_COMMIT,
                        Review::STATE_ARCHIVED,
                        Review::STATE_REJECTED
                    ],
                    IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
                ],
                IDef::REACT_ENABLED => [IDef::TYPE => IDef::BOOLEAN],
                IDef::MAX_SECONDARY_NAV_ITEMS => [IDef::TYPE => IDef::INT],
        ],
        IDef::PROJECTS => [
            IDef::README_MODE =>  [
                IDef::TYPE         => IDef::STRING,
                IDef::VALID_VALUES => MarkdownSettings::LEGACY_MARKDOWN_OPTIONS
            ],
            IDef::MAINLINES       => [IDef::TYPE => IDef::ARRAY_OF_STRINGS],
            IDef::MAX_README_SIZE => [IDef::TYPE => IDef::INT, IDef::ALLOW_NULL => true],
            IDef::FETCH           =>[
                IDef::MAXIMUM => [IDef::TYPE => IDef::INT]
            ],
            IDef::ADD_ADMIN_ONLY      => [IDef::TYPE => IDef::BOOLEAN],
            IDef::ADD_GROUPS_ONLY     => [IDef::TYPE => IDef::BOOLEAN],
            IDef::ALLOW_VIEW_SETTINGS => [IDef::TYPE => IDef::BOOLEAN],
            IDef::RUN_TESTS_ON_UNCHANGED_SUBMIT => [IDef::TYPE => IDef::BOOLEAN],
        ],
        IDef::GROUPS => [
            IDef::SUPER_ONLY => [IDef::TYPE => IDef::BOOLEAN],
        ],
        'upgrade' => [
            'status_refresh_interval' => [IDef::TYPE => IDef::INT],
            'batch_size'              => [IDef::TYPE => IDef::INT]
        ],
        'avatars' => [
            'http_url'  => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            'https_url' => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ]
        ],
        IDef::SECURITY => [
            'require_login' => [IDef::TYPE => IDef::BOOLEAN],
            'prevent_login' => [IDef::TYPE => IDef::ARRAY_OF_STRINGS, IDef::FORCE_STRING => true],
            'https_strict'  => [IDef::TYPE => IDef::BOOLEAN],
            IDef::ADD_PROJECT_ADMIN_ONLY => [IDef::TYPE => IDef::BOOLEAN],
            IDef::ADD_PROJECT_GROUPS     => [IDef::TYPE => IDef::BOOLEAN],
            IDef::EMULATE_IP_PROTECTIONS => [IDef::TYPE => IDef::BOOLEAN],
        ],
        IDef::QUEUE => [
            IDef::WORKER_CHANGE_SAVE_DELAY => [IDef::TYPE => IDef::INT],
            IDef::PATH                 => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::WORKERS              => [IDef::TYPE => IDef::INT],
            IDef::WORKER_LIFETIME      => [IDef::TYPE => IDef::INT],
            IDef::WORKER_TASK_TIMEOUT  => [IDef::TYPE => IDef::INT],
            IDef::WORKER_MEMORY_LIMIT  => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::DISABLE_TRIGGER_DIAGNOSTICS => [IDef::TYPE => IDef::BOOLEAN],
        ],
        IDef::TRANSLATOR => [
            'non_utf8_encodings' => [IDef::TYPE => IDef::ARRAY_OF_STRINGS],
            IDef::UTF8_CONVERT => [IDef::TYPE => IDef::BOOLEAN],
        ],
        'users' => [
            'maximum_dashboard_actions' => [IDef::TYPE => IDef::INT],
            IDef::SETTINGS => [
                ReviewPreferences::REVIEW_PREFERENCES => [
                    ReviewPreferences::SHOW_COMMENTS_IN_FILES  => [IDef::TYPE => IDef::BOOLEAN],
                    ReviewPreferences::VIEW_DIFFS_SIDE_BY_SIDE => [IDef::TYPE => IDef::BOOLEAN],
                    ReviewPreferences::SHOW_SPACE_AND_NEW_LINE => [IDef::TYPE => IDef::BOOLEAN],
                    ReviewPreferences::IGNORE_WHITESPACE       => [IDef::TYPE => IDef::BOOLEAN]
                ],
                TimePreferences::TIME_PREFERENCES => [
                    TimePreferences::DISPLAY  => [
                        IDef::TYPE => IDef::STRING,
                        IDef::VALID_VALUES => [TimePreferences::TIMEAGO, TimePreferences::TIMESTAMP]
                    ]
                ]
            ],
            'display_fullname' => [IDef::TYPE => IDef::BOOLEAN],
        ],
        IDef::JIRA => [
            IDef::LINK_TO_JOBS        => [IDef::TYPE => IDef::BOOLEAN],
            IDef::DELAY_JOB_LINKS     => [IDef::TYPE => IDef::INT],
            IDef::MAX_JOB_FIXES       => [IDef::TYPE => IDef::INT],
            IDef::API_HOST            => [IDef::TYPE => IDef::HTTP_STRING],
            IDef::HOST                => [IDef::TYPE => IDef::HTTP_STRING],
            IDef::USER                => [IDef::TYPE => IDef::STRING],
            IDef::PASSWORD            => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
            IDef::JOB_FIELD           => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
            IDef::RELATIONSHIP        => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
        ],
        IWorkflow::WORKFLOW => [
            IWorkflow::WORKFLOW_ENABLED => [IDef::TYPE => IDef::BOOLEAN]
        ],
        IWorkflow::WORKFLOW_RULES => [
            IWorkflow::ON_SUBMIT => [
                IWorkflow::WITH_REVIEW => [
                    IWorkflow::RULE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::NO_CHECKING, IWorkflow::APPROVED, IWorkflow::STRICT]
                    ],
                    IWorkflow::MODE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                    ]
                ],
                IWorkflow::WITHOUT_REVIEW => [
                    IWorkflow::RULE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::NO_CHECKING, IWorkflow::AUTO_CREATE, IWorkflow::REJECT]
                    ],
                    IWorkflow::MODE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                    ]
                ]
            ],
            IWorkflow::END_RULES => [
                IWorkflow::UPDATE => [
                    IWorkflow::RULE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::NO_CHECKING, IWorkflow::NO_REVISION]
                    ],
                    IWorkflow::MODE => [
                        IDef::TYPE         => IDef::STRING,
                        IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                    ]
                ]
            ],
            IWorkflow::AUTO_APPROVE => [
                IWorkflow::RULE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::NEVER, IWorkflow::VOTES]
                ],
                IWorkflow::MODE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                ]
            ],
            IWorkflow::COUNTED_VOTES => [
                IWorkflow::RULE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::ANYONE, IWorkflow::MEMBERS]
                ],
                IWorkflow::MODE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                ]
            ],
            IWorkflow::GROUP_EXCLUSIONS => [
                IWorkflow::RULE => [
                    IDef::TYPE         => IDef::ARRAY_OF_STRINGS,
                    IDef::FORCE_STRING => true
                ],
                IWorkflow::MODE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                ]
            ],
            IWorkflow::USER_EXCLUSIONS => [
                IWorkflow::RULE => [
                    IDef::TYPE         => IDef::ARRAY_OF_STRINGS,
                    IDef::FORCE_STRING => true
                ],
                IWorkflow::MODE => [
                    IDef::TYPE         => IDef::STRING,
                    IDef::VALID_VALUES => [IWorkflow::MODE_DEFAULT, IWorkflow::MODE_POLICY]
                ]
            ]
        ],
        'saml' => ['header' => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE]],
        IDef::MENTIONS => [
            IDef::USERS_EXCLUDE_LIST => [
                IDef::TYPE => IDef::ARRAY_OF_STRINGS,
                IDef::FORCE_STRING => true,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::USERS_BLACKLIST => [
                IDef::TYPE => IDef::ARRAY_OF_STRINGS,
                IDef::FORCE_STRING => true,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::GROUPS_EXCLUDE_LIST => [
                IDef::TYPE => IDef::ARRAY_OF_STRINGS,
                IDef::FORCE_STRING => true,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::GROUPS_BLACKLIST => [
                IDef::TYPE => IDef::ARRAY_OF_STRINGS,
                IDef::FORCE_STRING => true,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ],
            IDef::MODE => [
                IDef::TYPE => IDef::STRING,
                IDef::VALID_VALUES => ['disabled', 'global', 'projects']
            ]
        ],
        IDef::P4 => [
            IDef::SSO => [
                IDef::TYPE => IDef::STRING,
                IDef::VALID_VALUES => [IDef::ENABLED, IDef::DISABLED, IDef::OPTIONAL],
            ],
            IDef::SSO_ENABLED => [IDef::TYPE => IDef::BOOLEAN],
            P4_SERVER_ID => [IDef::SSO_ENABLED => [IDef::TYPE => IDef::BOOLEAN]],
            IDef::MAX_CHANGELIST_FILES => [IDef::TYPE => IDef::INT],
            IDEF::PROXY_MODE => [IDef::TYPE => IDef::BOOLEAN]
        ],
        IDef::ENVIRONMENT => [
            IDef::LOGOUT_URL => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE,
                IDef::ALLOW_NULL => true
            ],
            IDef::VENDOR => [
                IDef::EMOJI_PATH => [
                    IDef::TYPE => IDef::STRING,
                    IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE,
                    IDef::ALLOW_NULL => true
                ],
            ],
            IDef::BASE_URL => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE,
                IDef::ALLOW_NULL => true
            ],
            IDef::ASSET_BASE_PATH => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE,
                IDef::ALLOW_NULL => true
            ],
            IDef::MODE => [IDef::TYPE => IDef::STRING, IDef::VALID_VALUES => [IDef::DEVELOPMENT, IDef::PRODUCTION]],
        ],
        IDef::LOG => [
            IDef::REFERENCE_ID => [IDef::TYPE => IDef::BOOLEAN],
            IDef::EVENT_TRACE  => [IDef::TYPE => IDef::BOOLEAN],
            IDef::FILE         => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
            IDef::PRIORITY     => [IDef::TYPE => IDef::INT, IDef::ALLOW_NULL => true],
            IDef::JS_DEBUG     => [IDef::TYPE => IDef::ARRAY_OF_STRINGS],
        ],
        IDef::MARKDOWN => [
            IDef::FILE_EXTENSIONS => [IDef::TYPE => IDef::ARRAY_OF_STRINGS, IDef::FORCE_STRING => true],
            IDef::MARKDOWN        => [
                IDef::TYPE => IDef::STRING, IDef::VALID_VALUES => MarkdownSettings::MARKDOWN_OPTIONS
            ]
        ],
        IDef::DEPOT_STORAGE => [
            IDef::BASE_PATH => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE]
        ],
        IDef::REDIS => [
            IDef::OPTIONS => [
                IDef::NAMESPACE => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE]
            ],
            IDef::POPULATION_LOCK_TIMEOUT => [IDef::TYPE => IDef::INT],
            IDef::ITEMS_BATCH_SIZE        => [IDef::TYPE => IDef::INT],
            IDef::CHECK_INTEGRITY         => [IDef::TYPE => IDef::INT_OR_HOURS_MINUTES_24],
            IDef::INVALID_KEY_CHARS       => [
                IDef::TYPE => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE,
                IDef::ALLOW_NULL => true
            ]
        ],
        IDef::MAIL => [
            IDef::VALIDATOR => [
                IDef::OPTIONS => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE]
            ]
        ],
        IDef::TEST_DEFINITIONS => [
            IDef::PROJECT_AND_BRANCH_SEPARATOR => [
                IDef::TYPE             => IDef::STRING,
                IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE
            ]
        ],
        IDef::LINKIFY => [
            IDef::WORD_LENGTH_LIMIT => [IDef::TYPE => IDef::INT],
            IDef::MARKDOWN => [IDEF::TYPE => IDef::ARRAY],
        ],
        IDef::SEARCH => [
            IDef::P4_SEARCH_HOST => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
            IDef::P4_SEARCH_API_PATH => [IDef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE],
        ],
        Idef::TAG_PROCESSOR => [
            IDef::TAGS => [
                IDef::WORK_IN_PROGRESS => [Idef::TYPE => IDef::STRING, IDef::CASE_SENSITIVITY => IDef::CASE_SENSITIVE]
            ]
        ]
    ];
}
