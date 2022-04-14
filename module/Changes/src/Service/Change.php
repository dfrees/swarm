<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Service;

use Application\Config\Services;
use Application\Config\IDao;
use Application\Service\P4Command;
use P4\Command\IDescribe;
use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface as Connection;
use P4\File\File;
use P4\Log\Logger;
use P4\Spec\Change as P4Change;

/**
 * Class Change
 * @package Changes\Service
 */
class Change extends P4Command implements IChange
{
    /**
     * @inheritDoc
     */
    public function describe(Connection $connection, array $options, P4Change $change)
    {
        return $this->run($connection, self::DESCRIBE_COMMAND, $options, [$change->getId()]);
    }

    /**
     * @inheritDoc
     */
    public function getFileList(Connection $connection, array $options, P4Change $change)
    {
        $inHeader     = true;
        $files        = [];
        $describeData = $this->describe($connection, $options, $change)->getData();
        foreach ($describeData as $data) {
            $data = trim($data, "\r\n");
            // if we are in the header check to see if we have hit the start
            // of the file list and return that we took care of this block.
            if ($inHeader) {
                if (!strlen($data) || $data === self::AFFECTED_FILES || $data === self::SHELVED_FILES) {
                    $inHeader = false;
                }
                continue;
            }
            $files[] = substr($data, 0, strrpos($data, self::FILE_LINE_PREFIX));
        }
        return $files;
    }

    /**
     * Get the stream for a given changelist if present.
     * @param Connection $connection The P4 connection.
     * @param P4Change   $change     The changelist we want to get the spec for.
     * @return string | null
     */
    public function getStream(Connection $connection, P4Change $change)
    {
        // Must run describe to get the stream spec data as the changelist doesn't have the stream
        // spec against it when it is shelved.
        $describe = $this->describe($connection, [self::COMMAND_FLAGS => ['-sS']], $change)->getData(0);
        return isset($describe[self::STREAM_FIELD]) ? $describe[self::STREAM_FIELD] : null;
    }

    /**
     * @inheritDoc
     */
    public function reopen(Connection $connection, array $options, P4Change $change)
    {
        // Changelist first then the flags followed by location.
        // p4 reopen [-c changelist#] [-t filetype] [-Si] file ... (2019.1 help output)
        $options = [
            P4Command::COMMAND_FLAGS => array_merge(
                ['-c', $change->getId()],
                isset($options[P4Command::COMMAND_FLAGS])? $options[P4Command::COMMAND_FLAGS]: [],
                isset($options[P4Command::INPUT]) ? $options[P4Command::INPUT] :[]
            )
        ];
        return $this->run($connection, self::REOPEN_COMMAND, $options);
    }

    /**
     * @inheritDoc
     */
    public function shelve(Connection $connection, array $options, P4Change $change)
    {
        // flags come before changelist then location lasted.
        // p4 shelve [-Af|-As] [-a option] [-p] -c changelist# [-f] [file ...] (2019.1 help output)
        $options = [
            P4Command::COMMAND_FLAGS => array_merge(
                isset($options[P4Command::COMMAND_FLAGS])? $options[P4Command::COMMAND_FLAGS]: [],
                ['-c', $change->getId()],
                isset($options[P4Command::INPUT]) ? $options[P4Command::INPUT] :[]
            )
        ];
        return $this->run($connection, self::SHELVE_COMMAND, $options);
    }

    /**
     * @inheritDoc
     */
    public function unshelve(Connection $connection, array $options, P4Change $change)
    {
        // flags come before changelist then location lasted.
        //  p4 unshelve -s changelist# [options] [file ...] (2019.1 help output)
        $options = [
            P4Command::COMMAND_FLAGS => array_merge(
                ['-s', $change->getId()],
                isset($options[P4Command::COMMAND_FLAGS])? $options[P4Command::COMMAND_FLAGS]: [],
                isset($options[P4Command::INPUT]) ? $options[P4Command::INPUT] :[]
            )
        ];
        return $this->run($connection, self::UNSHELVE_COMMAND, $options);
    }

    /**
     * @inheritDoc
     */
    public function hasContentChanged(Connection $connection, $changeId1, $changeId2)
    {
        $changeDao = $this->services->get(IDao::CHANGE_DAO);
        $change1   = $changeDao->fetchById($changeId1, $connection);
        $change2   = $changeDao->fetchById($changeId2, $connection);
        return $this->checkContent($connection, $change1, $change2);
    }

