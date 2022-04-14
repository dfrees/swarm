<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Model;

use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Helper\StringHelper;
use Application\Model\AbstractDAO;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\IpProtects;
use Application\Permissions\Protections;
use Exception;
use Files\Filter\IFile;
use Files\Filter\Diff\IDiff;
use P4\Connection\Connection;
use P4\Connection\ConnectionInterface;
use P4\Exception as P4Exception;
use P4\File\Diff;
use P4\File\Exception\Exception as FileException;
use P4\File\Exception\NotFoundException as FileNotFoundException;
use P4\File\File;
use P4\Filter\Utf8;
use P4\Spec\Change;
use P4\Spec\Depot;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use P4\Spec\Stream;

/**
 * Class FileDAO
 * @package Files\Model
 */
class FileDAO extends AbstractDAO
{
    const MODEL = File::class;

    // Defaults
    const DEFAULT_CONTEXT_LINES = 5; //used this in case of config exception for lines
    const DEFAULT_MAX_DIFFS     = 1000; //used this in case of config exception for maxDiffs
    const DEFAULT_MAX_SIZE      = 1048576; //1MB used this in case of config exception for maxSize
    const STREAM                = 'stream';
    const FILE                  = 'file';

    /**
     * Reads the full content of the file provided by the path
     * @param string        $path       full depot path to the file
     * @param mixed         $connection optional connection to use, defaults to current user
     * @return mixed file content
     * @throws ForbiddenException
     */
    public function read($path, ConnectionInterface $connection = null)
    {
        $this->checkPermission($path, Protections::MODE_READ);
        $fileService = $this->services->get(IConfigDefinition::DEPOT_STORAGE);
        if ($connection) {
            $fileService->setConnection($connection);
        }
        return $fileService->read($path);
    }

    /**
     * Update the content of the file provided by the path.
     * @param string        $path           full depot path to the file
     * @param mixed         $content        file contents to write
     * @param string        $description    description for the change
     * @param mixed         $options        options
     * @return FileUpdateResult
     * @throws ForbiddenException
     * @throws SpecNotFoundException
     */
    public function update(string $path, $content, string $description, $options = [])
    {
        $this->checkPermission($path, Protections::MODE_WRITE);
        $options += [
            IFile::ACTION => IFile::SUBMIT
        ];

        $editedFile  = null;
        $fileService = $this->services->get(IConfigDefinition::DEPOT_STORAGE);
        $connection  = $this->services->get(ConnectionFactory::P4);
        $fileService = $fileService->setConnection($connection);
        $stream      = $this->streamFromFile($path, $connection);
        $clientPool  = $connection->getService('clients');
        try {
            $change = null;
            $file   = $fileService->manipulateFile(
                $path,
                function ($file) use (
                    $path,
                    $content,
                    $description,
                    $options,
                    $connection,
                    $stream,
                    $clientPool,
                    &$change
                ) {
                    if ($stream) {
                        $clientPool->grab();
                        $clientPool->reset(true, $stream);
                    }
                    if ($options[IFile::ACTION] === IFile::SUBMIT) {
                        $file->sync()->edit()->setLocalContents($content);
                    } elseif ($options[IFile::ACTION] === IFile::SHELVE) {
                        // For now shelve into a new change, we may add to this functionality as required later
                        // (may want to pass a changeId via options)
                        $changeService = $this->services->get(Services::CHANGE_SERVICE);
                        $change        = new Change($connection);
                        $file          = $file->sync()->edit($change->getId())->setLocalContents($content);
                        $change        = $change->addFile($file)->setDescription($description)->save();
                        $changeService->shelve($connection, [], $change);
                    }
                    return $description;
                },
                $options
            );
            return new FileUpdateResult($file, $change);
        } finally {
            if ($stream) {
                $clientPool->release();
            }
        }
    }

