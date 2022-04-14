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
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Application\Service\P4Command;
use Interop\Container\ContainerInterface;
use P4\Command\IDescribe;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\OutputHandler\Limit;
use P4\Spec\Change as ChangeSpec;

/**
 * Class ChangeComparator
 * @package Changes\Service
 */
class ChangeComparator implements InvokableService, IChangeComparator, IDescribe
{
    const HEAD_TYPE    = 'headType';
    const RESOLVED     = 'resolved';
    const UNRESOLVED   = 'unresolved';
    const HEAD_REV     = 'headRev';
    const HEAD_ACTION  = 'headAction';
    const STREAM_FIELD = 'stream';
    const IDENTICAL    = 'identical';
    const STATUS       = 'status';
    const CONTENT      = 'content';

    const COMPARE_FIELDS = [
        self::DEPOT_FILE,
        self::HEAD_ACTION,
        self::HEAD_TYPE,
        self::HEAD_REV,
        self::RESOLVED,
        self::UNRESOLVED,
        self::DIGEST
    ];

    private $services;
    private $changeDAO;
    private $changeService;
    private $fileService;
    private $logger;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services      = $services;
        $this->changeDAO     = $services->get(IModelDAO::CHANGE_DAO);
        $this->changeService = $services->get(Services::CHANGE_SERVICE);
        $this->fileService   = $services->get(Services::FILE_SERVICE);
        $this->logger        = $services->get(SwarmLogger::SERVICE);
    }

    /**
     * @inheritDoc
     */
    public function compare($a, $b, ConnectionInterface $connection)
    {
        $a   = $a instanceof ChangeSpec ? $a : $this->changeDAO->fetchById($a, $connection);
        $b   = $b instanceof ChangeSpec ? $b : $this->changeDAO->fetchById($b, $connection);
        $aId = $a->getId();
        $bId = $b->getId();

        $compareFields = implode(',', self::COMPARE_FIELDS);
        $flags         = [
            '-Ol',  // include digests
            '-T',   // only the fields we want:
            $compareFields
        ];
        // add '-Rs' flag for pending changes
        $flagsA = array_merge($a->isPending() ? ['-Rs'] : [], $flags);
        $flagsB = array_merge($b->isPending() ? ['-Rs'] : [], $flags);
        $a      = $this->fileService->fstat(
            $connection,
            [P4Command::COMMAND_FLAGS => array_merge(['-e', $a->getId()], $flagsA)],
            '//...@=' . $a->getId()
        );
        $b      = $this->fileService->fstat(
            $connection,
            [P4Command::COMMAND_FLAGS => array_merge(['-e', $b->getId()], $flagsB)],
            '//...@=' . $b->getId()
        );
        // remove trailing change descriptions - we don't care if they differ
        $a = $a->getData(-1, 'desc') !== false ? array_slice($a->getData(), 0, -1) : $a->getData();
        $b = $b->getData(-1, 'desc') !== false ? array_slice($b->getData(), 0, -1) : $b->getData();
        if ($a == $b) {
            return self::NO_DIFFERENCE;
        }
        // the fstat reported digests for ktext files are not what we want.
        // they are based on the text with keywords expanded which is apt to harmlessly flux.
        // if it looks worthwhile, we want to recalculate md5s without expansion.
        if ($this->shouldFixDigests($a, $b)) {
            $a = $this->fixKeywordExpandedDigests($a, $aId, $connection);
            $b = $this->fixKeywordExpandedDigests($b, $bId, $connection);
        }
        // our ktext related md5 updates may have cleared the difference; if so we're done!
        if ($a == $b) {
            return self::NO_DIFFERENCE;
        }
        // screen down to only the 'major' difference fields
        $whitelist = [self::DEPOT_FILE => null, self::HEAD_TYPE => null, self::DIGEST => null];
        foreach ($a as $block => $data) {
            $a[$block] = array_intersect_key($data, $whitelist);
        }
        foreach ($b as $block => $data) {
            $b[$block] = array_intersect_key($data, $whitelist);
        }
        // if the data are same now, it means that differences must have been within
        // action, revs or resolved/unresolved; otherwise changes must differ in other fields
        return $a == $b ? self::OTHER_DIFFERENCE : self::DIFFERENCE;
    }

    /**
     * @inheritDoc
     */
    public function compareStreamSpec($a, $b, ConnectionInterface $connection)
    {
        $diffOutput = null;
        $a          = $a instanceof ChangeSpec ? $a : $this->changeDAO->fetchById($a, $connection);
        $b          = $b instanceof ChangeSpec ? $b : $this->changeDAO->fetchById($b, $connection);
        $aId        = $a->getId();
        $bId        = $b->getId();

        // add '-S' flag for pending changes
        $aFlags = $a->isPending() ? ['-S'] : [];
        $bFlags = $b->isPending() ? ['-S'] : [];
        // Get the output from the describe command and we are only interested in the first data.
        $aDescribe = $this->changeService->describe(
            $connection,
            [P4Command::COMMAND_FLAGS => $aFlags],
            $a
        )->getData(0);
        $bDescribe = $this->changeService->describe(
            $connection,
            [P4Command::COMMAND_FLAGS => $bFlags],
            $b
        )->getData(0);
        // Get the stream name from the changelist if present.
        $aStream = isset($aDescribe[self::STREAM_FIELD]) ? $aDescribe[self::STREAM_FIELD] : null;
        $bStream = isset($bDescribe[self::STREAM_FIELD]) ? $bDescribe[self::STREAM_FIELD] : null;
        // If both do not have a stream spec no difference here.
        if (!$aStream && !$bStream) {
            return self::NO_DIFFERENCE;
        }
        // If one has a stream spec but other doesn't we have a difference.
        // If the spec are not the same name we have different stream spec.
        if (($aStream && !$bStream) || (!$aStream && $bStream) || ($aStream !== $bStream)) {
            return self::DIFFERENCE;
        }
        // Build up the diff2 argument to be ran.
        $options = [P4Command::COMMAND_FLAGS => ['-As']];
        $paths   = [$aStream.'@='.$aId, $bStream.'@='.$bId];
        try {
            // Get the diff2 results and only get the first data.
            $diffOutput = $this->fileService->diff2($connection, $options, $paths)->getData(0);
            // If the string returned only has this then we can assume no differences and we should
            // just return 0 for no differences.
            if (isset($diffOutput[self::STATUS]) && $diffOutput[self::STATUS] === self::IDENTICAL) {
                return self::NO_DIFFERENCE;
            }
        } catch (CommandException $error) {
            // Currently ignore the error as we don't need to do anything with it.
            $diffError = $error->getMessage();
            $this->logger->trace(get_class($this) . ": compareStreamSpec command error [$diffError]");
            // As we have failed on diff2 command, return 0.
            return self::NO_DIFFERENCE;
        }
        // If they are the same return 0 for no difference else return 1.
        return $diffOutput ? self::DIFFERENCE : self::NO_DIFFERENCE;
    }

    /**
     * This is a helper method for changesDiffer. We determine if touching up keyword expanded
     * digests is worthwhile.
     *
     * @param   array   $a  fstat output with list of files to potentially update for old change
     * @param   array   $b  fstat output with list of files to potentially update for new change
     * @return  bool    true if calling fixKeywordExpandedDigests is likely worthwhile, false otherwise
     */
    protected function shouldFixDigests($a, $b)
    {
        // differing counts means changesDiffer will always report 1; no need to fix digests
        if (count($a) != count($b)) {
            return false;
        }
        // index all 'b' blocks by depotFile so we can correlate them later
        $bByFile = [];
        foreach ($b as $key => $block) {
            if (isset($block[self::DEPOT_FILE])) {
                $bByFile[$block[self::DEPOT_FILE]] = $block;
            }
        }
        $hasKtext  = false;
        $normalize = [self::DEPOT_FILE => null, self::DIGEST => null, self::HEAD_TYPE => null];
        foreach ($a as $blockA) {
            // if the 'b' set doesn't include this file, no need to fix digests
            $blockA += $normalize;
            $file    = $blockA[self::DEPOT_FILE];
            if (!isset($bByFile[$file])) {
                return false;
            }
            $blockB = $bByFile[$file] + $normalize;
            // if type has changed on any file, no need to fix digests
            if ($blockA[self::HEAD_TYPE] != $blockB[self::HEAD_TYPE]) {
                return false;
            }
            // if a single non-ktext file has a changed digest, no need to fixup
            $isKtext = preg_match('/kx?text|.+\+.*k/i', $blockA[self::HEAD_TYPE]);
            if (!$isKtext && $blockA[self::DIGEST] != $blockB[self::DIGEST]) {
                return false;
            }
            // track if we've hit any ktext files
            $hasKtext = $hasKtext || $isKtext;
        }
        // if we made it this far, fixing ktext digests is likely worthwhile if we've seen any
        return $hasKtext;
    }

    /**
     * This is a helper method for changesDiffer. We get passed in the fstat output for one
     * of the changes being examined and locate any ktext files located in it. We then print
     * all of the ktext files and recalculate the md5 values with the keywords not expanded.
     * This will allow the changes differ method to tell if the ktext files fundamentally
     * differ (as opposed to simply differ in the expanded keywords).
     *
     * @param array               $blocks      fstat output with list of files to potentially update
     * @param int                 $changeId    change id to use for revspec when printing files
     * @param ConnectionInterface $connection  The P4 connection
     * @return  array   the provided blocks array with ktext digests updated
     */
    protected function fixKeywordExpandedDigests($blocks, $changeId, ConnectionInterface $connection)
    {
        // first collect the key and depotPath for all ktext entries and a list of filespecs with revspec
        $ktexts    = [];
        $filespecs = [];
        foreach ($blocks as $block => $data) {
            // note ktext filetypes include things like: ktext, text+ko, text+mko, kxtext, etc.
            if (isset($data[self::HEAD_TYPE], $data[self::DEPOT_FILE])
                && preg_match('/kx?text|.+\+.*k/i', $data[self::HEAD_TYPE])) {
                $file          = $data[self::DEPOT_FILE];
                $ktexts[$file] = $block;
                $filespecs[]   = $file . '@=' . $changeId;
            }
        }
        // if we didn't detect any ktext files we need to update, we're done!
        if (!$filespecs) {
            return $blocks;
        }
        // now setup an output handler to process the print output for all ktext files (with keywords unexpanded)
        // and do a streaming calculation of the md5 for all ktext files
        $file    = null;
        $hash    = null;
        $handler = new Limit;
        $handler->setOutputCallback(
            function ($data, $type) use (&$blocks, &$file, &$hash, $ktexts) {
                // if its an array with depotFile; we're swapping files
                if (is_array($data) && isset($data[self::DEPOT_FILE])) {
                    // if we were already on a file, finalize its hash update
                    if ($file !== null) {
                        $blocks[$ktexts[$file]][self::DIGEST] = hash_final($hash);
                    }
                    // record the new file we're on and (re)init the streaming hash
                    $file = $data[self::DEPOT_FILE];
                    $hash = hash_init('md5');
                    return Limit::HANDLER_HANDLED;
                }
                // if we have an unexpected type, skip it
                if ($type !== 'text' && $type !== 'binary') {
                    return Limit::HANDLER_HANDLED;
                }
                // update the hash with our new block of content
                hash_update($hash, $data);
                return Limit::HANDLER_HANDLED;
            }
        );
        // print via our handler, note we pass -k to avoid expanding keywords
        // thanks to our output handler this will update the digest values in the $blocks array
        $connection->runHandler($handler, 'print', array_merge(['-k'], $filespecs));
        // we're likely to have a final file to wrap up the hash update on, do that
        if ($file) {
            $blocks[$ktexts[$file]][self::DIGEST] = hash_final($hash);
        }
        return $blocks;
    }
}
