<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
use Events\Listener\ListenerFactory as EventListenerFactory;
use Queue\Manager as QueueManager;
use TestIntegration\Listener\TestRun;
use TestIntegration\Listener\TestDefinition;
use Laminas\Mvc\MvcEvent;
use Laminas\Mvc\ResponseSender\SendResponseEvent;
use Activity\Listener\ActivityListener;
use Workflow\Listener\WorkflowListener;
use Reviews\Listener\Statistics;

$listeners = [
    ActivityListener::class,
    Application\Listener\EventErrorListener::class,
    Application\Listener\RouteListener::class,
    Application\Listener\WorkerListener::class,
    Application\View\Http\StrictJsonStrategy::class,
    Application\View\Http\ExceptionStrategy::class,
    Application\Response\CallbackResponseSender::class,
    Application\Permissions\Csrf\Listener::class,
    Attachments\Listener\AttachmentsListener::class,
    Changes\Listener\CommitShelveListener::class,
    Changes\Listener\ChangeListener::class,
    Comments\Listener\CommentListener::class,
    Files\Listener\FileListener::class,
    Groups\Listener\GroupsListener::class,
    Jira\Listener\JiraListener::class,
    Jobs\Listener\JobListener::class,
    Mail\Listener\MailListener::class,
    Queue\Listener\Ping::class,
    Projects\Listener\ProjectListener::class,
    Reviews\Listener\ShelveCommit::class,
    Reviews\Listener\Review::class,
    Reviews\Listener\ReviewTestRuns::class,
    Users\Listener\UserEventListener::class,
    Users\Authentication\BasicAuthListener::class,
    Xhprof\Listener\XhprofListener::class,
    Application\Log\EventListener::class,
    WorkflowListener::class,
    Redis\Listener\RedisListener::class,
    TestRun::class,
    TestDefinition::class,
    Statistics::class
];

