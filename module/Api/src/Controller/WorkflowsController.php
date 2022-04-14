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
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Permissions;
use Events\Listener\ListenerFactory;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Workflow\Model\IWorkflow;
use Workflow\Model\Workflow;
use Workflow\Filter\WorkflowV9 as Filter;
use Record\Exception\NotFoundException;

/**
 * Controller for querying, creating and updating workflows.
 */
class WorkflowsController extends AbstractApiController
{
    /**
     * Create a new workflow with the provided data
     * @param mixed $data
     * @return JsonModel
     */
    public function create($data)
    {
        $services = $this->services;
        try {
            $services->get(Services::CONFIG_CHECK)->enforce([IWorkflow::WORKFLOW, Permissions::AUTHENTICATED]);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            return $this->buildErrorResponse($e);
        }
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
        $defaults = $this->getGlobalDefaults();
        $data     = $this->applyDefaults($defaults[IWorkflow::WORKFLOW_RULES], $this->normaliseData($data));
        $filter   = new Filter($p4Admin, false);
        $filter->setMode(Filter::MODE_ADD)->setData($this->normaliseData($data));
        $model = $this->buildChangeResponse(new Workflow($p4Admin), $filter, ListenerFactory::WORKFLOW_CREATED);
        return $model;
    }

    /**
     * Builds an array based on global workflow values to use as defaults
     * @return array
     */
    private function getGlobalDefaults()
    {
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        $wfDao    = $this->services->get(IDao::WORKFLOW_DAO);
        $globalWf = $wfDao->fetchById(IWorkflow::GLOBAL_WORKFLOW_ID, $p4Admin);
        return [
            IWorkflow::WORKFLOW_RULES => [
                IWorkflow::ON_SUBMIT        => $globalWf->getOnSubmit(),
                IWorkflow::END_RULES        => $globalWf->getEndRules(),
                IWorkflow::AUTO_APPROVE     => $globalWf->getAutoApprove(),
                IWorkflow::COUNTED_VOTES    => $globalWf->getCountedVotes(),
                IWorkflow::GROUP_EXCLUSIONS => $globalWf->getGroupExclusions(),
                IWorkflow::USER_EXCLUSIONS  => $globalWf->getUserExclusions()
            ],
            IWorkflow::NAME           => '',
            IWorkflow::DESCRIPTION    => '',
            IWorkflow::OWNERS         => null,
            IWorkflow::SHARED         => false
        ];
    }

