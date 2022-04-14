<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Controller;

use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Controller\AbstractIndexController;
use Application\Filter\Preformat;
use Application\Helper\StringHelper;
use Application\Log\SwarmLogger;
use Application\Module as ApplicationModule;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Application\Response\CallbackResponse;
use Application\View\Helper\Utf8Filter;
use Closure;
use Files\MimeType;
use P4\Connection\ConnectionInterface as Connection;
use P4\Connection\Exception\CommandException;
use P4\File\Diff;
use P4\File\File;
use P4\File\Exception\Exception as FileException;
use P4\Spec\Exception\NotFoundException;
use P4\Spec\Stream;
use Projects\Model\Project as ProjectModel;
use Reviews\Model\Review;
use Laminas\Http\Response;
use Laminas\Stdlib\Parameters;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use P4\Spec\Change;
use P4\Filter\Utf8;
use Application\Config\ConfigManager;
use Application\Config\ConfigException;

class IndexController extends AbstractIndexController
{
    const REVIEW_ID      = 'reviewId';
    const REVIEW_UPDATED = 'reviewUpdated';

    public function fileAction()
    {
        $p4       = $this->services->get('p4');
        $archiver = $this->services->get(Services::ARCHIVER);
        $route    = $this->getEvent()->getRouteMatch();
        $path     = trim($route->getParam('path'), '/');
        $history  = $route->getParam('history');
        $project  = $route->getParam('project');
        $readme   = $route->getParam('readme');
        $client   = $project ? $project->getClient() : null;
        $request  = $this->getRequest();
        $format   = $request->getQuery('format');
        $version  = $request->getQuery('v');
        $range    = $request->getQuery('range');
        $fileType = $request->getQuery('type');
        $version  = ctype_digit($version) ? '#' . $version : $version;
        $download = $request->getQuery('download', $route->getParam('download')) !== null;
        $view     = $request->getQuery('view',     $route->getParam('view'))     !== null;
        $annotate = $request->getQuery('annotate')                               !== null;
        $lines    = (array) $request->getQuery('lines');
        $config   = $this->services->get('config');

        // if we have a client, set it on the connection
        if ($client) {
            $p4->setClient($client);
        }
        ini_set(
            'max_execution_time',
            ConfigManager::getValue($config, ConfigManager::FILES_DOWNLOAD_TIMEOUT, 1800)
        );
        // attempt to treat path as a file
        try {
            // if path is empty, no point querying perforce, throw now
            // and we'll turn it into a list action when we catch it below
            if (!strlen($path)) {
                throw new \Exception;
            }

            // We don't want to effect $path as this is used to forward onto file listing.
            $queryPath = $path;
            // If client is involved we are likely a project so we need to remove the branch ID
            // from the path.
            if ($client) {
                $queryPath = $this->removeBranchIDFromPath($path);
            }
            $queryPath = '//' . $queryPath;
            // fetch files for given path.
            if ($fileType === Stream::SPEC_TYPE) {
                // For Streams, output the form data from stream -o //...
                $stream   = $p4->run('stream', ['-o', $queryPath . $version], null, false)->getData(0);
                $callback = function () use ($stream) {
                    echo $stream;
                };

                return $this->buildFileResponse($queryPath, $download, 'text', $callback, strlen($stream));
            }
            $file = File::fetch($queryPath . $version, $p4);

            // deny access if user doesn't have read access to the file from his/her client IP
            $ipProtects = $this->services->get('ip_protects');
            if (!$ipProtects->filterPaths($file->getDepotFilename(), Protections::MODE_READ)) {
                throw new ForbiddenException(
                    "You don't have permission to read this file."
                );
            }

            // early exit if annotate requested.
            if ($annotate) {
                $annotate = $file->getAnnotatedContent(
                    [
                        File::ANNOTATE_CHANGES => true,
                        File::ANNOTATE_INTEG   => true,
                        File::ANNOTATE_CONTENT => false
                    ]
                );

                // fetch information for each referenced change
                $changes = [];
                $params  = [];
                foreach ($annotate as $line) {
                    $params[] = '@=' . $line['lower'];
                }

                // empty files won't generate output, avoid running changes with no params
                if ($params) {
                    $result = $p4->run('changes', array_merge(['-L', '-t'], $params));

                    // format change information
                    $preformat = new Preformat($this->services, $request->getBaseUrl());
                    foreach ($result->getData() as $change) {
                        $changes[$change['change']] = [
                            'user' => $change['user'],
                            'desc' => $preformat->filter($change['desc']),
                            'time' => date('c', $change['time'])
                        ];
                    }
                }

                return new JsonModel(
                    [
                        'annotate' => $annotate,
                        'changes'  => $changes
                    ]
                );
            }
            // determine file's content type.
            $type = MimeType::getTypeFromFile($file);
            // Establish whether this file can be edited
            $canEdit = false;
            if (ConfigManager::getValue($config, ConfigManager::FILES_ALLOW_EDITS) && $file->isText()) {
                $canEdit = !!$ipProtects->filterPaths($file->getDepotFilename(), Protections::MODE_WRITE);
            }

            if ($download || $view) {
                if ($format === 'json' && $type !== 'application/json') {
                    // though it is plausible to show json without
                    // the line range, we don't support it yet
                    if (!$lines) {
                        $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                        return new JSONModel(
                            [
                                'isValid'   => false,
                                'error'     => "JSON only supported when line range specified"
                            ]
                        );
                    }

                    $type = 'application/json';
                }

                // if requested, only get the content between the specified ranges
                if ($lines) {
                    // if lines were passed, but the file is binary, error out
                    if ($file->isBinary()) {
                        $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                        return new JSONModel(
                            [
                                'isValid'   => false,
                                'error'     => "Cannot apply line range to binary files"
                            ]
                        );
                    }

                    try {
                        $contents = $file->getDepotContentLines($lines);
                    } catch (\InvalidArgumentException $e) {
                        // Request Range Not Satisfiable
                        $this->getResponse()->setStatusCode(Response::STATUS_CODE_416);
                        return new JSONModel(
                            [
                                'isValid'   => false,
                                'error'     => "Invalid Range Specified: " . $e->getMessage()
                            ]
                        );
                    }

                    $callback = function () use ($format, $contents) {
                        if ($format === 'json') {
                            // Get the utf8filter to convert the string into utf8 for json.
                            $utf8 = new Utf8Filter($this->services);
                            echo $utf8->convertToUTF8($contents);
                        } else {
                            // If not Json format just output each part.
                            echo implode($contents);
                        }
                    };

                    return $this->buildFileResponse(
                        $file->getBasename(),
                        $download,
                        $type,
                        $callback,
                        $file->getFileSize()
                    );
                }

                $callback = function () use ($file) {
                    return $file->streamDepotContents();
                };

                // let's stream the response! this will save memory and hopefully improve performance
                return $this->buildFileResponse(
                    $file->getBasename(),
                    $download,
                    $type,
                    $callback,
                    $file->getFileSize()
                );
            }

            $partial  = $format === 'partial';
            $maxSize  = $this->getArchiveMaxInputSize();
            $fileFits = $file->hasStatusField('fileSize') && (int)$file->get('fileSize') <= $maxSize;
            $model    = new ViewModel;
            $model->setTerminal($partial);
            $model->setVariables(
                [
                    'path'       => $path,
                    'base64Path' => StringHelper::base64EncodeUrl($file->getDepotFilename()),
                    'file'       => $file,
                    'type'       => $type,
                    'version'    => $version,
                    'partial'    => $partial,
                    'history'    => $history,
                    'project'    => $project,
                    'readme'     => $readme,
                    'range'      => $range,
                    'formats'    => $this->services->get('formats'),
                    'canArchive' => $archiver->canArchive() && (!$maxSize || $fileFits),
                    'canEdit'    => $canEdit
                ]
            );

            return $model;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Exception $e) {
            // show 404 for download/view as we couldn't get the file, otherwise forward to list action
            if ($download || $view) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                return;
            }

            return $this->forward()->dispatch(
                IndexController::class,
                [
                    'action'  => 'list',
                    'path'    => $path,
                    'history' => $history,
                    'project' => $project,
                    'readme'  => $readme,
                    'client'  => $client,
                ]
            );
        }
    }

    /**
     * @param string        $filename       name of the content
     * @param bool          $download       whether it's a download or not
     * @param string        $contentType    type of the content
     * @param Closure       $callback       method to get the actual content
     * @param int           $contentLength  length of the content in bytes
     * @return CallbackResponse
     */
    protected function buildFileResponse($filename, $download, $contentType, $callback, $contentLength)
    {
        $response = new CallbackResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $contentType)
            ->addHeaderLine('Content-Transfer-Encoding', 'binary')
            ->addHeaderLine('Expires', '@0')
            ->addHeaderLine('Cache-Control', 'must-revalidate')
            ->addHeaderLine('Content-Disposition', ($download ? 'attachment; ' : '') . 'filename="' . $filename . '"')
            ->addHeaderLine('Content-Length', $contentLength);

        $response->setCallback($callback);
        return $response;
    }

    /**
     * Action for creating, downloading and checking status of an archive for the given path.
     *
     * @todo   at the moment this works only for depot paths
     *         in particular, it doesn't work for projects
     */
    public function archiveAction()
    {
        $config     = $this->services->get('config');
        $p4Config   = $this->services->get('p4_config');
        $p4         = $this->services->get('p4');
        $p4admin    = $this->services->get('p4_admin');
        $route      = $this->getEvent()->getRouteMatch();
        $path       = $route->getParam('path');
        $digest     = $route->getParam('digest');
        $project    = $route->getParam('project');
        $version    = $this->getRequest()->getQuery('v');
        $version    = ctype_digit($version) ? '#' . $version : $version;
        $request    = $this->getRequest();
        $response   = $this->getResponse();
        $archiver   = $this->services->get(Services::ARCHIVER);
        $background = $request->getQuery('background');
        $changeId   = $request->getQuery('changeId');
        $isChange   = $request->getQuery('isChange', $request->getQuery('isReview'));
        $cacheDir   = DATA_PATH . '/cache/archives';

        // set protections on the archiver to filter out files user doesn't have access to
        $archiver->setProtections($this->services->get('ip_protects'));

        // if status requested for a given archive digest, return it
        $statusFile = $cacheDir . '/' . $digest . '.status';
        if ($digest && $archiver->hasStatus($statusFile)) {
            return new JsonModel($archiver->getStatus($statusFile));
        } elseif ($digest) {
            $response->setStatusCode(Response::STATUS_CODE_404);
            return;
        }

        // check whether we archiving change or depot files
        if ($changeId) {
            $id       = $changeId;
            $isReview = Review::exists($changeId, $p4admin);

            // if review is committed the commit change id should be used to get files
            // otherwise use the latest shelved change ID
            if ($isReview) {
                $review  = Review::fetch($changeId, $p4admin);
                $changes = $review->isCommitted() ? $review->getCommits() : $review->getChanges();
                $id      = array_pop($changes);
            }

            try {
                $change = Change::fetchById($id, $p4);
            } catch (NotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }

            // if we got a not found or invalid argument exception
            // send a more appropriate 404
            if (!isset($change)) {
                throw new ForbiddenException("The change is not found");
            }

            // send 403 if change is not accessible
            if (!$change->canAccess()) {
                throw new ForbiddenException("You don't have permission to view this change.");
            }

            $max      = isset($p4Config['max_changelist_files']) ? (int)$p4Config['max_changelist_files'] : 1000;
            $files    = $change->getFileData(true, $max + 1);
            $filespec = [];
            // filter out any stream spec from the file list.
            foreach (array_filter(
                $files,
                function ($file) {
                    return $file['type'] !== Stream::SPEC_TYPE;
                }
            ) as $file) {
                if ($file['action'] != 'delete') {
                    $filespec[] = $file['depotFile'] . ($change->isPending() ? '@=' : '@') . $id;
                }
            }
            $filesInfo = [
                'digest' => "swarm-" . ($isReview ? "review-" : "change-") . $changeId
            ];
        } elseif ($isChange) {
            $filesInfo = ['digest' => $path];
        } else {
            // if we have a project, set its client on the connection so we are mapping the depot appropriately
            if ($project) {
                // split the browse path into branch-id and sub-path (e.g. //<branch-id>/<sub-path>)
                $parts   = explode('/', trim($path, '/'));
                $branch  = array_shift($parts);
                $subPath = implode('/', $parts);

                // collect paths
                try {
                    $branch = $project->getBranch($branch);
                    // Build the client for this branch only.
                    $client = $project->getBranchClient($branch['id']);
                } catch (\InvalidArgumentException $e) {
                    throw $e;
                }
                $p4->setClient($client);
                // If the subpath is empty or just the branch name set the path the the client as this
                // is the branch client only.
                $path = strlen($subPath) < 1 ? $client : $subPath;
            }

            // translate path to filespec
            $filespec = File::exists('//' . $path . $version, $p4)
                ? '//' . $path . $version
                : '//' . $path . '/...';

            try {
                $filesInfo = $archiver->getFilesInfo($filespec, $p4);
            } catch (\InvalidArgumentException $e) {
                if (strpos($e->getMessage(), 'contains no files') !== false) {
                    $response->setStatusCode(Response::STATUS_CODE_404);
                }

                throw $e;
            }

            // throw if files to compress are over the maximum size limit (if set)
            $maxSize = $this->getArchiveMaxInputSize();
            if ($maxSize && $filesInfo['size'] > $maxSize) {
                $response->setStatusCode(Response::STATUS_CODE_413);
                throw new \Exception(
                    "Cannot archive '$filespec'. Files are " . $filesInfo['size'] .
                    " bytes (max size is " . $maxSize . " bytes)."
                );
            }
        }

        // if background processing requested, return json response with file info and disconnect
        if ($background) {
            $json = new JsonModel($filesInfo);
            $response->getHeaders()->addHeaderLine('Content-Type: application/json; charset=utf-8');
            $response->setContent($json->serialize());
            $this->disconnect();
        }

        // compressing files can take a while
        ini_set(
            'max_execution_time',
            isset($config['archives']['archive_timeout']) ? (int) $config['archives']['archive_timeout'] : 1800
        );

        // archive files matching filespec
        ApplicationModule::ensureCacheDirExistAndWritable($cacheDir);
        $statusFile  = $cacheDir . '/' . $filesInfo['digest'] . ".status";
        $archiveFile = $cacheDir . '/' . $filesInfo['digest'] . '.zip';
        $archiver->archive($filespec, $archiveFile, $statusFile, $p4);

        // add a future task to remove archive file after its lifetime set in config (defaults to 1 day)
        $config        = $this->services->get('config');
        $cacheLifetime = isset($config['archives']['cache_lifetime'])
            ? $config['archives']['cache_lifetime']
            : 60 * 60 * 24;

        $this->services->get('queue')->addTask(
            'cleanup.archive',
            $archiveFile,
            ['statusFile' => $statusFile],
            time() + $cacheLifetime
        );

        // if we were archiving in the background, no need to send archive
        if ($background) {
            return $response;
        }

        // download
        $callback = function () use ($archiveFile) {
            return readfile($archiveFile);
        };

        // let's stream the response, this will save memory and hopefully improve performance
        return $this->buildFileResponse(
            basename($path) . '.zip',
            true,
            'application/zip',
            $callback,
            filesize($archiveFile)
        );
    }

    public function listAction()
    {
        $p4         = $this->services->get('p4');
        $ipProtects = $this->services->get('ip_protects');
        $archiver   = $this->services->get(Services::ARCHIVER);
        $config     = $this->services->get('config');
        $mainlines  = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAINLINES, []);
        $route      = $this->getEvent()->getRouteMatch();
        $path       = $route->getParam('path');
        $history    = $route->getParam('history');
        $project    = $route->getParam('project');
        $readme     = $route->getParam('readme');
        $client     = $project ? ($route->getParam('client') ?: $project->getClient()) : null;
        $request    = $this->getRequest();
        $partial    = $request->getQuery('format') === 'partial';
        $deleted    = $request->getQuery('showDeleted');
        $range      = $request->getQuery('range');
        $deleted    = $deleted !== null && $deleted != '0';

        // if we have a client, set it on the connection
        if ($client) {
            $p4->setClient($client);
        }

        try {
            $dirs  = $this->getDirs($path, $deleted, $ipProtects, $p4, $mainlines, $project);
            $files = $this->getFiles($path, $client, $deleted, $ipProtects, $p4);

            // if we have no dirs and no files, include deleted and try again
            // (we consider this analogous to accessing a deleted file directly)
            if (!$dirs && !$files && !$deleted) {
                $deleted = true;
                $dirs    = $this->getDirs($path, $deleted, $ipProtects, $p4, $mainlines, $project);
                $files   = $this->getFiles($path, $client, $deleted, $ipProtects, $p4);
            }
        } catch (\P4\Connection\Exception\CommandException $e) {
            // a command exception with the message:
            //  <path> - must refer to client '<client-id>'.
            // indicates an invalid depot - produce a 404 if this happens.
            $errors = implode("", $e->getResult()->getErrors());
            if (stripos($errors, " - must refer to client ") !== false) {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
                return;
            }

            throw $e;
        }

        // if we encountered an invalid folder, we need to flag it as a 404
        // (any path with an embedded / is a folder). missing depots already throw
        // and empty depots are valid so we don't deal with them here.
        if (strpos(trim($path, '/'), '/') && empty($dirs) && empty($files)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            return;
        }

        $view = new ViewModel;
        $view->setTerminal($partial);
        $view->setVariables(
            [
                'path'       => $path,
                'dirs'       => $dirs,
                'files'      => $files,
                'partial'    => $partial,
                'history'    => $history,
                'project'    => $project,
                'readme'     => $readme,
                'client'     => $client,
                'mainlines'  => $mainlines,
                'range'      => $range,
                'canArchive' => $archiver->canArchive() && strlen($path) > 0
            ]
        );

        return $view;
    }

    /**
     * Generate the differences between 2 elements of a changelist; the natural operation of this is to act upon
     * two versions of a file, either committed or in a shelf, the more limited option is to generate the
     * difference between a shelved version of a stream spec and a committed/shelved version of the same spec - it is
     * worth noting that the right hand side of the comparison must be a shelf.
     * @return void|ViewModel
     * @throws ConfigException
     * @throws FileException
     * @throws ForbiddenException
     */
    public function diffAction()
    {
        $queryParams = $this->getRequest()->getQuery();
        $specType    = $queryParams->get('type');
        $left        = $queryParams->get('left');
        $right       = $queryParams->get('right');
        $action      = null;
        $type        = null;

        // Split the diff processing into stream/file
        $diffResult = $specType === Stream::SPEC_TYPE
            ? $this->diffStream($queryParams, $left, $right, $action, $type)
            : $this->diffFile($queryParams, $left, $right, $action, $type);

        // There was some error so we just return
        if (is_null($diffResult)) {
            return;
        }

        // All good, so set view model
        $view = new ViewModel(
            [
                'left'              => $left,
                'right'             => $right,
                'action'            => $action,
                'diff'              => $diffResult,
                'ignoreWs'          => (bool) $queryParams->get('ignoreWs'),
                'formats'           => $this->services->get('formats'),
                'nonPreviewMessage' => $this->getNonPreviewMessage($action, $type, $diffResult)
            ]
        );

        return $view->setTerminal(true);
    }

    /**
     * Manages the handling of stream diffs
     *
     * @param array          $queryParams   parameters passed to in request's query string
     * @param string|null    $left          name of the stream on the left hand side of the comparison pane
     * @param string|null    $right         name of the stream on the right hand side of the comparison pane
     * @param string|null    $action        the action performed on the stream - will be set to 'edit'.
     * @param string|null    $type          the type of specification - will be set to Stream::SPEC_TYPE
     *
     * @return array|void
     *
     * @throws ConfigException
     */
    protected function diffStream($queryParams, &$left, &$right, &$action, &$type)
    {
        $p4 = $this->services->get(ConnectionFactory::P4);

        try {
            // Get the stream objects, removing any version data from the requested values
            // N.B. head will be passed in as the left hand version when the web application requests a comparison
            // with the latest version and must be removed
            $left  = $left  ? Stream::fetchById(preg_replace('/[@#]([0-9=]*|head)$/', '', $left),  $p4) : null;
            $right = $right ? Stream::fetchById(preg_replace('/[@#][0-9=]*$/', '', $right), $p4) : null;
        } catch (NotFoundException $e) {
            // return 404 or 422 if either file could not be fetched (due to invalid or non-existent filespec)
            return $this->setDiffErrorStatusCode($p4, $queryParams, $e);
        }
        $action = "edit";
        $type   = Stream::SPEC_TYPE;
        return $this->getStreamDiffs($queryParams, $p4);
    }

    /**
     * Manages the handling of file diffs
     *
     * @param array          $queryParams   parameters passed to in request's query string
     * @param string|null    $left          name of the file on the left hand side of the comparison pane
     * @param string|null    $right         name of the file on the right hand side of the comparison pane
     * @param string|null    $action        the action performed on the file - will be 'edit', 'add', 'delete', etc.
     * @param string|null    $type          the type of specification - 'text', 'binary', etc.
     *
     * @return array|void
     *
     * @throws ConfigException
     * @throws FileException
     * @throws ForbiddenException
     */
    protected function diffFile($queryParams, &$left, &$right, &$action, &$type)
    {
        $p4 = $this->services->get(ConnectionFactory::P4);

        try {
            try {
                $left = $left ? File::fetch($left,  $p4) : null;
            } catch (FileException $e) {
                // Allow 404 when head is requested, as this may or may not be a new file
                if (strpos($left, '#head')) {
                    $left = null;
                } else {
                    throw $e;
                }
            }

            $right = $right ? File::fetch($right, $p4) : null;
        } catch (FileException $e) {
            // return 404 or 422 if either file could not be fetched (due to invalid or non-existent filespec)
            return $this->setDiffErrorStatusCode($p4, $queryParams, $e);
        }

        // action can be explicitly passed in (useful for diffing versions of a review)
        // if no action is given, we favor the action of the right and fallback to the left
        $action = $queryParams->get('action');
        $action = $action ?: ($right ? $right->getStatus('headAction') : null);
        $action = $action ?: ($left  ? $left->getStatus('headAction')  : null);

        $type = $right ? $right->getStatus('headType') : $left->getStatus('headType');
        return $this->getFileDiffs($queryParams, $left, $right, $p4);
    }

    /**
     * When there is an error diffing streams or files, sets the response's status code to 404 (not found),
     * unless the diff is part of a review and the review is out of date, in which case it sets the response's
     * status code to 422 (unprocessable entity).
     *
     * @param Connection       $p4             p4 connection to use
     * @param Parameters       $queryParams    parameters passed to in request's query string
     * @param \P4\Exception    $e              exception that lead to this method getting called
     */
    protected function setDiffErrorStatusCode($p4, $queryParams, $e)
    {
        $outdated      = false;
        $logger        = $this->services->get(SwarmLogger::SERVICE);
        $reviewId      = $queryParams->get(self::REVIEW_ID);
        $reviewUpdated = $queryParams->get(self::REVIEW_UPDATED);

        // Trace the original error
        $logger->trace($e);

        // Determine if the review is outdated
        if ($reviewId && $reviewUpdated) {
            try {
                $adminUser = $this->services->get(ConnectionFactory::P4_ADMIN);
                $review    = Review::fetch($reviewId, $adminUser);
                $outdated  = $review->get(Review::FIELD_UPDATED) > (int) $reviewUpdated;
            } catch (\Record\Exception\NotFoundException $e) {
            } catch (\InvalidArgumentException $e) {
            }
        }

        // If the diff action is on a review and the review is out of date, we need to indicate that
        if ($outdated) {
            $user = $p4->getUser();
            $logger->trace("User: $user looking at Review: $reviewId and it is out of date");

            // This will be caught by the front end and processed accordingly
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_422);
        } else {
            // If the review is not outdated, the filespec can not legitimately be found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
        }
    }

    /**
     * Use a Diff object to compare two versions of a file and split the output into lines for further processing
     * into ui elements.
     * @param $queryParams  array      The query parameters from the request
     * @param $left         File       The file on the left hand side of the comparison pane
     * @param $right        File       The file on the right hand side of the comparison pane
     * @param $p4           Connection The active perforce connection
     * @return array                   The array, may be empty, of difference lines
     * @throws ConfigException         When there is a configuration problem
     * @throws ForbiddenException      When access to file is disallowed
     */
    protected function getFileDiffs($queryParams, $left, $right, $p4)
    {
        $config = $this->services->get(ConfigManager::CONFIG);
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $diff   = new Diff($p4);

        try {
            $maxSize = ConfigManager::getValue($config, ConfigManager::FILES_MAX_SIZE);
        } catch (ConfigException $ce) {
            $logger->warn($ce);
            $maxSize = File::MAX_SIZE_VALUE;
        }
        try {
            $maxDiffs = ConfigManager::getValue($config, ConfigManager::DIFF_MAX_DIFFS);
        } catch (ConfigException $ce) {
            $logger->warn($ce);
            $maxDiffs = 1000;
        }
        if ($queryParams->get(File::MAX_SIZE)) {
            $maxSize = null;
        }

        $maxDiffOverride = null;
        if ($queryParams->get('maxDiffs')) {
            $maxSize         = null;
            $maxDiffOverride = true;
        }
        $options = [
            $diff::IGNORE_WS    => (bool) $queryParams->get('ignoreWs'),
            $diff::UTF8_CONVERT => ConfigManager::getValue(
                $config,
                ConfigManager::TRANSLATOR_UTF8_CONVERT,
                true
            ),
            File::MAX_SIZE      => $maxSize,
            Utf8::NON_UTF8_ENCODINGS =>
                ConfigManager::getValue(
                    $config,
                    ConfigManager::TRANSLATOR_NON_UTF8_ENCODINGS,
                    Utf8::$fallbackEncodings
                )
        ];

        // ensure user has access to the files from his/her client IP
        $ipProtects    = $this->services->get('ip_protects');
        $noLeftAccess  = $left  && !$ipProtects->filterPaths($left->getDepotFilename(),  Protections::MODE_READ);
        $noRightAccess = $right && !$ipProtects->filterPaths($right->getDepotFilename(), Protections::MODE_READ);
        if ($noLeftAccess || $noRightAccess) {
            throw new ForbiddenException("You don't have permission to diff these file(s).");
        }

        $diffResult = $diff->diff($right, $left, $options);

        if (!$maxDiffOverride && $left && sizeof($diffResult[Diff::LINES]) > $maxDiffs) {
            $diffResult[Diff::LINES] = array_slice($diffResult[Diff::LINES], 0, $maxDiffs);
            $diffResult[Diff::CUT]   = $maxDiffs;
            // If the file has been sliced we want to replace all the 'meta' lines with non-functioning ones.
            // We want to show the demarcation in the file display but we cannot load more if we have cut the file.
            foreach ($diffResult[Diff::LINES] as &$line) {
                if ($line[Diff::TYPE] === Diff::META) {
                    $line[Diff::VALUE] = '';
                    $line[Diff::CUT]   = true;
                }
            }
        }

        return $diffResult;
    }

    /**
     * Perform a diff2 operation on 2 stream objects and split it into lines, so that the output mimics that
     * of the file diff functionality
     * @param $queryParams  array      The query parameters from the request
     * @param $p4           Connection The active perforce connection
     * @return array                   The array, may be empty, of difference lines
     * @throws ConfigException         When there is a configuration problem
     */
    protected function getStreamDiffs($queryParams, $p4)
    {
        $left    = $queryParams->get('left');
        $right   = $queryParams->get('right');
        $config  = $this->services->get(ConfigManager::CONFIG);
        $options = [
            Diff::IGNORE_WS    => (bool) $queryParams->get('ignoreWs'),
            Diff::UTF8_CONVERT => true,
            Diff::UTF8_SANITIZE => true,
            Utf8::NON_UTF8_ENCODINGS =>
                ConfigManager::getValue(
                    $config,
                    ConfigManager::TRANSLATOR_NON_UTF8_ENCODINGS,
                    Utf8::$fallbackEncodings
                )
        ];

        $diff = array(
            Diff::LINES  => array(),
            Diff::CUT  => false,
            Diff::SAME => false
        );

        try {
                $diff = Diff::processDiffEdit(
                    $diff,
                    $p4->run(
                        'diff2',
                        array_merge(
                            $options[Diff::IGNORE_WS] ? [Diff::IGNORE_ALL_WHITESPACE] : [],
                            [
                                Diff::FORCE_BINARY_DIFF,
                                Diff::UNIFIED_MODE . '5', // 5 lines of context, could this be a configurable in future
                                Diff::STREAM_DIFF,
                                str_replace('#', '@', str_replace('#head', '', $left)),
                                $right
                            ]
                        ),
                        null,
                        false
                    )->getData(),
                    $options
                );
        } catch (CommandException $ce) {
            try {
                $this->services->get(SwarmLogger::SERVICE)->debug(
                    "Diff command failed. Considering it as new stream add."
                );
                $diff = Diff::streamSpecAdd(
                    $diff,
                    $right,
                    $p4->run(
                        Stream::SPEC_TYPE,
                        [
                            "-o",
                            $right
                        ],
                        null,
                        false
                    )->getData(),
                    $options
                );
            } catch (CommandException $ce) {
                // Couldn't diff, report the reason as a preview message
                $diff[Diff::ERROR] = $ce->getMessage();
                return $diff;
            }
        }
        $diff[Diff::SAME] = count($diff[Diff::LINES]) === 0;
        return $diff;
    }

    /**
     * Builds a message for a non-preview file trying to avoid fragmentation
     * for translations.
     * @param $action the file action.
     * @param $type the file type.
     * @param $diffResult the diff result.
     * @return string the message.
     */
    protected function getNonPreviewMessage($action, $type, $diffResult)
    {
        // If the diff result isSame use that rather than the edit action
        $combinedAction = isset($diffResult[Diff::ERROR])?Diff::ERROR:$action;
        if ($combinedAction === 'edit' && $diffResult[Diff::SAME] === true) {
            $combinedAction = Diff::SAME;
        }
        $translator = $this->services->get('translator');
        // Fallback for any message not determined
        $message = $translator->t('File content not displayed.');
        switch ($combinedAction) {
            case 'purge':
                switch ($type) {
                    case 'text':
                        $message = $translator->t('Text file content purged.');
                        break;
                    case 'binary':
                        $message = $translator->t('Binary file content purged.');
                        break;
                    case 'symlink':
                        $message = $translator->t('Symlink purged.');
                        break;
                    default:
                        break;
                }
                break;
            case Diff::SAME:
                switch ($type) {
                    case 'text':
                        $message = $translator->t('Text file content unchanged.');
                        break;
                    case 'binary':
                        $message = $translator->t('Binary file content unchanged.');
                        break;
                    case 'symlink':
                        $message = $translator->t('Symlink unchanged.');
                        break;
                    case Stream::SPEC_TYPE:
                        $message = $translator->t('Stream unchanged.');
                        break;
                    default:
                        break;
                }
                break;
            case 'add':
                switch ($type) {
                    case 'text':
                        $message = $translator->t('Text file added.');
                        break;
                    case 'binary':
                        $message = $translator->t('Binary file added.');
                        break;
                    case 'symlink':
                        $message = $translator->t('Symlink added.');
                        break;
                    default:
                        break;
                }
                break;
            case 'delete':
                switch ($type) {
                    case 'text':
                        $message = $translator->t('Text file deleted.');
                        break;
                    case 'binary':
                        $message = $translator->t('Binary file deleted.');
                        break;
                    case 'symlink':
                        $message = $translator->t('Symlink deleted.');
                        break;
                    default:
                        break;
                }
                break;
            case 'edit':
                switch ($type) {
                    case 'text':
                        $message = $translator->t('Text file edited.');
                        break;
                    case 'binary':
                        $message = $translator->t('Binary file edited.');
                        break;
                    case Stream::SPEC_TYPE:
                        $message = $translator->t('Stream edited.');
                        break;
                    default:
                        break;
                }
                break;
            case Diff::ERROR:
                $message = $translator->t('Failed to run a diff2 command.');
                $this->services->get('logger')->err($diffResult[$combinedAction]);
                break;
            default:
                break;
        }
        return $message;
    }

    /**
     * Get dirs for the given path (applies ip-filters and a 'natural' sort)
     *
     * @param   string          $path           the path we are currently browsing
     * @param   boolean         $deleted        whether or not deleted directories are included
     * @param   Protections     $ipProtects     filter dirs according to given protections
     * @param   Connection      $p4             the perforce connection to use
     * @param   array           $mainlines      common 'mainline' branch names
     * @param   ProjectModel    $project        optional project to get branch paths from
     * @return  array           list of directories under the given path
     */
    protected function getDirs(
        $path,
        $deleted,
        $ipProtects,
        Connection $p4,
        array $mainlines,
        ProjectModel $project = null
    ) {
        // four discrete cases to handle:
        // - no path and no project (report depots as dirs)
        // - no path, but we have a project (report branches as dirs)
        // - path for a project (run our fancy project branch/dir logic)
        // - plain path (just run dirs)
        $dirs = [];
        if (!$path && !$project) {
            foreach ($p4->run('depots')->getData() as $depot) {
                $dirs[] = ['dir' => $depot[ 'name']];
            }
        } elseif (!$path && $project) {
            foreach ($project->getBranches('id', $mainlines) as $branch) {
                // only list branches with paths
                if (count($branch['paths'])) {
                    $dirs[] = ['dir' => $branch[ 'id']];
                }
            }
        } elseif ($path && $project) {
            $dirs = $this->getProjectDirs($project, $path, $deleted, $ipProtects, $p4);
        } else {
            $flags   = $deleted ? ['-D'] : [];
            $flags[] = '//' . $path . '/*';
            $dirs    = $p4->run('dirs', $flags)->getData();
        }

        // apply ip-protections (if we have a project, protections are already applied)
        if (!$project) {
            $dirs = $ipProtects->filterPaths(
                $dirs,
                Protections::MODE_LIST,
                function ($dir) {
                    return '//' . trim($dir['dir'], '/') . '/';
                }
            );
        }

        // sort directories unless we got them from project branches that already handles sorting
        if ($path || !$project) {
            usort(
                $dirs,
                function ($a, $b) {
                    // put hidden (.foo) dirs last - otherwise, just a natural case-insensitive sort
                    return (($a['dir'][0] === '.') - ($b['dir'][0] === '.'))
                        ?: strnatcasecmp($a['dir'], $b['dir']);
                }
            );
        }

        // flag deleted directories
        // if not fetching deleted directories, then none are deleted
        // if fetching deleted dirs, then recurse (excluding deleted) and flag the disjoint set
        if (!$deleted) {
            foreach ($dirs as $key => $dir) {
                $dirs[$key]['isDeleted'] = false;
            }
        } else {
            $notDeleted = $this->getDirs($path, false, $ipProtects, $p4, $mainlines, $project);
            $notDeleted = array_map('current', $notDeleted);
            foreach ($dirs as $key => $dir) {
                $dirs[$key]['isDeleted'] = !in_array($dir['dir'], $notDeleted);
            }
        }

        return $dirs;
    }

    /**
     * Get list of directory basenames in the given path and project.
     * This method is needed because project branches can map multiple paths
     * and the 'p4 dirs' command does not support client-syntax very well.
     * To work around this, we merge results from 'p4 dirs' run with multiple
     * arguments (one argument for each mapping).
     *
     * @param ProjectModel $project    project to get branch paths from
     * @param string       $path       the project path we are currently browsing
     * @param boolean      $deleted    whether or not deleted directories are included
     * @param Protections  $ipProtects dirs are filtered according to given protections
     * @param Connection   $p4         the perforce connection to use
     * @return  array           list of unique/merged directory basenames for given path
     * @throws \P4\Exception
     */
    protected function getProjectDirs(ProjectModel $project, $path, $deleted, Protections $ipProtects, Connection $p4)
    {
        // split the browse path into branch-id and sub-path (e.g. //<branch-id>/<sub-path>)
        $parts   = explode('/', trim($path, '/'));
        $branch  = array_shift($parts);
        $subPath = implode('/', $parts);

        // collect paths to run p4 dirs on (early exit for non-existent branch)
        try {
            $branch = $project->getBranch($branch);
            // Build the client for this branch only.
            $client = $project->getBranchClient($branch['id']);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        // Run dirs on the currently navigated path.
        $paths[] = '//' . $subPath . (strlen($subPath) > 0 ? '/*' : '*');

        // Run the dirs command against the navigated path and include the '-C' flag as this limits
        // the view to only files that are mapped into the client. Then filter the result according
        // to given protections.
        $dirs = $ipProtects->filterPaths(
            $p4->run('dirs', array_merge($deleted ? ['-D', '-C'] : ['-C'], $paths))->getData(),
            Protections::MODE_LIST,
            function ($dir) {
                return rtrim($dir['dir'], '/') . '/';
            }
        );

        // Convert dir paths to basenames and return unique entries
        $unique = [];
        foreach ($dirs as $dir) {
            $dir          = basename($dir['dir']);
            $unique[$dir] = ['dir' => $dir];
        }

        return array_values($unique);
    }

    /**
     * Get files in the given path (applies ip-filters and a 'natural' sort)
     *
     * @param   string          $path           the path we are currently browsing
     * @param   string          $client         optional client to map files through
     * @param   boolean         $deleted        whether or not deleted files are included
     * @param   Protections     $ipProtects     filter files according to given protections
     * @param   Connection      $p4             the perforce connection to use
     * @return  array           list of files under the given path
     */
    protected function getFiles($path, $client, $deleted, Protections $ipProtects, Connection $p4)
    {

        // First build the flags.
        $flags   = $deleted ? [] : ['-F', '^headAction=...delete'];
        $flags[] = '-Ol';
        $flags[] = '-T';
        $flags[] = ($client ? 'clientFile,' : '') . 'depotFile,headTime,fileSize,headAction';

        // If we have a client set, add the "-Rc" flag to only get files within the client view
        if ($client) {
            $path    = $this->removeBranchIDFromPath($path);
            $flags[] = '-Rc';
        }

        // if the path is empty likely to be no files return early.
        if (!$path) {
            return [];
        }

        $flags[] = "//$path/*";
        // Run fstat on the current flags, then filter the files based on protections.
        $files = $p4->run('fstat', $flags)->getData();
        $files = $ipProtects->filterPaths($files, Protections::MODE_LIST, 'depotFile');

        usort(
            $files,
            function ($a, $b) use ($client) {
                $a = basename($client ? $a['clientFile'] : $a['depotFile']);
                $b = basename($client ? $b['clientFile'] : $b['depotFile']);

                // put hidden (.foo) files last - otherwise, just a natural case-insensitive sort
                return (($a[0] === '.') - ($b[0] === '.')) ?: strnatcasecmp($a, $b);
            }
        );

        return $files;
    }

    /**
     * Helper method to get config value for archives 'max_input_size'.
     *
     * @return  int|null    value for archives 'max_input_size' from config or null if not set
     */
    protected function getArchiveMaxInputSize()
    {
        $config = $this->services->get('config') + ['archives' => []];
        return isset($config['archives']['max_input_size'])
            ? (int) $config['archives']['max_input_size']
            : null;
    }

    /**
     * If a project Path is being used we need to remove the branch ID from the
     * path so we can run commands against the depot path syntax.
     *
     * @param string $path  The path that contains the branch Id and we want removed.
     *
     * @return bool|string
     */
    protected function removeBranchIDFromPath($path)
    {
        $string = explode('/', $path);
        // As we know we have hit this function due to a project. This means we know that element
        // zero is the branch ID. When running command against the depot we don't need this. Removing
        // this and return just the depot path helps.
        $depotLength = strlen($string[0])+1;
        return substr($path, $depotLength);
    }
}
