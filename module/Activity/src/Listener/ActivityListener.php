<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Listener;

use Activity\Model\Activity;
use Application\Config\IConfigDefinition;
use Application\Connection\ConnectionFactory;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Groups\Model\Group;
use P4\Key\Key;
use P4\Spec\Job;
use Projects\Model\Project as ProjectModel;
use Reviews\Model\Review;
use Users\Model\Config;
use Laminas\EventManager\Event;
use P4\Spec\Definition as SpecDefinition;

class ActivityListener extends AbstractEventListener
{
    /**
     * Connect to all tasks and write activity data we do this late (low-priority) so all handlers have
     * a chance to influence the activity model.
     * @param Event $event
     * @throws \Exception
     */
    public function createActivity(Event $event)
    {
        $model = $event->getParam('activity');
        if (!$model instanceof Activity) {
            return;
        }

        // ignore 'quiet' events.
        $data  = (array) $event->getParam('data') + ['quiet' => null];
        $quiet = $event->getParam('quiet', $data['quiet']);
        if ($quiet === true || in_array('activity', (array) $quiet)) {
            return;
        }

        // don't record activity by users we ignore.
        $config = $this->services->get(IConfigDefinition::CONFIG);
        $ignore = isset($config['activity']['ignored_users'])
            ? (array) $config['activity']['ignored_users']
            : [];
        $userID = $model->get('user');
        if (in_array($userID, $ignore)) {
            return;
        }
        $class = get_class($this);
        $this->logger->trace(sprintf("%s: Message for the log", $class));

        $streams = (array) $model->getStreams();

        // all activity should appear in the activity streams
        // of the user that initiated the activity.
        $streams[] = 'user-'     . $userID;
        $streams[] = 'personal-' . $userID;

        // add anyone who follows the user that initiated this activity
        $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
        $followers = Config::fetchFollowerIds($userID, 'user', $p4Admin);

        // projects that are affected should also get the activity
        // and, by extension, project members should see it too.
        if ($model->getProjects()) {
            $projectDAO = $this->services->get(IModelDAO::PROJECT_DAO);
            $projects   = $projectDAO->fetchAll(
                [ProjectModel::FETCH_BY_IDS => array_keys($model->getProjects())],
                $p4Admin
            );
            foreach ($projects as $project) {
                $projectID = $project->getId();
                $streams[] = 'project-' . $projectID;
                $followers =  array_merge($followers, (array) $project->getAllMembers(false));
                $this->logger->trace(
                    sprintf(
                        "%s: Added all members for project with id [%s] as followers",
                        $class,
                        $projectID
                    )
                );
            }
        }

        // ensure groups the user is member of get the activity
        if ($userID) {
            $groups = $this->services->get(IModelDAO::GROUP_DAO)->fetchAll(
                [
                    Group::FETCH_BY_USER      => $userID,
                    Group::FETCH_INDIRECT     => true
                ],
                $p4Admin
            );
            $this->logger->trace(
                sprintf(
                    "%s: Adding the activity for user %s's groups",
                    $class,
                    $userID
                )
            );
            $streams = array_merge(
                $streams,
                (array) preg_filter('/^/', 'group-', $groups->invoke('getId'))
            );
        }

        // activity related to a review should include review participants
        // and should appear in the activity stream for the review itself
        $review = $event->getParam('review');
        if ($review instanceof Review) {
            $followers = array_merge($followers, (array) $review->getParticipants());
            $streams[] = 'review-' . $review->getId();
        }
        // Now add the followers to the model.
        $model->addFollowers($followers);
        $this->logger->trace(sprintf("%s: Adding Personal Followers", $class));
        $streams = array_merge(
            $streams,
            (array) preg_filter('/^/', 'personal-', $model->getFollowers())
        );
        $this->logger->trace(sprintf("%s: Setting the streams", $class));
        $model->setStreams($streams);

        try {
            $model->setConnection($p4Admin)->save();
        } catch (\Exception $e) {
            $this->logger->err($e);
        }
    }

    /**
     * Connect to worker startup to check if we need to prime activity
     * data (i.e. this is a first run against an existing server).
     * @param Event $event
     * @throws \P4\Counter\Exception\NotFoundException
     */
    public function prePopulateActivity(Event $event)
    {
        $manager = $this->services->get('queue');
        $events  = $manager->getEventManager();
        if ($event->getParam('slot') !== 1) {
            return;
        }

        // if we already have an event counter, nothing to do.
        $p4Admin = $this->services->get('p4_admin');
        if (Key::exists(Activity::KEY_COUNT, $p4Admin)) {
            return;
        }

        // initialize count to zero so we exit early next time.
        $key = new Key($p4Admin);
        $key->setId(Activity::KEY_COUNT)
            ->set(0);

        // looks like we're going to do the initial import, tie up as many
        // worker slots as we can to minimize concurrency/out-of-order issues
        // (if other workers were already running, we won't get all the slots)
        // release these slots on shutdown - only really needed when testing
        $slots = [];
        while ($slot = $manager->getWorkerSlot()) {
            $slots[] = $slot;
        }
        $events->attach(
            'worker.shutdown',
            function () use ($slots, $manager) {
                foreach ($slots as $slot) {
                    $manager->releaseWorkerSlot($slot);
                }
            }
        );

        // grab the last 10k changes and get ready to queue them.
        $queue   = [];
        $changes = $p4Admin->run('changes', ['-m10000', '-s', 'submitted']);
        foreach ($changes->getData() as $change) {
            $queue[] = [
                'type' => 'commit',
                'id'   => $change['change'],
                'time' => (int) $change['time']
            ];
        }

        // grab the last 10k jobs and get ready to queue them.
        // note, jobspec is mutable so we get the date via its code
        try {
            // use modified date field if available, falling-back to the default date field.
            // often this will be the same field, by default the date field is a modified date.
            $job  = new Job($p4Admin);
            $spec = SpecDefinition::fetch('job', $p4Admin);
            $date = $job->hasModifiedDateField()
                ? $job->getModifiedDateField()
                : $spec->fieldCodeToName(104);

            $jobs = $p4Admin->run('jobs', ['-m10000', '-r']);
            foreach ($jobs->getData() as $job) {
                if (isset($job[$date])) {
                    $queue[] = [
                        'type' => 'job',
                        'id'   => $job['Job'],
                        'time' => strtotime($job[$date])
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->services->get('logger')->err($e);
        }

        // sort items by time so they are processed in order
        // if other workers are already pulling tasks from the queue.
        usort(
            $queue,
            function ($a, $b) {
                return $a['time'] - $b['time'];
            }
        );

        // we don't want to duplicate activity
        // it's possible there are already tasks in the queue
        // (imagine the trigger was running, but the workers were not),
        // if there are >10k abort; else fetch them so we can skip them.
        if ($manager->getTaskCount() > 10000) {
            return;
        }
        $skip = [];
        foreach ($manager->getTaskFiles() as $file) {
            $task = $manager->parseTaskFile($file);
            if ($task) {
                $skip[$task['type'] . ',' . $task['id']] = true;
            }
        }

        // again, we don't want to duplicate activity
        // if there is any activity at this point, abort.
        if (Key::fetch(Activity::KEY_COUNT, $p4Admin)->get()) {
            return;
        }

        // add jobs and changes to the queue
        foreach ($queue as $task) {
            if (!isset($skip[$task['type'] . ',' . $task['id']])) {
                $manager->addTask($task['type'], $task['id'], null, $task['time']);
            }
        }
    }
}
