<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Events\Listener;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ListenerFactory implements FactoryInterface
{
    const TASK                     = 'task';
    const WORKER                   = 'worker';
    const EVENT_LISTENER_CONFIG    = 'event_listener_config';
    const PRIORITY                 = 'priority';
    const CALLBACK                 = 'callback';
    const MANAGER_CONTEXT          = 'context';
    const REVIEW                   = 'review';
    const COMMENT                  = 'comment';
    const TYPE                     = 'type';
    const ID                       = 'id';
    const DATA                     = 'data';
    const UPGRADE                  = 'upgrade';
    const WORKFLOW                 = 'workflow';
    const WORKER_SHUTDOWN          = self::WORKER . '.shutdown';
    const WORKER_LOOP              = self::WORKER . '.loop';
    const WORKER_STARTUP           = self::WORKER . '.startup';
    const TASK_CLEANUP_ATTACHMENTS = self::TASK . '.cleanup.attachment';
    const TASK_CLEANUP_ARCHIVE     = self::TASK . '.cleanup.archive';
    const TASK_COMMIT              = self::TASK . '.commit';
    const TASK_SHELVE              = self::TASK . '.shelve';
    const TASK_SHELVE_DELETE       = self::TASK . '.shelvedel';
    const TASK_CHANGE_SAVED        = self::TASK . '.changesaved';
    const TASK_CHANGE_SAVE         = self::TASK . '.changesave';
    const TASK_COMMENT             = self::TASK . '.' . self::COMMENT;
    const COMMENT_BATCH            = 'comment.batch';
    const TASK_COMMENT_BATCH       = self::TASK . '.comment.batch';
    const TASK_COMMENT_SEND_DELAY  = self::TASK . '.commentSendDelay';
    const TASK_GROUP               = self::TASK . '.group';
    const TASK_GROUP_DELETE        = self::TASK . '.groupdel';
    const TASK_JOB                 = self::TASK . '.job';
    const TASK_CHANGE              = self::TASK . '.change';
    const TASK_REVIEW              = self::TASK . '.' . self::REVIEW;
    const TASK_PING                = self::TASK . '.ping';
    const USER                     = 'user';
    const TASK_USER                = self::TASK . '.' . self::USER;
    const TASK_USER_DELETE         = self::TASK . '.userdel';
    const TASK_MAIL                = self::TASK . '.mail';
    const TEST_RUN                 = 'testrun';
    const TEST_RUN_ON_DEMAND       = self::TEST_RUN . '.onDemand.started';
    const TEST_RUN_UPGRADE_SCHEMA  = self::TEST_RUN . '.' . self::UPGRADE;
    const TEST_RUN_SCHEMA_VERSION  = 'testrun-schema-version'; // A pseudo task id qualifier for schema upgrades
    const TASK_TEST_RUN_UPGRADE    = self::TASK . '.' . self::TEST_RUN_UPGRADE_SCHEMA;
    const TASK_TEST_RUN            = self::TASK . '.' . self::TEST_RUN;
    const TASK_TEST_RUN_ON_DEMAND  = self::TASK . '.' . self::TEST_RUN_ON_DEMAND;
    const ALL                      = '*';
    const WORKFLOW_CREATED         = self::WORKFLOW . '.created';
    const WORKFLOW_UPDATED         = self::WORKFLOW . '.updated';
    const WORKFLOW_DELETED         = self::WORKFLOW . '.deleted';
    const WORKFLOW_UPGRADE_SCHEMA  = self::WORKFLOW . '.' . self::UPGRADE;
    const WORKFLOW_SCHEMA_VERSION  = 'workflow-schema-version'; // A pseudo task id qualifier for schema upgrades
    const TASK_WORKFLOW_CREATED    = self::TASK . '.' . self::WORKFLOW_CREATED;
    const TASK_WORKFLOW_UPDATED    = self::TASK . '.' . self::WORKFLOW_UPDATED;
    const TASK_WORKFLOW_DELETED    = self::TASK . '.' . self::WORKFLOW_DELETED;
    const TASK_WORKFLOWS_UPGRADE   = self::TASK . '.' . self::WORKFLOW_UPGRADE_SCHEMA;
    const PROJECT_CREATED          = 'project.created';
    const PROJECT_UPDATED          = 'project.updated';
    const TASK_PROJECT_CREATED     = self::TASK . '.' . self::PROJECT_CREATED;
    const TASK_PROJECT_UPDATED     = self::TASK . '.' . self::PROJECT_UPDATED;
    const CACHE                    = 'cache';
    const INTEGRITY                = 'integrity';
    const CACHE_INTEGRITY          = self::CACHE . '.' . self::INTEGRITY;
    const TASK_CACHE_INTEGRITY     = self::TASK . '.' .self::CACHE_INTEGRITY;
    // Test definition tasks
    const TEST_DEFINITION                = 'testdefinition';
    const TEST_DEFINITION_MIGRATION      = self::TEST_DEFINITION . '.migration';
    const TASK_TEST_DEFINITION_MIGRATION = self::TASK . '.' . self::TEST_DEFINITION_MIGRATION;
    const TEST_DEFINITION_MIGRATED       = self::TEST_DEFINITION . '.migrated';
    const TEST_DEFINITION_MIGRATED_ERROR = self::TEST_DEFINITION . '.migrated.error';
    const TEST_DEFINITION_UPDATED        = self::TEST_DEFINITION . '.updated';
    const TEST_DEFINITION_CREATED        = self::TEST_DEFINITION . '.created';
    const TEST_DEFINITION_DELETED        = self::TEST_DEFINITION . '.deleted';
    const TASK_TEST_DEFINITION_UPDATED   = self::TASK . '.' .self::TEST_DEFINITION_UPDATED;
    const TASK_TEST_DEFINITION_CREATED   = self::TASK . '.' .self::TEST_DEFINITION_CREATED;
    const TASK_TEST_DEFINITION_DELETED   = self::TASK . '.' .self::TEST_DEFINITION_DELETED;


    // Priority for events
    const DEFAULT_PRIORITY     = 1;
    const HANDLE_ACTIVITY      = -200;
    const HANDLE_MAIL_PRIORITY = -300;


    /**
     * @inheritDoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $globalConfiguration = $container->get('config');
        $configuration       = [];

        if (array_key_exists(self::EVENT_LISTENER_CONFIG, $globalConfiguration)) {
            $configuration = $globalConfiguration[self::EVENT_LISTENER_CONFIG];
        }

        return new $requestedName($container, $this->recursiveFind($configuration, $requestedName));
    }

    public function recursiveFind(array $haystack, $needle)
    {
        $events    = [];
        $iterator  = new \RecursiveArrayIterator($haystack);
        $recursive = new \RecursiveIteratorIterator(
            $iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($recursive as $key => $value) {
            if ($key === $needle) {
                foreach ($value as $callback) {
                    $events[$recursive->getSubIterator($recursive->getDepth() - 1)->key()][] = $callback;
                }
            }
        }
        return $events;
    }
}
