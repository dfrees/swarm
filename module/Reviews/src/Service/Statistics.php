<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Service;

use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Application\Permissions\Exception\ForbiddenException;
use Files\Filter\Diff\IDiff;
use Interop\Container\ContainerInterface;
use P4\Command\IDescribe;
use P4\File\Diff;
use Reviews\Model\IReview;

/**
 * Class Statistics. Service to implement review statistics functions
 * @package Reviews\Service
 */
class Statistics implements IStatistics, InvokableService
{
    private $services;

    /**
     * Statistics constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     */
    public function buildComplexity()
    {
        return [
            IReview::FILES_MODIFIED => 0,
            IReview::LINES_ADDED    => 0,
            IReview::LINES_EDITED   => 0,
            IReview::LINES_DELETED  => 0
        ];
    }

    /**
     * @inheritDoc
     */
    public function calculateComplexity($reviewId)
    {
        $complexity = $this->buildComplexity();
        $logger     = $this->services->get(SwarmLogger::SERVICE);
        $p4Admin    = $this->services->get(ConnectionFactory::P4_ADMIN);
        $reviewDao  = $this->services->get(IDao::REVIEW_DAO);
        $changeDao  = $this->services->get(IDao::CHANGE_DAO);
        $fileDao    = $this->services->get(IDao::FILE_DAO);
        try {
            $review     = $reviewDao->fetch($reviewId, $p4Admin);
            $headChange = $review->getHeadChange();
            $change     = $changeDao->fetchById($headChange, $p4Admin);
            $files      = $change->getFileData(true);
            $isPending  = $change->isPending();
            $to         = $isPending ? "@=$headChange" : "@$headChange";

            $complexity[IReview::FILES_MODIFIED] = sizeof($files);
            foreach ($files as $file) {
                $rev      = ($isPending
                    ? $file[IDescribe::REV]
                    : ($file[IDescribe::REV] > 0 ? intval($file[IDescribe::REV]) - 1 : 'head')
                );
                $from     = $file[IDescribe::ACTION] === Diff::ADD
                    || $file[IDescribe::ACTION] === Diff::MOVE_ADD ? null : "#$rev";
                $fileName = $file[IDescribe::DEPOT_FILE];
                $diff     = $fileDao->diff(
                    $fileName,
                    [
                        Diff::TYPE => $file[Diff::TYPE],
                        IDiff::FROM => $from,
                        IDiff::TO => $to,
                        Diff::SUMMARY_LINES => true
                    ]
                );
                if (isset($diff[Diff::SUMMARY])) {
                    try {
                        $summary                            = $diff[Diff::SUMMARY];
                        $complexity[IReview::LINES_DELETED] =
                            $complexity[IReview::LINES_DELETED] + $summary[Diff::SUMMARY_DELETES];
                        $complexity[IReview::LINES_ADDED]   =
                            $complexity[IReview::LINES_ADDED] + $summary[Diff::SUMMARY_ADDS];
                        $complexity[IReview::LINES_EDITED]  =
                            $complexity[IReview::LINES_EDITED] + $summary[Diff::SUMMARY_UPDATES];
                    } catch (\Exception $e) {
                    }
                }
            }
            $review->setComplexity($complexity);
            $reviewDao->save($review);
        } catch (ForbiddenException $e) {
            $logger->trace(sprintf("%s: No permission for review [%s]", get_class($this), $reviewId));
        } catch (\Exception $e) {
            $logger->err(sprintf("%s: %s", get_class($this), $e->getMessage()));
        }
    }
}
