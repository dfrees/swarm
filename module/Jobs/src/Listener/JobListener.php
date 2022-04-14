<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Jobs\Listener;

use Activity\Model\Activity;
use Application\Filter\Linkify;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use P4\Spec\Job;
use Laminas\EventManager\Event;
use Jobs\Module;

class JobListener extends AbstractEventListener
{
    // fetch job object for job events
    public function handleJob(Event $event)
    {
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $id       = $event->getParam('id');

        try {
            $job = Job::fetchById($id, $p4Admin);
            $event->setParam('job', $job);

            // determine event author
            // by default there is no modified-by field, but if we
            // can find one in the jobspec, we will use it here.
            $user = $job->hasModifiedByField()
                ? $job->get($job->getModifiedByField())
                : $job->getUser();

            // determine action the user took
            $action = 'modified';
            if ($job->hasCreatedDateField() && $job->hasModifiedDateField()) {
                $created  = $job->get($job->getCreatedDateField());
                $modified = $job->get($job->getModifiedDateField());
                $action   = $created === $modified ? 'created' : 'modified';
            }
            $projectDAO = $services->get(IModelDAO::PROJECT_DAO);

            // prepare data model for activity streams
            $activity = new Activity();
            $activity->set(
                [
                    'type'          => 'job',
                    'link'          => ['job', ['job' => $job->getId()]],
                    'user'          => $user,
                    'action'        => $action,
                    'target'        => $job->getId(),
                    'description'   => $job->getDescription(),
                    'topic'         => 'jobs/' . $job->getId(),
                    'time'          => $event->getParam('time'),
                    'projects'      => $projectDAO->getAffectedByJob($job, $p4Admin)
                ]
            );
            // ensure any @mention'ed users are included
            $mentions = $services->get(IModelDAO::USER_DAO)
                ->filter(Linkify::getCallouts($job->getDescription()), $p4Admin);
            $activity->addFollowers($mentions);

            $event->setParam('activity', $activity);

            // Now check if we need to notify anyone for job creation or edits.
            Module::checkJobNotification($job, $user, $event, $services);
        } catch (\Exception $e) {
            $services->get('logger')->err($e);
        }
    }
}
