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
use Activity\Model\IActivity;
use Api\Controller\AbstractRestfulController;
use Api\IRequest;
use Application\Connection\ConnectionFactory;
use Application\Model\IModelDAO;
use Activity\Filter\IParameters;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\Model\Fielded\Iterator;
use P4\Spec\Exception\NotFoundException as RecordNotFoundException;
use Record\Key\AbstractKey;
use Exception;
use Projects\Model\Project as ProjectModel;

/**
 * Class ActivityApi. Controller class to deal with Activity data.
 * @package Activity\Controller
 */
class ActivityApi extends AbstractRestfulController implements IActivityApi
{
    /**
     * Get a list of all activities.
     * Example: GET /api/v11/activity
     * {
     *      "error": null,
     *      "messages": null,
     *      "data": {
     *          "activity": [
     *              {
     *                  id: 1646,
     *                  action: "Automated tests reported",
     *                  behalfOf: null,
     *                  behalfOfExists: false,
     *                  behalfOfFullName: "",
     *                  change: 1236,
     *                  comments: [0,0],
     *                  date: "2021-05-05T15:25:01+00:00",
     *                  depotFile: null,
     *                  description: "Make a review.",
     *                  details: [ ],
     *                  followers: [ ],
     *                  link: ["review",{review: "1236",version: 4}],
     *                  preposition: "failed tests for",
     *                  projectList: [ ],
     *                  projects: [ ],
     *                  streams: ["review-1236","user-","personal-","personal-macy.winter","personal-super"],
     *                  target: "review 1236",
     *                  time: 1620228301,
     *                  topic: "reviews/1236",
     *                  type: "review",
     *                  url: "/reviews/1236/v4/",
     *                  user: null,
     *                  userExists: false,
     *                  userFullName: ""
     *             }
     *          ],
     *          "totalCount": 42,
     *          "lastSeen": 1646
     *      }
     *  }
     * @return JsonModel list of activity
     */
    public function getList() : JsonModel
    {
        $activities   = [];
        $activityData = null;
        $error        = null;
        $request      = $this->getRequest();
        $query        = $request->getQuery();
        try {
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            $filter  = $this->services->get(IParameters::ACTIVITY_STREAM_PARAMETERS_FILTER);
            $options = $query->toArray();
            $filter->setData($options);
            if ($filter->isValid()) {
                $options      = $filter->getValues();
                $fields       = $this->getRequest()->getQuery(IRequest::FIELDS);
                $dao          = $this->services->get(IModelDAO::ACTIVITY_DAO);
                $activityData = $dao->fetchAll($options, $p4Admin);
                $activities   = $this->limitFieldsForAll($this->modelsToArray($activityData), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $error = $filter->getMessages();
            }
        } catch (RecordNotFoundException $e) {
            // no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_ACTIVITY             => $activities,
                AbstractKey::FETCH_TOTAL_COUNT  => $activityData->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN          => $activityData->getProperty(AbstractKey::LAST_SEEN),
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * @inheritDoc
     * Example: GET /api/v11/activity/review
     * @return JsonModel
     */
    public function getByTypeAction() : JsonModel
    {
        $activities   = [];
        $activityData = null;
        $error        = null;
        $request      = $this->getRequest();
        $query        = $request->getQuery();
        try {
            $type    = $this->getEvent()->getRouteMatch()->getParam(Activity::FETCH_BY_TYPE);
            $filter  = $this->services->get(IParameters::ACTIVITY_STREAM_PARAMETERS_FILTER);
            $options = $query->toArray();
            $filter->setData($options);
            if ($filter->isValid()) {
                $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
                $dao     = $this->services->get(IModelDAO::ACTIVITY_DAO);
                $options = $filter->getValues();
                $fields  = $this->getRequest()->getQuery(IRequest::FIELDS);

                $options[Activity::FETCH_BY_TYPE] = $type;

                $activityData = $dao->fetchAll($options, $p4Admin);
                $activities   = $this->limitFieldsForAll($this->modelsToArray($activityData), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $error = $filter->getMessages();
            }
        } catch (RecordNotFoundException $e) {
            // no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_ACTIVITY             => $activities,
                AbstractKey::FETCH_TOTAL_COUNT  => $activityData->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN          => $activityData->getProperty(AbstractKey::LAST_SEEN),
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * @inheritDoc
     * Example: GET /api/v11/reviews/1/activity
     * @return JsonModel
     */
    public function getByStreamAction() : JsonModel
    {
        $activities   = [];
        $activityData = null;
        $error        = null;
        $request      = $this->getRequest();
        $query        = $request->getQuery();
        try {
            $streamPath = $this->getEvent()->getRouteMatch()->getParam(self::STREAM);
            $streamId   = $this->getEvent()->getRouteMatch()->getParam(self::STREAM_ID);
            $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
            $filter     = $this->services->get(IParameters::ACTIVITY_PARAMETERS_FILTER);
            $options    = $query->toArray();
            $filter->setData($options);
            if ($filter->isValid()) {
                $options      = $filter->getValues();
                $fields       = $this->getRequest()->getQuery(IRequest::FIELDS);
                $dao          = $this->services->get(IModelDAO::ACTIVITY_DAO);
                $activityData = $dao->fetchByStream($streamPath, $streamId, $options, $p4Admin);
                $activities   = $this->limitFieldsForAll($this->modelsToArray($activityData), $fields);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $error = $filter->getMessages();
            }
        } catch (RecordNotFoundException $e) {
            // no record found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $error = $this->buildMessage(Response::STATUS_CODE_404, $e->getMessage());
        } catch (Exception $e) {
            // unknown error just catch and return.
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $error = $this->buildMessage(Response::STATUS_CODE_500, $e->getMessage());
        }
        if ($error) {
            $json = $this->error([$error], $this->getResponse()->getStatusCode());
        } else {
            $result = [
                self::DATA_ACTIVITY             => $activities,
                AbstractKey::FETCH_TOTAL_COUNT  => $activityData->getProperty(AbstractKey::FETCH_TOTAL_COUNT),
                AbstractKey::LAST_SEEN          => $activityData->getProperty(AbstractKey::LAST_SEEN),
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Convert an iterator of Activity to an array representation
     * @param Iterator     $activities            iterator of activities
     * @return array
     */
    protected function modelsToArray(Iterator $activities): array
    {
        $filteredActivity = [];
        foreach ($activities as $activity) {
            // filter out any streams that contain project groups
            $streams = $activity->get(IActivity::STREAMS);
            if ($streams) {
                $streams = array_filter(
                    $streams,
                    function ($stream) {
                        return strpos($stream, (IActivityApi::GROUPHYPHEN . ProjectModel::KEY_PREFIX)) !== 0;
                    }
                );
            }
            $filteredActivity[] = array_merge(
                $activity->get(),
                [
                    IActivity::STREAMS             => $streams,
                ]
            );
        }
        return $filteredActivity;
    }
}
