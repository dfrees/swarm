<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Listener;

use Activity\Model\Activity;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Model\IModelDAO;
use Events\Listener\AbstractEventListener;
use Events\Listener\ListenerFactory;
use Reviews\Model\IReview;
use TestIntegration\Model\ITestRun;
use TestIntegration\Model\TestRun as Model;
use Laminas\EventManager\Event;
use Record\Exception\NotFoundException;
use Exception;

/**
 * Class TestRun, listens to TestRun events raised based on queue tasks
 * @package TestIntegration\Listener
 */
class TestRun extends AbstractEventListener
{
    const UPGRADED_ACTION = 'upgraded';
    const REVIEW_ID       = 'reviewId';
    const LOG_PREFIX      = TestRun::class;

    /**
     * Listener for 'on demand' test requests. Creates an activity when one of these tests has been started.
     * @param Event     $event  the event
     */
    public function onDemandTestStarted(Event $event)
    {
        try {
            $translator        = $this->services->get(TranslatorFactory::SERVICE);
            $reviewDao         = $this->services->get(IDao::REVIEW_DAO);
            $testRunDao        = $this->services->get(IDao::TEST_RUN_DAO);
            $testDefinitionDao = $this->services->get(IDao::TEST_DEFINITION_DAO);
            $data              = $event->getParam('data');
            $reviewId          = $data && isset($data[self::REVIEW_ID]) ? $data[self::REVIEW_ID] : null;
            $user              = $data && isset($data['user']) ? $data['user'] : null;
            $p4Admin           = $this->services->get(ConnectionFactory::P4_ADMIN);
            $review            = $reviewDao->fetch($reviewId, $p4Admin);
            $version           = $review->getHeadVersion();
            $testRun           = $testRunDao->fetch($event->getParam('id'), $p4Admin);
            $testDefinition    = $testDefinitionDao->fetchById($testRun->getTest(), $p4Admin);
            $activity          = new Activity();
            $activity->set(
                [
                    'type' => 'review',
                    'link' => ['review', ['review' => $reviewId, 'version' => $version]],
                    'user' => $user,
                    'streams' => ['review-' . $reviewId],
                    'target' => 'review ' . $reviewId . ' (revision ' . $version . ')',
                    'time' => $event->getParam('time'),
                    'action' => $translator->t("started test '%s' for", [$testRun->getTitle()]),
                    'projects' => $this->getProjectList($testRun->getBranches())
                ]
            );
            $event->setParam('activity', $activity);
        } catch (Exception $e) {
            $this->logger->err(
                sprintf("An error occurred creating the activity for a started test [%s]", $e->getMessage())
            );
        }
    }

    /**
     * Listener for TestRun add/update. Updates the overall test status on a review if the
     * TestRun changed is for the latest version
     * @param Event $event  Zend event
     * @return bool true if a review was updated, false otherwise
     */
    public function update(Event $event) : bool
    {
        $updated = true;
        $data    = $event->getParam('data');
        $id      = $data[Model::FIELD_CHANGE];
        $version = $data[Model::FIELD_VERSION];
        try {
            $reviewDao = $this->services->get(IDao::REVIEW_DAO);
            $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
            $review    = $reviewDao->fetchByIdUnrestricted($id, $p4Admin);
            if ($version == $review->getHeadVersion()) {
                $dao       = $this->services->get(IDao::TEST_RUN_DAO);
                $newStatus = $dao->calculateTestStatus($review->getTestRuns(), $p4Admin);
                $this->logger->debug(sprintf("%s: New overall test status is [%s]", self::LOG_PREFIX, $newStatus));
                if ($newStatus) {
                    $currentStatus = $review->getTestStatus();
                    // Only track pass or fail in the previous test status
                    if ($currentStatus === IReview::TEST_STATUS_FAIL ||
                        $currentStatus === IReview::TEST_STATUS_PASS) {
                        $review->setPreviousTestStatus($currentStatus);
                    }
                    $review->setTestStatus($newStatus);
                    $this->logger->debug(
                        sprintf(
                            "%s: Previous test status is [%s]",
                            self::LOG_PREFIX,
                            $review->getPreviousTestStatus()
                        )
                    );
                    // If the new overall test status is 'pass' approve the review if votes/tests/workflow rules allow
                    if ($newStatus === IReview::TEST_STATUS_PASS) {
                        if ($reviewDao->canApprove($review)) {
                            $this->logger->debug(sprintf("%s: Approved review [%s]", self::LOG_PREFIX, $id));
                            $review->setState(IReview::STATE_APPROVED);
                        }
                    }
                    $reviewDao->save($review);
                }
            }
        } catch (NotFoundException $e) {
            $updated = false;
            $this->logger->err(sprintf("%s: Review [%s] not found on TestRun update", self::LOG_PREFIX, $id));
        } catch (Exception $e) {
            $updated = false;
            $this->logger->err(sprintf("%s: An exception occurred getting the review [%s]", self::LOG_PREFIX, $id));
        }
        return $updated;
    }

    /**
     * Function to upgrade existing TestRun instances triggered by an upgrade task in the queue
     * @param Event $event  Laminas event
     */
    public function upgradeTestRuns(Event $event)
    {
        $logPrefix = self::LOG_PREFIX . ':' . __FUNCTION__ . ':';
        $services  = $this->services;
        $logger    = $this->logger;
        try {
            $p4Admin    = $services->get(ConnectionFactory::P4_ADMIN);
            $testRunDao = $services->get(IModelDAO::TEST_RUN_DAO);
            $data       = $event->getParam(ListenerFactory::DATA);
            $upgraded   = [];
            foreach (array_filter(
                iterator_to_array($testRunDao->fetchAll([], $p4Admin)),
                function ($testRun) use ($data) {
                    return $data[ITestRun::FIELD_UPGRADE] > (int)$testRun->get(ITestRun::FIELD_UPGRADE);
                }
            ) as $needsUpgrade) {
                $description = $needsUpgrade->getTest() . '(' . $needsUpgrade->getId() . ')';
                try {
                    $logger->trace(sprintf("%s Upgrading [%s]", $logPrefix, $description));
                    $testRunDao->save($needsUpgrade);
                    $upgraded[] = $description;
                } catch (Exception $e) {
                    $logger->err(sprintf("%s Failed to upgrade test run [%s]", $logPrefix, $description));
                    $logger->err($e->getMessage());
                }
            }
            if (count($upgraded) > 0) {
                // Only log and create activity if there was work to be done
                $upgradeTargets = count($upgraded) . ' test run(s) [' . implode(', ', $upgraded) . ']';
                $event->setParam(
                    'activity',
                    (new Activity())->set(
                        [
                            'action' => self::UPGRADED_ACTION,
                            'type' => 'testRun',
                            'user' => $p4Admin->getUser(),
                            'target' => $upgradeTargets
                        ]
                    )
                );
                $logger->info(sprintf("%s Upgraded %s", $logPrefix, $upgradeTargets));
            }
        } catch (Exception $e) {
            $logger->err($e->getMessage());
        }
    }

    /**
     * Take the branches field from a testrun and build it into an array of projects:[branches] that
     * can be used to filter activities against private projects.
     * @param $branches
     * @return array
     */
    protected function getProjectList($branches)
    {
        $projectList = [];
        foreach (explode(",", $branches) as $branch) {
            $parts = explode(":", $branch);
            if (!isset($projectList[$parts[0]])) {
                $projectList[$parts[0]] = [];
            }
            if ($parts[1]??false) {
                $projectList[$parts[0]][] = $parts[1];
            }
        }
        return $projectList;
    }
}