    /**
     * @inheritDoc
     */
    public function getCommonPath(Connection $connection, P4Change $change)
    {
        $case       = $connection->isCaseSensitive();
        $isPending  = $change->isPending();
        $depotPaths = array_map(
            function ($file) {
                return $file[IDescribe::DEPOT_FILE];
            },
            $change->getFileData($isPending)
        );
        $commonPath = File::getCommonPath($depotPaths, $case);
        $commonPath = $commonPath ? $commonPath  . '...' : '//...';
        return $commonPath;
    }

    /**
     * Run a command to determine differences between two change identifiers
     * @param Connection    $connection     connection to use
     * @param mixed         $change1        first change
     * @param mixed         $change2        second change
     * @return bool
     */
    private function checkContent(Connection $connection, $change1, $change2)
    {
        $fileService = $this->services->get(Services::FILE_SERVICE);
        $change1Stat = $fileService->fstat(
            $connection,
            $this->getContentChangedFlags($change1),
            $this->getCommonPath($connection, $change1)
        );
        $change2Stat = $fileService->fstat(
            $connection,
            $this->getContentChangedFlags($change2),
            $this->getCommonPath($connection, $change2)
        );

        return $this->hasFileDifferences($change1Stat, $change2Stat, $connection, $change1, $change2);
    }

    /**
     * Check if there is any difference in files between two change stat result
     * @param CommandResult $change1Stat
     * @param CommandResult $change2Stat
     * @param Connection    $connection     connection to use
     * @param mixed         $change1        first change
     * @param mixed         $change2        second change
     * @return bool
     */
    private function hasFileDifferences(
        CommandResult $change1Stat,
        CommandResult $change2Stat,
        Connection $connection,
        P4Change $change1,
        P4Change $change2
    ) {
        $data1 = $change1Stat->getData();
        $data2 = $change2Stat->getData();
        // We add array_pop so that the data we get back from the changelist removes the final ‘desc‘ element that is
        // not a file. This means the sizeof get an accurate total, meaning we can compare the sizes and know if the
        // are different earl and return without having to issue the diff command.
        array_pop($data1);
        array_pop($data2);
        $size1 = sizeof($data1);
        $size2 = sizeof($data2);
        // If the size of changelist 2 “the users changelist” is equal to zero, this could be that we are in the
        // commit phase of the submit. The files have been transferred to p4d and diff2 doesn’t return any results
        // about the files.
        // To get around this we issue a p4 describe against the users changelist “change2” and get the files from
        // that changelist. This is using the p4d db.have and db.working records. We use the array_pop to remove the
        // final element which is just a new line. This allows us to get a total count of files p4d expect the user
        // change to contain. We can then compare this with the review changelist. If the changelist contains fewer
        // or more files just report, there is a difference.
        if ($size1 > 0 || $size2 > 0) {
            if ($size2 === 0) {
                $descChange2 = $this->getFileList(
                    $connection,
                    [P4Command::COMMAND_FLAGS => [], P4Command::TAGGED => false],
                    $change2
                );
                array_pop($descChange2);
                if ($size1 !== count($descChange2)) {
                    Logger::log(
                        Logger::DEBUG,
                        "The changelist contains more files than the approved review.\n"
                    );
                    return true;
                }
            }
            // If the size of changelist 2 “users changelist” is greater than changelist 1 “the review” then return
            // true early to save us having to issue p4 diff on each file.
            if ($size2 > $size1) {
                Logger::log(
                    Logger::DEBUG,
                    "The changelist file count does not match the approved review file count.\n"
                );
                return true;
            }
            foreach ($data1 as $fileData1) {
                if (!isset($fileData1[IDescribe::DEPOT_FILE])) {
                    continue;
                }
                $fileData2 = $this->findFile($fileData1, $data2);
                if ($fileData2) {
                    Logger::log(
                        Logger::TRACE,
                        sprintf(
                            "Comparing [%s] and [%s]\n",
                            $fileData1[IDescribe::DEPOT_FILE],
                            $fileData2[IDescribe::DEPOT_FILE]
                        )
                    );
                    if ((isset($fileData1[IDescribe::DIGEST]) && isset($fileData2[IDescribe::DIGEST]))
                        &&
                        ($fileData1[IDescribe::DIGEST] !== $fileData2[IDescribe::DIGEST])
                    ) {
                        $changed = true;
                        Logger::log(Logger::DEBUG, "File digest is different\n");
                        // Digests differ so we would ordinarily treat this as changed. However for pending changes
                        // that have ktext content the shelf in one change list could contain expanded RCS content
                        // meaning that a change is incorrectly detected (SW-7901). In this instance we double check
                        // with diff2, unless the change list sizes do not agree
                        $fileService = $this->services->get(Services::FILE_SERVICE);
                        $bothPending = $change1->isPending() && $change2->isPending();
                        if ($fileService->isKText($fileData1)
                            && $fileService->isKText($fileData2)
                            && $bothPending
                            && $size1 === $size2) {
                            $diffResult = $this->getDiff(
                                $connection,
                                $change1,
                                $change2,
                                $fileData1,
                                $fileData2
                            );
                            if (empty($diffResult)) {
                                Logger::log(
                                    Logger::DEBUG,
                                    "File digest was different but diff indicates no change (likely ktext expansion)\n"
                                );
                                $changed = false;
                            }
                        }
                        return $changed;
                    } else {
                        // digest matched or
                        // digest not found, add scenario
                        Logger::log(
                            Logger::DEBUG,
                            "Digest not found or Digest matched, Considering new file\n"
                        );
                        $diffResult = $this->getDiff(
                            $connection,
                            $change1,
                            $change2,
                            $fileData1,
                            $fileData2
                        );
                        if (count($diffResult)) {
                            foreach ($diffResult as $file) {
                                if (isset($file[ChangeComparator::STATUS])
                                    && $file[ChangeComparator::STATUS] !== ChangeComparator::IDENTICAL) {
                                    Logger::log(Logger::DEBUG, "diff2 command finds files not identical\n");
                                    return true;
                                }
                            }
                        }
                    }
                } else {
                    if ($size2 === 0) {
                        // change2 don't contains any file
                        Logger::log(Logger::DEBUG, "Size2 is zero\n");
                        $diffResult = $this->getDiff(
                            $connection,
                            $change1,
                            $change2,
                            $fileData1,
                            $fileData1
                        );
                        foreach ($diffResult as $file) {
                            if (isset($file[ChangeComparator::STATUS])
                                && $file[ChangeComparator::STATUS] !== ChangeComparator::IDENTICAL) {
                                Logger::log(
                                    Logger::DEBUG,
                                    "Size2 is zero and still content change. Return true\n"
                                );
                                return true;
                            }
                        }
                        Logger::log(Logger::DEBUG, "Size2 is zero, diff had no further info.\n");
                    } else {
                        Logger::log(Logger::DEBUG, "Size2 is less then Size1. Return true\n");
                        return true;
                    }
                }
            }
        } else {
            // both change don't contains any files
            Logger::log(Logger::DEBUG, "No file change. Return False\n");
            return false;
        }
        Logger::log(Logger::DEBUG, "Return False by default\n");
        return false;
    }

