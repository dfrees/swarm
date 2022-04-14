<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Activity\Controller;

use Activity\Model\Activity;
use Application\Controller\AbstractIndexController;
use Application\Filter\Preformat;
use Application\Model\IModelDAO;
use Application\Permissions\Protections;
use Application\Permissions\RestrictedChanges;
use Comments\Model\Comment;
use Laminas\View\Model\ViewModel;
use Projects\Model\Project as ProjectModel;
use Laminas\Feed\Writer\Feed;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\FeedModel;

class IndexController extends AbstractIndexController
{
    public function activityDataAction()
    {
        // collect request parameters:
        //               stream - only activity for given stream name (path stream takes precedence over query stream)
        //               change - only activity for given change
        //                  max - limit number of results
        //                after - only activity below the given id
        //                 type - only activity of given type
        //          disableHtml - return fields in plaintext
        // excludeProjectGroups - exclude swarm project groups from streams
        $request = $this->getRequest();
        $stream  = $this->event->getRouteMatch()->getParam('stream', $request->getQuery('stream'));
        if (!is_string($stream)) {
            // Anything other that a string (for example an array) is not supported so we'll follow the same
            // pattern as with others and simply ignore it
            $stream = null;
        }
        $change               = $request->getQuery('change');
        $max                  = $request->getQuery('max', 25);
        $after                = $request->getQuery('after');
        $type                 = $request->getQuery('type');
        $disableHtml          = $request->getQuery('disableHtml');
        $excludeProjectGroups = $request->getQuery('excludeProjectGroups');

        // build fetch query.
        $viewHelperManager = $this->services->get('ViewHelperManager');
        $p4Admin           = $this->services->get('p4_admin');
        $options           = [
            Activity::FETCH_MAXIMUM     => $max,
            Activity::FETCH_AFTER       => $after,
            Activity::FETCH_BY_CHANGE   => $change,
            Activity::FETCH_BY_STREAM   => $stream,
            Activity::FETCH_BY_TYPE     => $type
        ];

        // fetch activity and prepare data for output
        $activity         = [];
        $topics           = [];
        $preformat        = new Preformat($this->services, $request->getBaseUrl());
        $project          = strpos($stream, 'project-') === 0 ? substr($stream, strlen('project-')) : '';
        $projectList      = $viewHelperManager->get('projectList');
        $avatar           = $viewHelperManager->get('avatar');
        $reviewersChanges = $viewHelperManager->get('reviewersChanges');
        $authorChange     = $viewHelperManager->get('authorChange');
        $ipProtects       = $this->services->get('ip_protects');
        $records          = Activity::fetchAll($options, $p4Admin);

        // remove activity related to restricted/forbidden changes
        $records = $this->services->get(RestrictedChanges::class)->filter($records, 'change');

        // filter out private projects
        $records = $this->services->get('projects_filter')->filter($records, 'projects');

        foreach ($records as $event) {
            // filter out events related to files user doesn't have access to
            $depotFile = $event->get('depotFile');
            if ($depotFile && !$ipProtects->filterPaths($depotFile, Protections::MODE_READ)) {
                continue;
            }

            // filter out any streams that contain project groups
            $streams = $event->get('streams');
            if ($streams && $excludeProjectGroups) {
                $streams = array_filter(
                    $streams,
                    function ($stream) {
                        return strpos($stream, ('group-' . ProjectModel::KEY_PREFIX)) !== 0;
                    }
                );
            }

            //  - render user avatar
            //  - add formatted date
            //  - compose a url if possible
            //  - preformat/linkify descriptions
            //  - format reviewers or author as appropriate
            $description = $disableHtml ? $event->get('description') : $preformat->filter($event->get('description'));
            if ($event->getDetails('author')) {
                $description = (string) $authorChange($event->getDetails('author'))->setPlaintext((bool)$disableHtml);
            } elseif ($event->getDetails('reviewers')) {
                $description = (string) $reviewersChanges($event->getDetails('reviewers'))
                    ->setPlaintext((bool)$disableHtml);
            }

            // if this is a comment - pass it through Markdown
            if ($event->get('type') == 'comment') {
                $preformatDescrption = new Preformat($this->services, $request->getBaseUrl());
                $preformatDescrption->setMarkdown(true, true);
                $description = $disableHtml ?
                $event->get('description') : $preformatDescrption->filter($event->get('description'));
            }

            $userDAO = $this->services->get(IModelDAO::USER_DAO);

            $userFullName     = $userDAO->exists($event->get('user'), $p4Admin)
                ? $userDAO->fetchById($event->get('user'), $p4Admin)->get('FullName') : '';
            $behalfOfFullName = $userDAO->exists($event->get('behalfOf'), $p4Admin)
                ? $userDAO->fetchById($event->get('behalfOf'), $p4Admin)->get('FullName') : '';

            if ($event->get('type') === 'project') {
                // projects should not have projects
                $projects = '';
            } elseif ($disableHtml) {
                $projects = $event->get('projects');
            } else {
                $projects = $projectList($event->get('projects'), $project);
            }

            $activity[] = array_merge(
                $event->get(),
                [
                    'avatar'           => $avatar($event->get('user'), 64),
                    'date'             => date('c', $event->get('time')),
                    'url'              => $event->getUrl($this->url()),
                    'projectList'      => $projects,
                    'userExists'       => $userDAO->exists($event->get('user'), $p4Admin),
                    'behalfOfExists'   => $userDAO->exists($event->get('behalfOf'), $p4Admin),
                    'userFullName'     => $userFullName,
                    'behalfOfFullName' => $behalfOfFullName,
                    'description'      => $description,
                    'streams'          => $streams,
                ]
            );

            // remember the topic
            $topics[] = $event->get('topic');
        }

        // add comment count to the activity data
        $counts = Comment::countByTopic(array_unique($topics), $p4Admin);
        foreach ($activity as $key => $event) {
            $activity[$key]['comments'] = isset($counts[$event['topic']])
                ? $counts[$event['topic']]
                : [0, 0];
        }

        // activity stream title is taken from stream filter
        // e.g. 'user-jdoe' becomes 'jdoe', 'project-swarm' becomes 'swarm'.
        $title = explode('-', $stream);
        $title = end($title);

        if ($this->event->getRouteMatch()->getParam('rss')) {
            return $this->getFeedModel($activity, $title);
        }

        return new JsonModel(
            [
                'activity' => $activity,
                'lastSeen' => $records->getProperty('lastSeen')
            ]
        );
    }
    /**
     * my activity action to return rendered the activity.
     *
     * @return  ViewModel
     */
    public function indexAction(): ViewModel
    {
        return new ViewModel();
    }

