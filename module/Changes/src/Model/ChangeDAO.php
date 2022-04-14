<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Changes\Model;

use Api\IRequest;
use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Application\Model\AbstractDAO;
use Application\Permissions\IpProtects;
use Application\Permissions\Protections;
use P4\Connection\ConnectionInterface as Connection;
use P4\Spec\Change;
use P4\Exception;
use P4\Spec\Exception\NotFoundException;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Stream;
use Reviews\Filter\IVersion;
use Reviews\Model\Review;
use Users\Model\User;
use P4\Command\IDescribe;
use Application\Config\ConfigException;
use P4\File\File;
use P4\Connection\Exception\CommandException;

/**
 * Class ChangeDAO to handle access to Perforce change data
 * @package Changes\Model
 */
class ChangeDAO extends AbstractDAO
{
    // The Perforce class that handles review
    const MODEL = Change::class;

    /**
     * Updates the description of a review's original changelist to match the review's new
     * description only if all of the following conditions are met:
     * - It is flagged for update
     * - The description has changed
     * - The editor is also the owner of the original changelist
     * - It is a pre-commit review
     * @param array         $data           list with field-value pairs to change in $review record
     * @param Review        $review         review
     * @param string        $description    new description for the original changelist
     * @param User          $editor         editor of the review's description
     * @param Connection $p4
     * @throws Exception
     */
    // Created from Reviews->IndexController->updateOriginalChangelist
    public function updateOriginalChangelist(array $data, Review $review, $description, User $editor, Connection $p4)
    {
        // It's not flagged for update or it's not a pre-commit review
        if (!isset($data['updateOriginalChangelist']) || !$review->isPending()) {
            return;
        }
        try {
            $originalChange = Change::fetchById($review->getChanges()[0], $p4);
        } catch (SpecNotFoundException $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->trace($e->getMessage());
            return;
        }
        // The description has not changed or the editor is not the owner of the original changelist
        if ($originalChange->getDescription() == $description || $originalChange->getUser() != $editor->getId()) {
            return;
        }
        // No conditions were violated, so we are free to update
        $originalChange = $originalChange->setDescription($description);
        $this->save($originalChange, true);
    }

    /**
     * Save the change
     * @param mixed     $model  the Perforce model class to save
     * @param bool      $force  whether to try and force the save, false by default. true will result in the '-f' flag
     *                          being used if admin or the '-u' flag being used if non-admin
     * @return mixed|void
     * @see Change::save()
     */
    public function save($model, $force = false)
    {
        return $model->save($force);
    }