    /**
     * Find the file in second change stat data
     * @param $file
     * @param $fileData
     * @return mixed|null
     */
    protected function findFile($file, $fileData)
    {
        foreach ($fileData as $data) {
            if (isset($file[IDescribe::DEPOT_FILE]) && isset($data[IDescribe::DEPOT_FILE])) {
                if ($file[IDescribe::DEPOT_FILE] === $data[IDescribe::DEPOT_FILE]) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * Get required flags for command
     * @param P4Change $change
     * @return array[]
     */
    protected function getContentChangedFlags(P4Change $change)
    {
        $flags = [P4Command::COMMAND_FLAGS => ['-e', $change->getId(), "-Ol"]];
        if ($change->isPending()) {
            array_unshift($flags[P4Command::COMMAND_FLAGS], '-Rs');
        }
        return $flags;
    }

    /**
     * Get diff to check the content change
     * @param Connection $connection
     * @param P4Change $change1
     * @param P4Change $change2
     * @param $fileData1
     * @param $fileData2
     * @return mixed
     */
    protected function getDiff(
        Connection $connection,
        P4Change $change1,
        P4Change $change2,
        $fileData1,
        $fileData2
    ) {

        $id1         = $change1->getId();
        $id2         = $change2->getId();
        $path1Append = $change1->isPending() ? '@='.$id1 : '@'.$id1;
        $path1       = $fileData1[IDescribe::DEPOT_FILE].$path1Append;
        $path2Append = $change2->isPending() ? '@='.$id2 : '@'.$id2;
        $path2       = $fileData2[IDescribe::DEPOT_FILE].$path2Append;
        $fileService = $this->services->get(Services::FILE_SERVICE);
        return $fileService->diff2(
            $connection,
            [
                P4Command::COMMAND_FLAGS => [self::QUIET_DIFF]
            ],
            [
                $path1,
                $path2
            ]
        )->getData();
    }
}
