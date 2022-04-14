<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Model;

use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Application\Log\SwarmLogger;
use Application\Model\AbstractDAO;
use Exception;
use P4\Command\IDescribe;
use P4\Connection\ConnectionInterface;
use P4\File\Exception\NotFoundException;
use P4\File\File;
use Reviews\Filter\IFileReadUnRead;
use InvalidArgumentException;
use Record\Exception\NotFoundException as RecordNotFoundException;

class FileInfoDAO extends AbstractDAO
{
    // The Perforce class that handles review
    const MODEL           = FileInfo::class;
    const FETCH_BY_REVIEW = 'review';

    /**
     * @inheritDoc
     * @throws RecordNotFoundException
     */
    public function fetchById($id, ConnectionInterface $connection = null)
    {
        return FileInfo::fetch($id, $connection);
    }

    /**
     * Mark the review file as read or unread
     * @param array $options options
     *              $options[IReview::FIELD_ID] = id of the review
     *              $options[IFileReadUnRead::VERSION] = version of the review
     *              $options[IFileReadUnRead::PATH] = file path
     *              $options[IFileReadUnRead::READ] or $options[IFileReadUnRead::UNREAD] = for read or unread operation
     *
     * @param ConnectionInterface|null $connection connection to use, defaults to admin connection if not provided
     * @return array
     */
    public function markReviewFileReadOrUnRead(array $options = [], ConnectionInterface $connection = null): array
    {
        $p4User     = $this->services->get(ConnectionFactory::P4_USER);
        $connection = $connection ?? $this->services->get(ConnectionFactory::P4_ADMIN);
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        $userName   = $p4User->getUser();

        // check if review exists and user has permission to access the review
        $reviewDAO = $this->services->get(IDao::REVIEW_DAO);
        $review    = $reviewDAO->fetch($options[IReview::FIELD_ID], $connection);

        // check if passed version belongs to the review
        try {
            $change = $review->getChangeOfVersion($options[IFileReadUnRead::VERSION], true);
        } catch (\Exception $e) {
            throw new InvalidArgumentException($translator->t($e->getMessage()));
        }

        // check if file path exists
        try {
            $file = trim($options[IFileReadUnRead::PATH], '/');
            $file = strlen($file) ? '//' . $file : null;
            $file = File::fetch($file ? $file . '@=' . $change : null, $p4User);
        } catch (NotFoundException $e) {
            throw new InvalidArgumentException($translator->t($e->getMessage()));
        }

        //  we are good to update the record
        //  if the record doesn't exist yet, make one
        $id     = FileInfo::composeId($review->getId(), $file->getDepotFilename());
        $logger = $this->services->get(SwarmLogger::SERVICE);
        try {
            $fileInfo = $this->fetchById($id, $connection);
        } catch (RecordNotFoundException $e) {
            $fileInfo = new FileInfo($connection);
            $fileInfo->set(FileInfo::FETCH_BY_REVIEW, $review->getId())
                ->set(IDescribe::DEPOT_FILE, $file->getDepotFilename());
            $logger->debug(
                sprintf(
                    "File %s exists but no FileInfo record. Creating a FileInfo record.",
                    $options[IFileReadUnRead::PATH]
                )
            );
        }

        if (isset($options[IFileReadUnRead::READ]) && $options[IFileReadUnRead::READ]) {
            // use digest if we have one and it is current
            $digest = $file->hasField(IDescribe::DIGEST) && $file->get('headChange') == $change
                ? $file->get(IDescribe::DIGEST)
                : null;

            $fileInfo->markReadBy($userName, $options[IFileReadUnRead::VERSION], $digest);
        } elseif (isset($options[IFileReadUnRead::UNREAD]) && $options[IFileReadUnRead::UNREAD]) {
            $fileInfo->clearReadBy($userName);
        }

        $fileInfo->save();

        // returning the required response
        return [
            IFileReadUnRead::VERSION => $options[IFileReadUnRead::VERSION],
            IFileReadUnRead::PATH => $options[IFileReadUnRead::PATH]
        ];
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function fetchAll($options = [], ConnectionInterface $connection = null)
    {
        $reviewId   = $options[self::FETCH_BY_REVIEW];
        $p4User     = $this->services->get(ConnectionFactory::P4_USER);
        $connection = $connection ?? $this->services->get(ConnectionFactory::P4_ADMIN);
        $reviewDAO  = $this->services->get(IDao::REVIEW_DAO);
        $user       = $p4User->getUser();
        $review     = $reviewDAO->fetch($reviewId, $connection);
        $filesInfo  = FileInfo::fetchAllByReview($review, $connection);

        $filesInfo->filterByCallback(
            function (FileInfo $fileInfo) use ($user) {
                $read = $fileInfo->isReadBy($user, null, null);
                if ($read) {
                    $fileInfo->setReadBy([ $user => $fileInfo->getReadBy()[$user] ]);
                }
                return $read;
            }
        );

        return $filesInfo;
    }
}
