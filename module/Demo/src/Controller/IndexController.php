<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Demo\Controller;

use Application\Config\Services;
use Application\Controller\AbstractIndexController;
use Application\Model\IModelDAO;
use Comments\Model\Comment;
use P4\Counter\Counter;
use P4\Spec\Change;
use Groups\Model\Group;
use Users\Model\User;
use Projects\Model\Project as ProjectModel;
use Reviews\Model\Review;
use Laminas\View\Model\JsonModel;
use P4\Counter\Exception\NotFoundException;
use P4\Exception as P4Exception;
use Record\Exception\Exception as RecordException;
use Exception;

class IndexController extends AbstractIndexController
{
    /**
     * Generate data.
     * @return JsonModel
     * @throws NotFoundException
     * @throws P4Exception
     * @throws RecordException
     * @throws Exception
     */
    public function generateAction()
    {
        $request  = $this->getRequest();
        $services = $this->services;
        $p4Admin  = $services->get('p4_admin');
        $groupDao = $services->get(IModelDAO::GROUP_DAO);

        // only generate data if user is an admin
        $services->get('permissions')->enforce('admin');

        // if change counter is >1000, assume this is a real server and bail.
        if (!$request->getQuery('force') && Counter::exists('change') && Counter::fetch('change')->get() > 1000) {
            throw new Exception(
                "Refusing to generate data. This server looks real (>1000 changes). Use 'force' param to override."
            );
        }

        // making demo data is hard work!
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        $counts = [
            'users'    => ['created' => 0, 'deleted' => 0],
            'groups'   => ['created' => 0, 'deleted' => 0],
            'projects' => ['created' => 0, 'deleted' => 0],
            'reviews'  => ['created' => 0, 'deleted' => 0],
            'comments' => ['created' => 0, 'deleted' => 0]
        ];

        $userDAO      = $this->services->get(IModelDAO::USER_DAO);
        $projectDAO   = $this->services->get(IModelDAO::PROJECT_DAO);
        $findAffected = $services->get(Services::AFFECTED_PROJECTS);
        // optionally delete existing data
        if ($request->getQuery('reset')) {
            // delete all users, except for the current user
            $users = $userDAO->fetchAll([], $p4Admin);
            $users->filter('User', $p4Admin->getUser(), [$users::FILTER_INVERSE]);
            foreach ($users->keys() as $key) {
                $userDAO->delete($userDAO->fetchById($key));
            }
            $counts['users']['deleted'] = $users->count();

            $groups = $groupDao->fetchAll([], $p4Admin);
            foreach ($groups as $group) {
                $groupDao->delete($group);
            }
            $counts['groups']['deleted'] = $groups->count();

            $projects = $projectDAO->fetchAll([], $p4Admin);
            foreach ($projects as $project) {
                $projectDAO->delete($project);
            }
            $counts['projects']['deleted'] = $projects->count();

            $reviews = Review::fetchAll([], $p4Admin);
            $reviews->invoke('delete');
            $counts['reviews']['deleted'] = $reviews->count();

            $comments = Comment::fetchAll([], $p4Admin);
            $comments->invoke('delete');
            $counts['comments']['deleted'] = $comments->count();
        }

        // make requested number of users (default is 0)
        $users = $this->getUserNames((int) $request->getQuery('users', 0));
        foreach ($users as $name) {
            $user = new User($p4Admin);
            $user->set(['User' => $name, 'FullName' => $name, 'Email' => $name . '@localhost']);
            $userDAO->save($user, $p4Admin);
        }
        $counts['users']['created'] = count($users);

        // make requested number of groups (default is 0)
        // each group gets 1-3% of the user population
        $groups = (int) $request->getQuery('groups', 0);
        $users  = $userDAO->fetchAll([], $p4Admin)->invoke('getId');
        if ($groups) {
            for ($i = 0; $i < $groups; $i++) {
                $group   = new Group($p4Admin);
                $members = max(1, rand(count($users) * .01, count($users) * .03));
                $members = (array) array_rand(array_flip($users), $members);
                $group->set(
                    [
                        'Group'  => 'group' . $i,
                        'Owners' => [$p4Admin->getUser()],
                        'Users'  => $members
                    ]
                );
                $groupDao->save($group, false, true);
            }
        }
        $counts['groups']['created'] = $groups;

        // make requested number of projects (default is 5)
        $projects = $this->getProjectData((int) $request->getQuery('projects', 5));
        foreach ($projects as $project) {
            $model = new ProjectModel($p4Admin);
            $model->set($project);
            $model->setMembers((array) array_rand(array_flip($users), rand(1, ceil(count($users)/4))));
            $projectDAO->save($model);
        }
        $counts['projects']['created'] = count($projects);

        // make max of the requested number of reviews based on recent changes (default is 100)
        $reviews     = [];
        $reviewCount = (int) $request->getQuery('reviews', 100);
        if ($reviewCount) {
            $states  = ['needsReview', 'needsRevision', 'approved', 'rejected', 'archived'];
            $changes = Change::fetchAll(
                [
                    Change::FETCH_MAXIMUM   => $reviewCount,
                    Change::FETCH_BY_STATUS => 'submitted'
                ],
                $p4Admin
            );
            foreach ($changes as $change) {
                $review = Review::createFromChange($change->getId(), $p4Admin);
                $review->set('state',    $states[array_rand($states)]);
                $review->set('projects', $findAffected->findByChange($p4Admin, $change));
                $review->set('participants', (array) array_rand(array_flip($users), rand(1, min(count($users), 10))));
                $review->save();
                $review->updateFromChange($change, true);
                $review->save();
                $reviews[] = $review->getId();
            }
        }
        $counts['reviews']['created'] = count($reviews);

        // make requested number of comments (default is average of 5 per review)
        $comments = $this->getCommentData($request->getQuery('comments', 5 * count($reviews)));
        foreach ($comments as $comment) {
            $model = new Comment($p4Admin);
            $model->set('topic', 'reviews/' . $reviews[array_rand($reviews)]);
            $model->set('user', $users[array_rand($users)]);
            $model->set('body', $comment);
            $model->save();
        }
        $counts['comments']['created'] = count($comments);

        return new JsonModel($counts);
    }

