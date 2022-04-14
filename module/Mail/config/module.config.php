<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

use Mail\Transport\Factory;

return [
    'mail' => [
        'sender'               => null,
        'instance_name'        => 'swarm',
        'recipients'           => null,
        'subject_prefix'       => '[Swarm]',
        'use_bcc'              => false,
        'use_replyto'          => true,
        'transport'            => [],
        'notify_self'          => false,
        'index-conversations'  => true,
        'validator'            => ['options' => []]
    ],
    'security' => [
        'email_restricted_changes' => false
    ],
    'service_manager' => [
        'factories' => [
            Factory::SERVICE => Factory::class
        ],
    ]
];