    /**
     * List files affected by the given change or between two changes.
     *
     * The intent is to show the work the author did in a review at a version or
     * between two versions. If one change is given, it is easy. We simply ask
     * the server to 'describe' the change. If two changes are given, it is hard.
     *
     * It is hard because the server can't tell us. We need to collect the list
     * of files affected by either change, analyze the file actions in each change
     * and produce information we can use to show just the diffs introduced between
     * those changes.
     * @param mixed $fromChangeId                 optional - an older change id
     * @param mixed $toChangeId                   the primary (newer) change id
     * @param Connection|null $connection   connection to determine case sensitivity
     * @return array list of affected files with describe-like information
     * @throws ConfigException
     */
    // This function was built from Reviews\Controller\IndexController->getAffectedFiles
    // It is not the neatest implementation but we do not want to refactor this crucial
    // functionality other that using from/to instead of left/right
    public function getFileChanges($fromChangeId, $toChangeId, Connection $connection = null)
    {
        $fileChanges = [];
        $connection  = $connection ? $connection : $this->services->get(ConnectionFactory::P4);
        $maxFiles    = ConfigManager::getValue(
            $this->services->get(ConnectionFactory::P4_CONFIG),
            ConfigManager::MAX_CHANGELIST_FILES, 1000
        );
        $fromChange  = $fromChangeId ? $this->fetchById($fromChangeId, $connection) : null;
        $toChange    = $this->fetchById($toChangeId, $connection);
        if ($fromChange) {
            $isCaseSensitive = $connection->isCaseSensitive();
            foreach ([$fromChange, $toChange] as $i => $change) {
                foreach ($change->getFileData(true, $maxFiles) as $file) {
                    $depotFile    = $isCaseSensitive
                        ? $file[IDescribe::DEPOT_FILE] : strtolower($file[IDescribe::DEPOT_FILE]);
                    $fileChanges += [$depotFile => [IVersion::FROM => null, IVersion::TO => null]];
                    $file        += [IDescribe::DIGEST => null, IDescribe::FILE_SIZE => null];

                    $fileChanges[$depotFile][$i === 0 ? IVersion::FROM : IVersion::TO] = $file;
                }
            }
            // we need to resort filesPaths after we build the affected array
            // in order to match the ordering we would get from getFileData
            ksort($fileChanges, SORT_STRING);
            // because we merged files from two different changes, we need to re-apply max
            // otherwise we could end up returning more files than the caller requested
            array_splice($fileChanges, $maxFiles);
            foreach ($fileChanges as $depotFile => $file) {
                $action = null;
                // work out if we are dealing with a stream spec.
                $isStream = ($file[IVersion::FROM][IDescribe::TYPE] ?? false) === Stream::SPEC_TYPE
                    || ($file[IVersion::TO][IDescribe::TYPE] ?? false) === Stream::SPEC_TYPE;

                // for most cases, we diff using '#rev' for submits and '@=change' for shelves
                $diffFrom = $fromChange->isSubmitted()
                    ? (isset($file[IVersion::FROM])
                        ? ($isStream ? '@' . $fromChange->getId()
                        : '#' . $file[IVersion::FROM][IDescribe::REV]) : null)
                    : '@=' . $fromChange->getId();
                $diffTo   = $toChange->isSubmitted()
                    ? (isset($file[IVersion::TO])
                        ? ($isStream ? '@' . $toChange->getId()
                        : '#' . $file[IVersion::TO][IDescribe::REV]) : null)
                    : '@=' . $toChange->getId();

                $isFromDelete = isset($file[IVersion::FROM][IDescribe::ACTION])
                    && File::isDeleteAction($file[IVersion::FROM][IDescribe::ACTION]);
                $isToDelete   = isset($file[IVersion::TO][IDescribe::ACTION])
                    && File::isDeleteAction($file[IVersion::TO][IDescribe::ACTION]);
                // handle the cases where we have both a from and a to
                if (!$isStream && isset($file[IVersion::FROM], $file[IVersion::TO])) {
                    // check the digests - if they match and action doesn't involve delete, drop the file
                    if ($file[IVersion::FROM][IDescribe::DIGEST] == $file[IVersion::TO][IDescribe::DIGEST]
                        && !$isFromDelete && !$isToDelete
                    ) {
                        unset($fileChanges[$depotFile]);
                        continue;
                    } else {
                        // both deletes    = no diff, drop the file
                        // delete on from  = add
                        // delete on to    = delete
                        // add/edit combo  = edit
                        if ($isFromDelete && $isToDelete) {
                            unset($fileChanges[$depotFile]);
                        } elseif ($isFromDelete) {
                            $action = File::ADD;
                        } elseif ($isToDelete) {
                            $action = File::DELETE;
                        } else {
                            $action = File::EDIT;
                        }
                    }
                }

                if (!isset($file[IVersion::TO])) {
                    // Only 'from'
                    // if from hand change was committed, just drop the file
                    // (the fact it's missing on the to means it's unchanged)
                    if ($fromChange->isSubmitted()) {
                        unset($fileChanges[$depotFile]);
                    } else {
                        // since the from change is shelved, the absence of a file on
                        // the to means whatever was done on the from, has been undone
                        // therefore, we 'flip' the diff around
                        // add    = delete
                        // edit   = edit (edits undone)
                        // delete = add
                        if (File::isAddAction($file[IVersion::FROM][IDescribe::ACTION])) {
                            $action = File::DELETE;
                            $diffTo = null;
                        } elseif (File::isEditAction($file[IVersion::FROM][IDescribe::ACTION])) {
                            // edits going away, put the have-rev on the to
                            $action = File::EDIT;
                            $diffTo = '#' . $file[IVersion::FROM][IDescribe::REV];
                        } else {
                            // file coming back, put have-rev on the to and clear the from
                            $action   = File::ADD;
                            $diffTo   = '#' . $file[IVersion::FROM][IDescribe::REV];
                            $diffFrom = null;
                        }
                    }
                }
                // file only present on to
                // if file is added, clear diff-from (nothing to diff against)
                // otherwise diff against have-rev for shelves and previous for commits
                if (!isset($file[IVersion::FROM])) {
                    if (File::isAddAction($file[IVersion::TO][IDescribe::ACTION])) {
                        $diffFrom = null;
                    } else {
                        $diffFrom = $toChange->isSubmitted()
                            ? '#' . ($file[IVersion::TO][IDescribe::REV] - 1)
                            : '#' . $file[IVersion::TO][IDescribe::REV];
                    }
                }
                // handled notice, coming while to is committed and no action and rev is
                // set in the $file[IVersion::TO] for streams. This notice only appear in Unit test case
                if (isset($file[IVersion::TO]) && $file[IVersion::TO][IDescribe::TYPE] === Stream::SPEC_TYPE
                    && !isset($file[IVersion::TO][IDescribe::ACTION])) {
                    $file[IVersion::TO][IDescribe::ACTION] = File::EDIT;
                }
                if (isset($file[IVersion::TO]) && $file[IVersion::TO][IDescribe::TYPE] === Stream::SPEC_TYPE
                    && !isset($file[IVersion::TO][IDescribe::REV])) {
                    $file[IVersion::TO][IDescribe::REV] = null;
                }

                // action should default to the action of the to file
                $action = $action ?: ($file[IVersion::TO] ? $file[IVersion::TO][IDescribe::ACTION] : null);

                // type should default to the to file, but fallback to the from
                $type = $file[IVersion::TO]
                    ? $file[IVersion::TO][IDescribe::TYPE]
                    : (isset($file[IVersion::FROM][IDescribe::TYPE]) ? $file[IVersion::FROM][IDescribe::TYPE] : null);
                // compose the file information to keep and return to the caller
                // we start with basic 'describe' output, and add in some useful bits
                // if we don't have a to-side, then we can't populate certain fields
                $file                    = [
                    IDescribe::DEPOT_FILE => $file[($file[IVersion::TO]
                        ? IVersion::TO : IVersion::FROM)][IDescribe::DEPOT_FILE],
                    IDescribe::ACTION     => $action,
                    IDescribe::TYPE       => $type,
                    IDescribe::REV        => $file[IVersion::TO] ? $file[IVersion::TO][IDescribe::REV]       : null,
                    IDescribe::FILE_SIZE  => $file[IVersion::TO] ? $file[IVersion::TO][IDescribe::FILE_SIZE] : null,
                    IDescribe::DIGEST     => $file[IVersion::TO] ? $file[IVersion::TO][IDescribe::DIGEST]    : null,
                    IDescribe::DIFF_FROM  => $diffFrom,
                    IDescribe::DIFF_TO    => $diffTo
                ];
                $fileChanges[$depotFile] = $file;
            }
        } else {
            $fileChanges = $toChange->getFileData(true, $maxFiles);
            foreach ($fileChanges as $key => $value) {
                if ($value['type'] === Stream::SPEC_TYPE && !isset($value['action'])) {
                    $value['action']   = "edit";
                    $fileChanges[$key] = $value;
                }
            }
        }

        // filter files to comply with user's IP-based protections
        $ipProtects  = $this->services->get(IpProtects::IP_PROTECTS);
        $fileChanges = $ipProtects->filterPaths($fileChanges, Protections::MODE_LIST, IDescribe::DEPOT_FILE);
        return [
            IRequest::FILE_CHANGES => $fileChanges,
            IRequest::ROOT         => $this->getRootFilePath($fileChanges, $connection),
            IRequest::LIMITED      => sizeof($fileChanges) === $maxFiles
        ];
    }