    protected function getProjectData($count)
    {
        $projects = [
            [
                'id' => 'jam',
                'name' => 'Jam',
                'branches' => [
                    [
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => ['//depot/Jam/MAIN/...']
                    ],
                    [
                        'id' => '2.1',
                        'name' => 'Release 2.1',
                        'paths' => ['//depot/Jam/REL2.1/...']
                    ],
                    [
                        'id' => '2.2',
                        'name' => 'Release 2.2',
                        'paths' => ['//depot/Jam/REL2.2/...']
                    ]
                ]
            ],
            [
                'id' => 'jamgraph',
                'name' => 'Jamgraph',
                'branches' => [
                    [
                        'id' => 'dev',
                        'name' => 'Development',
                        'paths' => ['//depot/Jamgraph/DEV/...']
                    ],
                    [
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => ['//depot/Jamgraph/MAIN/...']
                    ],
                    [
                        'id' => '1.0',
                        'name' => 'Release 1.0',
                        'paths' => ['//depot/Jamgraph/REL1.0/...']
                    ]
                ]
            ],
            [
                'id' => 'misc',
                'name' => 'Miscellaneous',
                'branches' => [
                    [
                        'id' => 'manuals',
                        'name' => 'Manuals',
                        'paths' => ['//depot/Misc/manuals/...']
                    ],
                    [
                        'id' => 'marketing',
                        'name' => 'Marketing',
                        'paths' => ['//depot/Misc/marketing/...']
                    ]
                ]
            ],
            [
                'id' => 'talkhouse',
                'name' => 'Talkhouse',
                'branches' => [
                    [
                        'id' => 'main',
                        'name' => 'Main',
                        'paths' => ['//depot/Talkhouse/main-dev/...']
                    ],
                    [
                        'id' => '1.0',
                        'name' => 'Release 1.0',
                        'paths' => ['//depot/Talkhouse/rel1.0/...']
                    ],
                    [
                        'id' => '1.5',
                        'name' => 'Release 1.5',
                        'paths' => ['//depot/Talkhouse/rel1.5/...']
                    ]
                ]
            ],
            [
                'id' => 'www',
                'name' => 'WWW',
                'branches' => [
                    [
                        'id' => 'dev',
                        'name' => 'Development',
                        'paths' => ['//depot/www/DEV/...']
                    ],
                    [
                        'id' => 'live',
                        'name' => 'Live',
                        'paths' => ['//depot/www/live/...']
                    ],
                    [
                        'id' => 'review',
                        'name' => 'Review',
                        'paths' => ['//depot/www/review/...']
                    ]
                ]
            ]
        ];

        // duplicate projects up to requested count
        $have = count($projects);
        for ($i = $have; $i < $count; $i++) {
            $project          = $projects[rand(0, $have - 1)];
            $project['id']   .= $i;
            $project['name'] .= $i;
            $projects[]       = $project;
        }

        // remove unwanted projects
        $projects = array_slice($projects, 0, $count);

        return $projects;
    }

    protected function getCommentData($count)
    {
        if (!$count) {
            return [];
        }

        $comments = [];

        // if this is a test, don't make external requests
        if ($this->getRequest()->isTest) {
            return array_fill(0, $count, 'test comment');
        }

        // grab 50 paragraphs from hipster-ipsum
        $hipster  = file_get_contents('http://hipsterjesus.com/api/?paras=50&html=false');
        $hipster  = json_decode($hipster, true);
        $comments = explode("\n", $hipster['text']);

        // another 50 from bacon-ipsum
        $bacon    = file_get_contents('http://baconipsum.com/api/?type=meat-and-filler&paras=50');
        $bacon    = json_decode($bacon, true);
        $comments = array_merge($comments, $bacon);

        // make more comments if necessary
        while (count($comments) < $count) {
            $comments = array_merge($comments, $comments);
        }

        return array_slice($comments, 0, $count);
    }

    protected function getUserNames($count)
    {
        $names = [
            'ethan',
            'owen',
            'liam',
            'ryan',
            'lucas',
            'daniel',
            'mason',
            'oliver',
            'logan',
            'james',
            'noah',
            'nathan',
            'alexander',
            'jayden',
            'benjamin',
            'samuel',
            'jacob',
            'matthew',
            'jack',
            'william',
            'olivia',
            'sophie',
            'emma',
            'abigail',
            'sophia',
            'charlotte',
            'emily',
            'lily',
            'ava',
            'brooklyn',
            'ella',
            'madison',
            'chloe',
            'isla',
            'isabella',
            'grace',
            'avery',
            'maya',
            'hannah',
            'amelia'
        ];

        // flip so array_rand gives us values directly
        $names = array_flip($names);

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = array_rand($names) . $i;
        }

        return $result;
    }
}
