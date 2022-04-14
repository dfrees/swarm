<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Application\Config\ConfigException;
use Application\Config\Services;
use InvalidArgumentException;
use P4\Exception;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Reviews\UpdateService;
use Workflow\Manager;
use Laminas\View\Model\JsonModel;
use Api\Converter\Reviewers as ReviewersConverter;
use Laminas\Http\Response;
use Api\Filter\Changes;

/**
 * API controller providing a service for changes
 */
class ChangesController extends AbstractApiController
{
    /**
     * Get the default reviewer for the changelist in the request
     * @return JsonModel
     * @throws ConfigException
     * @throws Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Record\Exception\NotFoundException
     */
    public function defaultReviewersAction()
    {
        $error    = null;
        $services = $this->services;
        $services->get('permissions')->enforce('authenticated');
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $p4User   = $services->get('p4_user');
        // Check if the change exists.
        try {
            $change = Change::fetchById($changeId, $p4User);
        } catch (NotFoundException $e) {
            $error = $e;
        } catch (InvalidArgumentException $e) {
            $error = $e;
        }
        if ($error) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            return new JsonModel(
                [
                    'isValid'  => false,
                    'messages' => [$error->getMessage()],
                ]
            );
        }
        $reviewers = []; // Only variables can be passed by reference
        $reviewers = ReviewersConverter::expandUsersAndGroups(
            UpdateService::mergeDefaultReviewersForChange($change, $reviewers, $p4User)
        );

        return new JsonModel(
            [
                'change' => ['id' => $changeId, 'defaultReviewers' => $reviewers]
            ]
        );
    }

    /**
     * Get the projects/branches affected by the changelist requested
     * @return JsonModel
     * @throws Exception
     */
    public function affectsProjectsAction()
    {
        $error    = null;
        $services = $this->services;
        $services->get('permissions')->enforce('authenticated');
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $p4       = $services->get('p4');
        // Check if the change exists.
        try {
            $change = Change::fetchById($changeId, $p4);
        } catch (NotFoundException $e) {
            $error = $e;
        } catch (InvalidArgumentException $e) {
            $error = $e;
        }
        if ($error) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            return new JsonModel(
                [
                    'isValid'  => false,
                    'messages' => [$error->getMessage()],
                ]
            );
        }

        $findAffected     = $services->get(Services::AFFECTED_PROJECTS);
        $affectedProjects = $findAffected->findByChange($p4, $change);
        return new JsonModel(
            [
                'change' => ['id' => $changeId, 'projects' => $affectedProjects]
            ]
        );
    }

    /**
     * Carries out enforced checks on the change if enabled
     * @param string|null    $user   the user carrying out the action, this will be used to check
     *                               exclusions and if not provided will be ignored
     * @return JsonModel
     */
    private function checkEnforced($user)
    {
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $manager  = $this->services->get(Services::WORKFLOW_MANAGER);
        return $this->translateWorkflowResult($manager->checkEnforced($changeId, $user));
    }

    /**
     * Carries out checks on the change if enabled
     * @return JsonModel
     */
    public function checkAction()
    {
        $result = null;
        $filter = new Changes();
        $filter->setData($this->getRequest()->getQuery()->toArray());
        $valid = $filter->isValid();
        if ($valid) {
            $type = $this->getRequest()->getQuery(self::TYPE);
            $user = $this->getRequest()->getQuery(self::USER);
            switch ($type) {
                case Changes::ENFORCED:
                    $result = $this->checkEnforced($user);
                    break;
                case Changes::STRICT:
                    $result = $this->checkStrict($user);
                    break;
                case Changes::SHELVE:
                    $result = $this->checkShelve($user);
                    break;
            }
        } else {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            $result = $this->prepareErrorModel(
                new JsonModel(
                    [
                        'isValid'  => false,
                        'messages' => $filter->getMessages()
                    ]
                )
            );
        }
        return $result;
    }

    /**
     * Carries out shelve checks on the change if enabled (shelve-submit)
     * @param string|null    $user   the user carrying out the action, this will be used to check
     *                               exclusions and if not provided will be ignored
     * @return JsonModel
     */
    private function checkShelve($user)
    {
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $manager  = $this->services->get(Services::WORKFLOW_MANAGER);
        return $this->translateWorkflowResult($manager->checkShelve($changeId, $user));
    }

    /**
     * Carries out strict checks on the change if enabled.
     * @param string|null    $user   the user carrying out the action, this will be used to check
     *                               exclusions and if not provided will be ignored
     * @return JsonModel
     */
    private function checkStrict($user)
    {
        $changeId = $this->getEvent()->getRouteMatch()->getParam('change');
        $manager  = $this->services->get(Services::WORKFLOW_MANAGER);
        return $this->translateWorkflowResult($manager->checkStrict($changeId, $user));
    }

    /**
     * Translates the result from a workflow action to the format to be returned by the API
     * @param $result
     * @return JsonModel
     */
    private function translateWorkflowResult($result)
    {
        $logger = $this->services->get('logger');
        $valid  = $result[Manager::KEY_STATUS] === Manager::STATUS_OK ? true : false;
        if (!$valid) {
            if ($result[Manager::KEY_STATUS] === Manager::STATUS_BAD_CHANGE) {
                // The traditional bad data response
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            }
        }
        $result = [
            'isValid'  => $valid,
            'status'   => $result[Manager::KEY_STATUS],
            'messages' => $result[Manager::KEY_MESSAGES]
        ];
        $logger->trace('Workflow result ' . var_export($result, true));
        return new JsonModel($result);
    }
}