    /**
     * Grab the depot off the first file and check if it points to a stream depot if so, return the //<depot> followed
     * by path components equal to stream depth (this field is present only on new servers, on older ones we take just
     * the first one)
     * @param string        $file       the file specification
     * @param mixed         $connection the current connection
     * @return string|null a stream name or null if one cannot be determined
     * @throws SpecNotFoundException
     */
    public function streamFromFile($file, $connection)
    {
        $pathComponents = array_filter(explode('/', $file));
        $depot          = Depot::fetchById(current($pathComponents), $connection);
        if ($depot->get('Type') == 'stream') {
            $depth = $depot->hasField('StreamDepth') ? $depot->getStreamDepth() : 1;
            return count($pathComponents) > $depth
                ? '//' . implode('/', array_slice($pathComponents, 0, $depth + 1))
                : null;
        }
        return null;
    }

    /**
     * Check if there is permission on the file for the protections mode
     * @param string        $path       full depot path to the file
     * @param string        $mode       protections mode to check
     * @param string        $type       file/stream
     * @throws ForbiddenException
     */
    public function checkPermission($path, $mode, $type = self::FILE)
    {
        $ipProtects = $this->services->get(IpProtects::IP_PROTECTS);
        if (!$ipProtects->filterPaths($path, $mode)) {
            throw new ForbiddenException(sprintf("You do not have '%s' permission for $type '%s'.", $mode, $path));
        }
    }

    /**
     * Processes a filePath and some params to make a call to the Diff library's diff method.
     * Additionally, does some post processing of the results, according to the params
     * @param string   $filePath   depot path of file/stream
     * @param array    $params     diff options
     *
     * @return array               Array with four or five elements
     *                             - lines   - array of unified diff hunks strings
     *                             - isCut   - boolean indicating if diff results were truncated
     *                             - isSame  - boolean indicating if from & to are identical
     *                             - header  - unified diff header string in git format
     *                             - summary - array with number of adds, deletes & updates
     *
     * @throws FileException
     * @throws FileNotFoundException
     * @throws ForbiddenException
     * @throws SpecNotFoundException
     * @throws P4Exception
     * @throws Exception
     */
    public function diff($filePath, $params)
    {
        // Get the files with their correct revisions
        $p4     = $this->services->get(ConnectionFactory::P4);
        $differ = new Diff($p4);
        // Set the diff options and run the diff
        $options = $this->setDiffOptions($params);
        if ($params[IDiff::TYPE] == self::STREAM) {
            $this->checkPermission($filePath, Protections::MODE_READ, self::STREAM);
            $streamRevisions = $this->getStreamRevisions($filePath, $params, $p4);
            $fromPath        = $streamRevisions[IDiff::FROM];
            $toPath          = $streamRevisions[IDiff::TO];
            $diff            = $differ->diffStream($toPath, $fromPath, $options);
        } else {
            $fileRevisions = $this->getFileRevisions($filePath, $params, $p4);
            $fromFile      = $fileRevisions[IDiff::FROM];
            $toFile        = $fileRevisions[IDiff::TO];
            // Check the file permissions
            // Since we are diffing revisions of the same file, we just need to check the common depot path
            $path = $fromFile ? $fromFile->getDepotFilename() : ($toFile ? $toFile->getDepotFilename() : '');
            $this->checkPermission($path, Protections::MODE_READ);
            $diff = $differ->diff($toFile, $fromFile, $options);
        }
        $this->formatDiff($diff);
        $this->handlePaging($diff, $options);
        return $diff;
    }