    /**
     * Build a common root path (taking into account connection case sensitivity) based
     * on the IDescribe::DEPOT_FILE in each file change
     * @param mixed         $fileChanges    the file changes
     * @param Connection    $connection     the connection
     * @return string
     */
    public function getRootFilePath($fileChanges, Connection $connection)
    {
        $root = '';
        if ($fileChanges) {
            $last    = end($fileChanges);
            $first   = reset($fileChanges);
            $length  = min(strlen($first[IDescribe::DEPOT_FILE]), strlen($last[IDescribe::DEPOT_FILE]));
            $compare = $connection->isCaseSensitive() ? 'strcmp' : 'strcasecmp';
            for ($i = 0; $i < $length; $i++) {
                if ($compare($first[IDescribe::DEPOT_FILE][$i], $last[IDescribe::DEPOT_FILE][$i]) !== 0) {
                    break;
                }
            }
            $root = substr($first[IDescribe::DEPOT_FILE], 0, $i);
            $root = substr($root, 0, strrpos($root, '/'));
        }
        return $root === '/' ? '//' : $root;
    }

    /**
     * Add or remove job from a change
     * @param mixed     $changeId       the change id
     * @param mixed     $jobId          the job id
     * @param string    $mode           the mode, either 'add' or 'remove'
     * @return void
     * @throws CommandException if the 'fixes' command fails
     * @throws NotFoundException if the change cannot be found
     */
    private function addRemoveJob($changeId, $jobId, string $mode)
    {
        $p4 = $this->services->get(ConnectionFactory::P4);
        // Call the fetch to ensure the change id is valid
        $this->fetchById($changeId, $p4);
        $flags = array_merge(
            $mode === 'remove' ? ['-d'] : [],
            ['-c', $changeId],
            (array) $jobId
        );
        $p4->run('fix', $flags);
    }

    /**
     * Add a job to a change
     * @param mixed     $changeId       the change id
     * @param mixed     $jobId          the job id
     * @return void
     * @throws CommandException if the 'fixes' command fails
     * @throws NotFoundException if the change cannot be found
     */
    public function addJob($changeId, $jobId)
    {
        $this->addRemoveJob($changeId, $jobId, "add");
    }

    /**
     * Remove a job from a change
     * @param mixed     $changeId       the change id
     * @param mixed     $jobId          the job id
     * @return void
     * @throws CommandException if the 'fixes' command fails
     * @throws NotFoundException if the change cannot be found
     */
    public function removeJob($changeId, $jobId)
    {
        $this->addRemoveJob($changeId, $jobId, "remove");
    }
}
