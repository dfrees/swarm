<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Jobs;

use Mail\Module as Mail;
use Notifications\Settings;

class Module
{
    /**
     * To incorporate the p4review2 feature that notify people if a job is created or
     * edited it should send them an email.
     *
     * @param $job     The job that triggered the event.
     * @param $user    The user that did the creation or edit of job.
     * @param $event   The full event module.
     * @param $services The services object.
     */
    public static function checkJobNotification($job, $user, $event, $services)
    {
        $config  = $services->get('config');
        $p4Admin = $services->get('p4_admin');
        // Get the notification settings.
        $notifications = isset($config[Settings::NOTIFICATIONS])
            ? $config[Settings::NOTIFICATIONS]
            : [];
        // Check if the opt_in_job_path has been set otherwise set it to null
        $notifications += [
            Settings::OPT_IN_JOB_PATH    => null,
        ];

        // Now check that review path to see who wants emails for the job changes or creation.
        $reviewPath = $notifications[Settings::OPT_IN_JOB_PATH];
        if ($reviewPath && is_string($reviewPath)) {
            // Find who wants an email about jobs.
            $users   = $p4Admin->run('reviews', [$reviewPath])->getData();
            $toUsers = [];
            foreach ($users as $userDetails) {
                $toUsers[] = $userDetails['user'];
            }
            // ensure we don't have any duplicate users.
            array_unique($toUsers);
            // Debug out who we are attempting to send the job email to.
            $logger = $services->get('logger');
            $logger->debug("Job/Mail: We are preparing a job email for " . var_export($toUsers, true));
            // configure a message for mail module to deliver
            $mailParams = [
                'subject'       => 'Job '. $job->getId() . ' for review',
                'author'        => $user,
                'toUsers'       => $toUsers,
                'fromUser'      => $user,
                'job'           => $job,
                'messageId'     =>
                    '<topic-job/' . $job->getId()
                    . '@' . Mail::getInstanceName($config['mail']) . '>',
                'htmlTemplate'  => __DIR__ . '/view/mail/job-html.phtml',
                'textTemplate'  => __DIR__ . '/view/mail/job-text.phtml',
            ];
            // Set the mail param on the event
            $event->setParam('mail', $mailParams);
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