    /**
     * Builds from and to revisions of the given file path to diff against
     * @param string       $filePath   depot path of file
     * @param array        $params     diff options
     * @param Connection   $p4         p4 connection
     *
     * @return array
     * @throws FileException
     * @throws FileNotFoundException
     */
    protected function getFileRevisions($filePath, $params, $p4)
    {
        $leftFile  = null;
        $rightFile = null;
        $from      = isset($params[IDiff::FROM]) ? $params[IDiff::FROM] : null;
        $to        = $params[IDiff::TO];

        // Get the left file path with full revision
        if (isset($params[IDiff::FROM_FILE])) {
            // If a fromFile is specified, it will be base64 encoded, so we must decode it here
            // Additionally, if a fromFile is specified, we assume it exists. So, if there's not from,
            // we assume it's the head revision
            $leftFilePath = StringHelper::base64DecodeUrl($params[IDiff::FROM_FILE]);
            $left         = $leftFilePath . ($from ?? Diff::REVISION_HEAD);
        } else {
            $left = $from ? $filePath . $from : null;
        }

        // Get the left File object
        try {
            $leftFile = $left ? File::fetch($left, $p4) : null;
        } catch (FileException $e) {
            // Allow 404 when head is requested, as this may or may not be a new file
            if (strpos($left, Diff::REVISION_HEAD) === false) {
                throw $e;
            }
        }

        // Get the right File object
        $right     = $filePath . $to;
        $rightFile = $right ? File::fetch($right, $p4) : null;
        return [IDiff::FROM => $leftFile, IDiff::TO => $rightFile];
    }

    /**
     * Gets the diff options from the params, falling back to the config and in some cases local defaults
     * @param  array  $params   Passed parameters from query string to diff api
     * @return array
     * @throws Exception
     */
    protected function setDiffOptions($params)
    {
        $config       = $this->services->get(ConfigManager::CONFIG);
        $uft8_convert = ConfigManager::getValue(
            $config,
            ConfigManager::TRANSLATOR_UTF8_CONVERT,
            false
        );

        $options =  [
            Diff::RAW_DIFF           => true,
            Diff::SUMMARY            => true,
            IDiff::LINES             => $this->getContextLines($params, $config),
            IDiff::MAX_DIFFS         => $this->getMaxDiffs($params, $config),
            IDiff::MAX_SIZE          => $this->getMaxSize($params, $config),
            IDiff::OFFSET            => isset($params[IDiff::OFFSET]) ? (int)$params[IDiff::OFFSET] : 0,
            Diff::UTF8_CONVERT       => isset($params[Diff::UTF8_CONVERT])
                ? $params[Diff::UTF8_CONVERT] : $uft8_convert,
            Utf8::NON_UTF8_ENCODINGS => $this->getNonUtf8Encodings($config),
            Diff::SUMMARY_LINES      => isset($params[Diff::SUMMARY_LINES]) ? $params[Diff::SUMMARY_LINES] : false,
            Diff::UTF8_SANITIZE      => true,
            Diff::IGNORE_WS          => isset($params[Diff::IGNORE_WS]) ? $params[Diff::IGNORE_WS] : null
        ];

        return $options;
    }

    /**
     * Formats the diff into a form that is expected by the API
     * @param array    $diff   diff array reference
     */
    protected function formatDiff(array &$diff)
    {
        $diff[IFile::DIFFS] = $diff[Diff::LINES];
        unset($diff[Diff::LINES]);

        if (isset($diff[Diff::HEADER])) {
            $diff[Diff::HEADER] = $diff[Diff::HEADER] . "\n";
        }
    }

    /**
     * Truncates the number of diff sections (hunks) that we will return and populates the paging keys
     * @param array    $diff      diff array reference
     * @param array    $options   diff options
     */
    protected function handlePaging(array &$diff, array $options)
    {
        $maxDiffs      = $options[IDiff::MAX_DIFFS] == -1 ? null : $options[IDiff::MAX_DIFFS];
        $offset        = $options[IDiff::OFFSET];
        $totalDiffs    = count($diff[IFile::DIFFS]);
        $diffsReturned = $totalDiffs;
        $nextOffset    = null;

        if (!is_null($maxDiffs) || $offset > 0) {
            if ($offset <= $totalDiffs) {
                $diff[IFile::DIFFS] = array_slice($diff[IFile::DIFFS], $offset, $maxDiffs);
                $diffsReturned      = count($diff[IFile::DIFFS]);
                $nextOffset         = $offset + $diffsReturned;
                $nextOffset         = $nextOffset < $totalDiffs ? $nextOffset : null;
            } else {
                $diff[IFile::DIFFS] = [];
                $diffsReturned      = 0;
            }
        }

        $diff[IFile::PAGING][IFile::DIFFS]  = $diffsReturned;
        $diff[IFile::PAGING][IFile::OFFSET] = $nextOffset;
    }