return [
    'listeners'       => $listeners,
    'service_manager' =>[
        'factories' => array_fill_keys(
            $listeners,
            EventListenerFactory::class
        )
    ],
    EventListenerFactory::EVENT_LISTENER_CONFIG => [
        EventListenerFactory::ALL => [
            Application\Log\EventListener::class => [
                [
                    // Ensure this is the first to fire with a high priority
                    EventListenerFactory::PRIORITY => 1000,
                    EventListenerFactory::CALLBACK => 'handleEventTriggered',
                ],
                [
                    // Ensure this is the last to fire with a low priority
                    EventListenerFactory::PRIORITY => -1000,
                    EventListenerFactory::CALLBACK => 'handleEventFinished',
                ]
            ]
        ],
        MvcEvent::EVENT_DISPATCH => [
            Application\Permissions\Csrf\Listener::class => [
                [
                    EventListenerFactory::PRIORITY => 100,
                    EventListenerFactory::CALLBACK => 'registerControllerListener'
                ]
            ],
            Users\Authentication\BasicAuthListener::class => [
                [
                    EventListenerFactory::PRIORITY => 100,
                    EventListenerFactory::CALLBACK => 'registerControllerListener'
                ]
            ]
        ],
        MvcEvent::EVENT_ROUTE => [
            Application\Listener\RouteListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'handleMultiP4d'
                ],
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'handleApiUrl'
                ],
                [
                    EventListenerFactory::PRIORITY => -1000,
                    EventListenerFactory::CALLBACK => 'handleRequireLogin'
                ]
            ],
            Xhprof\Listener\XhprofListener::class => [
                [
                    EventListenerFactory::PRIORITY => -1010,
                    EventListenerFactory::CALLBACK => 'handleRouteEvent'
                ],
            ]
        ],
        MvcEvent::EVENT_RENDER => [
            Application\View\Http\StrictJsonStrategy::class => [
                [
                    EventListenerFactory::PRIORITY => -200,
                    EventListenerFactory::CALLBACK => 'injectStrictJsonResponse'
                ]
            ]
        ],
        MvcEvent::EVENT_DISPATCH_ERROR => [
            Application\Listener\EventErrorListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'onError'
                ]
            ],
            Application\View\Http\ExceptionStrategy::class => [
                [
                    EventListenerFactory::PRIORITY => 100,
                    EventListenerFactory::CALLBACK => 'prepareExceptionViewModel'
                ]
            ]
        ],
        MvcEvent::EVENT_RENDER_ERROR => [
            Application\Listener\EventErrorListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'onError'
                ]
            ],
            Application\View\Http\StrictJsonStrategy::class => [
                [
                    EventListenerFactory::PRIORITY => -200,
                    EventListenerFactory::CALLBACK => 'injectStrictJsonResponse'
                ]
            ]
        ],
        SendResponseEvent::EVENT_SEND_RESPONSE => [
            Application\Response\CallbackResponseSender::class => [
                [
                    EventListenerFactory::PRIORITY        => -3500,
                    EventListenerFactory::CALLBACK        => '__invoke',
                    EventListenerFactory::MANAGER_CONTEXT => 'SendResponseListener'
                ]
            ]
        ],
        EventListenerFactory::WORKER_STARTUP => [
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'prePopulateActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Application\Listener\WorkerListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'setHostUrl',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Jira\Listener\JiraListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'refreshProjectList',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Redis\Listener\RedisListener::class => [
                [
                    EventListenerFactory::PRIORITY        => 500, // We would want this to trigger early before other
                                                                  // events which is the reason for the 500.
                    EventListenerFactory::CALLBACK        => 'shouldVerifyCacheIntegrity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::WORKER_SHUTDOWN => [
            Application\Listener\WorkerListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'removeInvalidatedFiles',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Xhprof\Listener\XhprofListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleWorkerShutdown',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
            ]
        ],
        EventListenerFactory::WORKER_LOOP => [
            Queue\Listener\Ping::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'sendPing',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_PING => [
            Queue\Listener\Ping::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'receivePing',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_CLEANUP_ATTACHMENTS => [
            Attachments\Listener\AttachmentsListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'cleanUp',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_CLEANUP_ARCHIVE => [
            Files\Listener\FileListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'cleanUpArchive',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMIT => [
            Changes\Listener\CommitShelveListener::class => [
                [
                    EventListenerFactory::PRIORITY        => 300,
                    EventListenerFactory::CALLBACK        => 'onCommitShelve',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
                [
                    EventListenerFactory::PRIORITY        => 200,
                    EventListenerFactory::CALLBACK        => 'activityAndMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
                [
                    EventListenerFactory::PRIORITY        => -100,
                    EventListenerFactory::CALLBACK        => 'postActivityAndMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
                [
                    EventListenerFactory::PRIORITY        => -200,
                    EventListenerFactory::CALLBACK        => 'configureMailReviewers',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
            ],
            Reviews\Listener\ShelveCommit::class => [
                [
                    EventListenerFactory::PRIORITY        => 100,
                    EventListenerFactory::CALLBACK        => 'lockThenProcess',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Jira\Listener\JiraListener::class => [
                [
                    EventListenerFactory::PRIORITY        => -400,
                    EventListenerFactory::CALLBACK        => 'checkChange',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_SHELVE => [
            Changes\Listener\CommitShelveListener::class => [
                [
                    EventListenerFactory::PRIORITY        => 300,
                    EventListenerFactory::CALLBACK        => 'onCommitShelve',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Reviews\Listener\ShelveCommit::class => [
                [
                    EventListenerFactory::PRIORITY        => 200,
                    EventListenerFactory::CALLBACK        => 'processGitShelve',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],
                [
                    EventListenerFactory::PRIORITY        => 100,
                    EventListenerFactory::CALLBACK        => 'lockThenProcess',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ],

            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_SHELVE_DELETE => [
            Reviews\Listener\ShelveCommit::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'shelveDelete',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_CHANGE_SAVE => [
            Changes\Listener\ChangeListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleChangeSave',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_CHANGE_SAVED => [
            Changes\Listener\ChangeListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'descriptionSync',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMENT => [
            Comments\Listener\CommentListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'commentCreated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_COMMENT_BATCH => [
            Comments\Listener\CommentListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'commentBatch',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_COMMENT_SEND_DELAY => [
            Comments\Listener\CommentListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'commentSendDelay',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_GROUP  => [
            Groups\Listener\GroupsListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'onGroup',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_GROUP_DELETE => [
            Groups\Listener\GroupsListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'onGroup',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_JOB => [
            Jobs\Listener\JobListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleJob',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Jira\Listener\JiraListener::class => [
                [
                    EventListenerFactory::PRIORITY        => -300,
                    EventListenerFactory::CALLBACK        => 'handleJob',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_CHANGE => [
            Jira\Listener\JiraListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'checkChange',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_REVIEW => [
            Statistics::class => [
                [
                    EventListenerFactory::PRIORITY        => 200,
                    EventListenerFactory::CALLBACK        => 'reviewChanged',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Reviews\Listener\Review::class => [
                [
                    EventListenerFactory::PRIORITY        => 100,
                    EventListenerFactory::CALLBACK        => 'lockThenProcess',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Reviews\Listener\ReviewTestRuns::class => [
                [
                    EventListenerFactory::PRIORITY        => 90,
                    EventListenerFactory::CALLBACK        => 'processTests',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            Jira\Listener\JiraListener::class => [
                [
                    EventListenerFactory::PRIORITY        => -300,
                    EventListenerFactory::CALLBACK        => 'checkReview',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_USER => [
            Users\Listener\UserEventListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'onUser',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_USER_DELETE => [
            Users\Listener\UserEventListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'onUser',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_MAIL => [
            Mail\Listener\MailListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_MAIL_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'handleMail',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_WORKFLOW_CREATED => [
            WorkflowListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'workflowCreated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_WORKFLOW_UPDATED => [
            WorkflowListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'workflowUpdated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_WORKFLOW_DELETED => [
            WorkflowListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'workflowDeleted',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_WORKFLOWS_UPGRADE => [
            WorkflowListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'upgradeWorkflows',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_PROJECT_CREATED => [
            Projects\Listener\ProjectListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'projectCreated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_PROJECT_UPDATED => [
            Projects\Listener\ProjectListener::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'projectUpdated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_CACHE_INTEGRITY => [
            Redis\Listener\RedisListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK        => 'cacheIntegrity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_RUN => [
            TestRun::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'update',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ]
        ],
        EventListenerFactory::TASK_TEST_RUN_ON_DEMAND => [
            TestRun::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'onDemandTestStarted',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_RUN_UPGRADE => [
            TestRun::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'upgradeTestRuns',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_DEFINITION_MIGRATION => [
            TestDefinition::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'definitionsMigrated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_DEFINITION_CREATED => [
            TestDefinition::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'testDefinitionCreated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_DEFINITION_UPDATED => [
            TestDefinition::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'testDefinitionUpdated',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ],
        EventListenerFactory::TASK_TEST_DEFINITION_DELETED => [
            TestDefinition::class => [
                [
                    EventListenerFactory::PRIORITY => EventListenerFactory::DEFAULT_PRIORITY,
                    EventListenerFactory::CALLBACK => 'testDefinitionDeleted',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
            ActivityListener::class => [
                [
                    EventListenerFactory::PRIORITY        => EventListenerFactory::HANDLE_ACTIVITY,
                    EventListenerFactory::CALLBACK        => 'createActivity',
                    EventListenerFactory::MANAGER_CONTEXT => QueueManager::SERVICE
                ]
            ],
        ]
    ],
];
