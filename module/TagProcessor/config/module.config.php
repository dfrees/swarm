<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
use Application\Config\IConfigDefinition;
use Application\Factory\InvokableServiceFactory;
use Events\Listener\ListenerFactory;
use TagProcessor\Filter\ITagFilter;
use TagProcessor\Filter\WipKeyword;
use TagProcessor\Listener\WipListener;
use TagProcessor\Service\IWip;
use TagProcessor\Service\Wip;

$listeners = [WipListener::class];

return [
    IConfigDefinition::TAG_PROCESSOR => [
        IConfigDefinition::TAGS => [
            IConfigDefinition::WORK_IN_PROGRESS => '/(^|\s)+#wip($|\s)+/i'
        ],
    ],
    'listeners' => $listeners,
    'service_manager' =>[
        'aliases' => [
            ITagFilter::WIP_KEYWORD  => WipKeyword::class,
            IWip::WIP_SERVICE        => Wip::class
        ],
        'factories' => array_merge(
            array_fill_keys(
                $listeners,
                ListenerFactory::class
            ),
            array_fill_keys(
                [
                    WipKeyword::class,
                    Wip::class
                ],
                InvokableServiceFactory::class
            )
        )
    ],
    ListenerFactory::EVENT_LISTENER_CONFIG => [
        // To catch and changes to the changelist being saved.
        ListenerFactory::TASK_CHANGE_SAVE => [
            WipListener::class => [
                [
                    ListenerFactory::PRIORITY => 900,
                    ListenerFactory::CALLBACK => 'checkWip',
                    ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        // Catch sync description event here.
        ListenerFactory::TASK_CHANGE_SAVED => [
            WipListener::class => [
                [
                    ListenerFactory::PRIORITY => 900,
                    ListenerFactory::CALLBACK => 'checkWip',
                    ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        // To catch when user shelves changelist but don't want to update due to containing '#wip'
        ListenerFactory::TASK_SHELVE => [
            WipListener::class => [
                [
                    ListenerFactory::PRIORITY => 900,
                    ListenerFactory::CALLBACK => 'checkWip',
                    ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        // To catch when user commit changelist but don't want to update due to containing '#wip'
        ListenerFactory::TASK_COMMIT => [
            WipListener::class => [
                [
                    ListenerFactory::PRIORITY => 900,
                    ListenerFactory::CALLBACK => 'checkWip',
                    ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        // To catch when user does a shelve delete with description containing '#wip'
        ListenerFactory::TASK_SHELVE_DELETE => [
            WipListener::class => [
                [
                    ListenerFactory::PRIORITY => 900,
                    ListenerFactory::CALLBACK => 'checkWip',
                    ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
    ],
];