    /**
     * Modify part of an existing workflow, replacing the content of any field names provided with the new values
     * @param string        $workflowId     The id of the workflow being patched
     * @param array         $data           Data to patch
     * @return JsonModel
     */
    public function patch($workflowId, $data)
    {
        $error = null;
        $model = null;
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $services->get(Services::CONFIG_CHECK)->enforce([IWorkflow::WORKFLOW, Permissions::AUTHENTICATED]);
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $p4_user  = $services->get(ConnectionFactory::P4_USER);
            $workflow = $wfDao->fetch($workflowId, $p4Admin);
            $defaults = $this->getGlobalDefaults();
            $wfArray  = $this->applyDefaults($defaults[IWorkflow::WORKFLOW_RULES], $workflow->toArray());
            $data     = $this->applyDefaults($wfArray, $data);
            if ($workflow->canEdit($p4_user)) {
                $data   = array_replace($wfArray, $this->normaliseData($data));
                $filter = new Filter($p4Admin, $workflowId === IWorkflow::GLOBAL_WORKFLOW_ID, $workflow);
                $filter->setMode(Filter::MODE_EDIT)->setData($data);
                $model = $this->buildChangeResponse($workflow, $filter);
            } else {
                // No permission - we'll treat this as not found so as to not give an clues
                throw new NotFoundException('Cannot fetch entry. Id does not exist.');
            }
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $error = $e;
        }
        if ($error) {
            $model = $this->buildErrorResponse($error);
        }
        return $model;
    }

    /**
     * Returns the array passed with only relevant workflow values set.
     * @param array $data           data to normalise
     * @return array the normalised array
     */
    private function normaliseData(array $data)
    {
        // Only allow expected inputs
        return array_intersect_key(
            $data,
            array_flip(
                [
                    IWorkflow::SHARED,
                    IWorkflow::ON_SUBMIT,
                    IWorkflow::NAME,
                    IWorkflow::DESCRIPTION,
                    IWorkflow::OWNERS,
                    IWorkflow::END_RULES,
                    IWorkflow::AUTO_APPROVE,
                    IWorkflow::COUNTED_VOTES,
                    IWorkflow::GROUP_EXCLUSIONS,
                    IWorkflow::USER_EXCLUSIONS
                ]
            )
        );
    }

    /**
     * Applies values from defaults if not set in 'to'
     * @param array     $defaults   defaults
     * @param array     $to         apply to
     * @return array
     */
    private function applyDefaults($defaults, $to) : array
    {
        if (!isset($to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::RULE]) ||
            empty($to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::RULE])) {
            $to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::RULE] = [];
        }
        if (!isset($to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::MODE]) ||
            empty($to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::MODE])) {
            $to[IWorkflow::GROUP_EXCLUSIONS][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::USER_EXCLUSIONS][IWorkflow::RULE]) ||
            empty($to[IWorkflow::USER_EXCLUSIONS][IWorkflow::RULE])) {
            $to[IWorkflow::USER_EXCLUSIONS][IWorkflow::RULE] = [];
        }
        if (!isset($to[IWorkflow::USER_EXCLUSIONS][IWorkflow::MODE]) ||
            empty($to[IWorkflow::USER_EXCLUSIONS][IWorkflow::MODE])) {
            $to[IWorkflow::USER_EXCLUSIONS][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::COUNTED_VOTES][IWorkflow::RULE]) ||
            empty($to[IWorkflow::COUNTED_VOTES][IWorkflow::RULE])) {
            $to[IWorkflow::COUNTED_VOTES][IWorkflow::RULE] =
                $defaults[IWorkflow::COUNTED_VOTES][IWorkflow::RULE];
        }
        if (!isset($to[IWorkflow::COUNTED_VOTES][IWorkflow::MODE]) ||
            empty($to[IWorkflow::COUNTED_VOTES][IWorkflow::MODE])) {
            $to[IWorkflow::COUNTED_VOTES][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::AUTO_APPROVE][IWorkflow::RULE]) ||
            empty($to[IWorkflow::AUTO_APPROVE][IWorkflow::RULE])) {
            $to[IWorkflow::AUTO_APPROVE][IWorkflow::RULE] =
                $defaults[IWorkflow::AUTO_APPROVE][IWorkflow::RULE];
        }
        if (!isset($to[IWorkflow::AUTO_APPROVE][IWorkflow::MODE]) ||
            empty($to[IWorkflow::AUTO_APPROVE][IWorkflow::MODE])) {
            $to[IWorkflow::AUTO_APPROVE][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::RULE]) ||
            empty($to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::RULE])) {
            $to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::RULE] =
                $defaults[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::RULE];
        }
        if (!isset($to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::MODE]) ||
            empty($to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::MODE])) {
            $to[IWorkflow::END_RULES][IWorkflow::UPDATE][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::RULE]) ||
            empty($to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::RULE])) {
            $to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::RULE] =
                $defaults[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::RULE];
        }
        if (!isset($to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::MODE]) ||
            empty($to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::MODE])) {
            $to[IWorkflow::ON_SUBMIT][IWorkflow::WITH_REVIEW][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        if (!isset($to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE]) ||
            empty($to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE])) {
            $to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE] =
                $defaults[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE];
        }
        if (!isset($to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE]) ||
            empty($to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE])) {
            $to[IWorkflow::ON_SUBMIT][IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
        }
        return $to;
    }

    /**
     * Builds a response for update requests PUT, PATCH and POST
     * @param IWorkflow     $workflow   the workflow being changed
     * @param Filter        $filter     the filter to use for values
     * @param string        $task       task performed, defaults to 'workflow updated'
     * @return JsonModel
     */
    private function buildChangeResponse($workflow, Filter $filter, $task = ListenerFactory::WORKFLOW_UPDATED)
    {
        if ($filter->isValid()) {
            $wfDao = $this->services->get(IModelDAO::WORKFLOW_DAO);
            $workflow->set($filter->getValues());
            $wfDao->save($workflow);
            $model = $this->prepareSuccessModel(['workflow' => $this->removeUnsupported($workflow->toArray())]);
            $this->addTask($model, $task);
        } else {
            // Unless the filter specifically set forbidden set the response code to the general 400
            if ($this->getResponse()->getStatusCode() !== Response::STATUS_CODE_403) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
            $jsonModel = new JsonModel(
                [
                    'isValid'  => false,
                    'messages' => $filter->getMessages(),
                    'workflow' => null,
                ]
            );
            $model     = $this->prepareErrorModel($jsonModel);
        }
        return $model;
    }

    /**
     * Delete an existing workflow
     * @param mixed $workflowId
     * @return mixed|null|JsonModel
     * @throws \Exception
     */
    public function delete($workflowId)
    {
        $error = null;
        $model = null;
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $services->get(Services::CONFIG_CHECK)->enforce([IWorkflow::WORKFLOW, Permissions::AUTHENTICATED]);
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $workflow = $wfDao->fetch($workflowId, $p4Admin);
            try {
                $wfDao->delete($workflow);
                $translator = $services->get(TranslatorFactory::SERVICE);
                $model      = new JsonModel(
                    [
                        'isValid' => true,
                        'messages' => [$translator->t('Workflow [%s] was deleted', [$workflowId])],
                    ]
                );
            } catch (ForbiddenException $e) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
                $error = $e;
            }
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $error = $e;
        }
        if ($error) {
            $model = $this->buildErrorResponse($error);
        }
        return $model;
    }

    /**
     * Build a response based on an exception
     * @param mixed $error  the exception
     * @return JsonModel
     */
    private function buildErrorResponse($error)
    {
        return new JsonModel(
            [
                'isValid'  => false,
                'messages' => [$error->getMessage()],
            ]
        );
    }

    /**
     * Replace a workflow with the provided values
     * @param string        $workflowId     The id of the workflow being updated
     * @param array         $data           Data to patch
     * @return JsonModel
     */
    public function update($workflowId, $data)
    {
        $error = null;
        $model = null;
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $services->get(Services::CONFIG_CHECK)->enforce([IWorkflow::WORKFLOW, Permissions::AUTHENTICATED]);
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $p4_user  = $services->get(ConnectionFactory::P4_USER);
            $workflow = $wfDao->fetch($workflowId, $p4Admin);
            $data     = $this->normaliseData($data);
            // PUT should have provided all values the data just replaces defaults
            $data = $this->applyDefaults($this->getGlobalDefaults()[IWorkflow::WORKFLOW_RULES], $data);
            if ($workflow->canEdit($p4_user)) {
                $filter = new Filter($p4Admin, $workflowId === IWorkflow::GLOBAL_WORKFLOW_ID, $workflow);
                $filter->setMode(Filter::MODE_EDIT)->setData($data);
                $model = $this->buildChangeResponse($workflow, $filter);
            } else {
                // No permission - we'll treat this as not found so as to not give an clues
                throw new NotFoundException('Cannot fetch entry. Id does not exist.');
            }
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $error = $e;
        }
        if ($error) {
            $model = $this->buildErrorResponse($error);
        }
        return $model;
    }

    /**
     * Adds a task to the queue manager for an action
     * @param JsonModel     $workflow   the workflow model in JSON form
     * @param string        $task       task performed, defaults to 'workflow updated'
     */
    private function addTask(JsonModel $workflow, $task = ListenerFactory::WORKFLOW_UPDATED)
    {
        $this->services->get('queue')->addTask(
            $task,
            $workflow->getVariable(Workflow::WORKFLOW)['id'],
            [
                'user' => $this->services->get(ConnectionFactory::P4_USER)->getUser()
            ]
        );
    }

    /**
     * Get a list of known workflows, with an option to limit the fields returned
     * @return JsonModel
     */
    public function getList()
    {
        $services = $this->services;
        $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
        try {
            $services->get(Services::CONFIG_CHECK)->enforce(IWorkflow::WORKFLOW);
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            return $this->buildErrorResponse($e);
        }
        $fields  = $this->getRequest()->getQuery(self::FIELDS);
        $noCache = $this->getRequest()->getQuery(self::NO_CACHE);
        $p4Admin = $services->get(ConnectionFactory::P4_ADMIN);
        $options = [];
        if (isset($noCache) && $noCache === 'true') {
            $options[IModelDAO::FETCH_NO_CACHE] = true;
        }
        $workflows        = $wfDao->fetchAll($options, $p4Admin);
        $limitedWorkflows = [];

        foreach ($workflows->toArray() as $workflow) {
            $limitedWorkflows[] = $this->limitEntityFields($this->removeUnsupported($workflow), $fields);
        }
        return $this->prepareSuccessModel(new JsonModel(['workflows' => $limitedWorkflows]));
    }

    /**
     * Remove unsupported fields (introduced after v9) from an array. Removes:
     * - 'tests'
     * @param array $entityArray         workflow entity array
     * @return array array with unsupported values removed
     */
    private function removeUnsupported(array $entityArray)
    {
        unset($entityArray[IWorkflow::TESTS]);
        return $entityArray;
    }

    /**
     * Gets a specific workflow by its id
     * @param string $workflowId the id of the workflow
     * @return JsonModel
     */
    public function get($workflowId)
    {
        $workflow = null;
        $error    = null;
        $fields   = $this->getRequest()->getQuery(self::FIELDS);
        try {
            $services = $this->services;
            $wfDao    = $services->get(IModelDAO::WORKFLOW_DAO);
            $services->get(Services::CONFIG_CHECK)->enforce(IWorkflow::WORKFLOW);
            $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);
            $workflow = $wfDao->fetch($workflowId, $p4Admin);
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $e;
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
            $error = $e;
        }
        if ($workflow) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
            return $this->prepareSuccessModel(
                new JsonModel(
                    [
                        'workflow' => $this->limitEntityFields(
                            $this->removeUnsupported($workflow->toArray()), $fields
                        )
                    ]
                )
            );
        } else {
            return $this->buildErrorResponse($error);
        }
    }
}