    protected function getFeedModel($activity, $title)
    {
        $translator = $this->services->get('translator');

        // determine the URI the user came in on
        // clear the port if it was 80 so it doesn't show in the URI
        $uri = clone $this->request->getUri();
        $uri->setPort($uri->getPort() !== 80 ? $uri->getPort() : null);

        // default url for activity events that lack one
        $defaultUrl = $this->url()->fromRoute('home');

        // we'll need a fully qualified url for the 'link' get the helper
        $qualifiedUrl = $this->services->get('ViewHelperManager')->get('qualifiedUrl');

        // we'll also need a fully qualified root URL for each entry's link
        $baseUrl       = rtrim($qualifiedUrl(), '/');
        $defaultUrl    = rtrim($defaultUrl, '/');
        $basePathIndex = strrpos($baseUrl, $defaultUrl);
        if ($defaultUrl && $basePathIndex > 0) {
            $baseUrl = substr($baseUrl, 0, $basePathIndex);
            $baseUrl = rtrim($baseUrl, '/');
        }

        // create the parent feed
        $feed = new Feed;
        $feed->setTitle($translator->t('Swarm') . ($title ? ' - ' . $title : ''));
        $feed->setLink($qualifiedUrl('home'));
        $feed->setDescription($translator->t('Swarm Activity') . ($title ? ' - ' . $title : ''));

        // convert data over to feed entries
        foreach ($activity as $event) {
            // set the first entries time as our modified date
            if (!$feed->getDateModified()) {
                $feed->setDateModified((int) $event['time']);
            }

            $target = str_replace('review',  $translator->t('review'),  $event['target']);
            $target = str_replace('change',  $translator->t('change'),  $target);
            $target = str_replace('line',    $translator->t('line'),    $target);
            $target = str_replace('project', $translator->t('project'), $target);
            $entry  = $feed->createEntry();
            $entry->setTitle($event['user'] . ' ' . $translator->t($event['action']) . ' ' . $target);
            $entry->setLink($baseUrl . '/' . ltrim($event['url'] ?: $defaultUrl, '/'));
            $entry->setDateModified((int) $event['time']);
            $entry->setDescription($event['description']);
            $feed->addEntry($entry);
        }

        $model = new FeedModel;
        $model->setFeed($feed);
        return $model;
    }
}