    /**
     * Get the context lines value. NULL or negative value return the default value
     * from config else return the passed value
     * @param  array   $params    Passed parameters from query string to diff api
     * @param  array   $config    Swarm config
     * @return int
     * @throws Exception
     */
    protected function getContextLines($params, $config)
    {
        if (isset($params[IDiff::LINES]) && $params[IDiff::LINES] >= 0) {
            $lines = $params[IDiff::LINES];
        } else {
            $lines = ConfigManager::getValue($config, ConfigManager::DIFF_CONTEXT_LINES, self::DEFAULT_CONTEXT_LINES);
        }
        return $lines;
    }

    /**
     * Get the maxDiffs value
     * 1. NULL or Zero value return the default value from config.
     * 2. -1 value points to no limit and return -1
     * 3. Other then NULL or Zero or -1 return the passed  value
     * @param  array   $params    Passed parameters from query string to diff api
     * @param  array   $config    Swarm config
     * @return int
     * @throws Exception
     */
    protected function getMaxDiffs($params, $config)
    {
        if (isset($params[IDiff::MAX_DIFFS]) && ($params[IDiff::MAX_DIFFS] == -1 || $params[IDiff::MAX_DIFFS] > 0)) {
            $diffs = (int)$params[IDiff::MAX_DIFFS];
        } else {
            $diffs = ConfigManager::getValue($config, ConfigManager::DIFF_MAX_DIFFS, self::DEFAULT_MAX_DIFFS);
        }
        return $diffs;
    }

    /**
     * Get the maxSize value
     * 1. NULL or Zero value return the value from config.
     * 2. -1 value points to no limit so return 'unlimited'
     * 3. Other then NULL or Zero or -1 return the passed value
     * @param  array   $params    Passed parameters from query string to diff api
     * @param  array   $config    Swarm config
     * @return mixed
     * @throws Exception
     */
    protected function getMaxSize($params, $config)
    {
        $param = isset($params[IDiff::MAX_SIZE]) ? $params[IDiff::MAX_SIZE] : null;

        if (!$param || $param == 0 || $param < -1) {
            $fileSize = ConfigManager::getValue($config, ConfigManager::FILES_MAX_SIZE, self::DEFAULT_MAX_SIZE);
        } elseif ($param == -1) {
            $fileSize = 'unlimited';
        } else {
            $fileSize = $param;
        }

        return $fileSize;
    }

    /**
     * Get the TRANSLATOR_NON_UTF8_ENCODINGS from the config, otherwise default to the Utf8::$fallbackEncodings
     * @param array         $config   swarm config
     * @return mixed
     * @throws Exception
     */
    protected function getNonUtf8Encodings(array $config)
    {
        return ConfigManager::getValue($config, ConfigManager::TRANSLATOR_NON_UTF8_ENCODINGS, Utf8::$fallbackEncodings);
    }

    /**
     * Fetch file. Overrides the parent to check protections (also File does not support fetchById)
     * @param string                    $fileSpec       the file spec
     * @param ConnectionInterface|null  $connection     the current connection
     * @return mixed|File file model
     * @throws ForbiddenException
     * @throws FileNotFoundException
     */
    public function fetch($fileSpec, ConnectionInterface $connection = null)
    {
        $this->checkPermission($fileSpec, Protections::MODE_READ);
        return File::fetch($fileSpec, $connection);
    }

    /**
     * Checks whether stream is exists or not and return
     * the revisions path
     *
     * @param string       $streamPath stream spec path
     * @param array        $params     diff options
     * @param Connection   $p4         p4 connection
     * @return array
     * @throws SpecNotFoundException
     */
    protected function getStreamRevisions($streamPath, $params, $p4)
    {
        Stream::fetchById($streamPath,  $p4);
        $from  = $params[IDiff::FROM];
        $to    = $params[IDiff::TO];
        $left  = $from ? $streamPath . $from : null;
        $right = $streamPath . $to;

        return[IDiff::FROM => $left, IDiff::TO => $right];
    }
}
