<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Controller;

use Api\Controller\AbstractRestfulController;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Helper\StringHelper;
use Application\I18n\TranslatorFactory;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\Permissions;
use Application\View\Helper\ViewHelperFactory;
use Comments\Model\Comment;
use Files\Filter\Diff\IDiff;
use Files\Filter\IFile;
use Files\MimeType;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use P4\File\Exception\NotFoundException as FileNotFoundException;
use Exception;
use Record\Exception\NotFoundException as RecordNotFoundException;
use Files\Filter\Diff\IDiff as IDiffFilter;
use P4\File\Exception\Exception as P4Exception;

/**
 * Class FileApi
 * @package Files\Controller
 */
class FileApi extends AbstractRestfulController
{

    /**
     * Update an existing file identified by the encoded path with the content from data. File content will be updated
     * as long as the file exists and the user has permission to write contents to that file. Permission is determined
     * by protections as long as Swarm is set to emulate protections.
     * @param string        $encodedPath    full path of the file to update. This is expected to be an encoded
     *                                      representation as per StringHelper::base64EncodeUrl($string)
     *                                      (base64 url safe)
     * @param mixed         $data           content of the update
     *      {
     *          "content"     : "<file content>"                     (required, but can be '')
     *          "description" : "<description for the change>"       (required)
     *          "comment"     : "<comment for adding to a review>"   (optional)
     *      }
     * If a comment is provided the review id will be found from the description using the standard Swarm specification
     * (for example '#review-1234' etc). If the review cannot be found an exception will be thrown
     * @return JsonModel the standard Swarm API success response with the following data attributes
     *      "fileName": "<file name>"
     *      "contentLink": "<link to get content>"
     *      "fileRevision" : "#<headRev>, @=<reviewId> or @=<change>"
     *      "contentType" : "<content type determined from the file>
     *
     * or the standard error response if a problem occurs
     * @throws P4Exception
     * @throws UnauthorizedException
     */
    public function update($encodedPath, $data)
    {
        $errors      = null;
        $description = null;
        $review      = null;
        $fileModel   = null;
        $action      = null;
        $result      = null;
        $file        = StringHelper::base64DecodeUrl($encodedPath);
        try {
            // Make sure that a user is Authenticated
            $this->services->get(Services::PERMISSIONS)->enforce(Permissions::AUTHENTICATED);
            $filter = $this->services->get(IFile::UPDATE_FILTER);
            // Add the file name from the URL for the filter to check
            $data[IFile::FILE_NAME] = $file;
            $filter->setData($data);
            if ($filter->isValid()) {
                $description = $filter->getValue(IFile::DESCRIPTION);
                $comment     = $filter->getValue(IFile::COMMENT);
                $action      = $filter->getValue(IFile::ACTION);
                if ($comment) {
                    $reviewDao = $this->services->get(IDao::REVIEW_DAO);
                    // Ensure the review is valid, exception thrown to exit early
                    $review = $reviewDao->fetchFromKeyword($description);
                }
                $dao       = $this->services->get(IDao::FILE_DAO);
                $result    = $dao->update(
                    $file,
                    $filter->getValue(IFile::CONTENT),
                    $description,
                    [IFile::ACTION => $action]
                );
                $fileModel = $result->getFile();
                // If the file update is successful add the file level comment
                // (the update is most important so we don't want to add the comment first)
                // TODO in future we should have a commentDAO
                if ($comment) {
                    $commentModel = new Comment;
                    $commentModel
                        ->set('topic', 'review/' . $review->getId())
                        ->set('time', time())
                        ->set('body', $comment)
                        ->set(
                            'context',
                            [
                                'file'    => $file,
                                'type'    => 'text',
                                'review'  => $review->getId(),
                                'version' => $review->getHeadVersion()
                            ]
                        )
                        ->save();
                }
            } else {
                $errors = $filter->getMessages();
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (FileNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (RecordNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            $errors     = [
                $this->buildMessage(
                    Response::STATUS_CODE_404,
                    $translator->t("Cannot fetch review based on [%s]", [$description])
                )
            ];
        } catch (UnauthorizedException $e) {
            // Re-throw unauthorised, the standard handler copes well
            throw($e);
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $revision = null;
            if ($review) {
                $changeId = $review->getId();
                $revision = '@=' . $changeId;
            } else {
                if ($action === IFile::SUBMIT) {
                    $changeId = $fileModel->getStatus('headChange');
                    $revision = '#' . $fileModel->getStatus('headRev');
                } else {
                    $changeId = (string)$result->getChange()->getId();
                    $revision = '@=' . $changeId;
                }
            }
            $result = [
                IFile::FILE_NAME    => $file,
                IFile::CONTENT_LINK => $this->getContentLink($file),
                IFile::REVISION     => $revision,
                IFile::CONTENT_TYPE => MimeType::getTypeFromFile($fileModel),
                IFile::CHANGE_ID    => $changeId
            ];
            $json   = $this->success($result);
        }
        return $json;
    }

    /**
     * Get file content information. Data is returned provided that the current user has permission to read the file,
     * otherwise an exception will be thrown. An optional query parameter 'fileRevision' is supported to get a specific
     * revision for example '#2' or '@=123456'.
     * @param string        $encodedPath    path of the file to get. This is expected to be an encoded representation as
     *                                      per StringHelper::base64EncodeUrl($string) (base64 url safe) of the full
     *                                      depot path
     * @return JsonModel the standard Swarm API success response with the following data attributes
     *      "fileName": "<file name>"
     *      "contentLink": "<link to get content>"
     *      "fileRevision" : "<revision specifier> (headRev at the time of request if not specified)",
     *      "contentType" : "<content type determined from the file>
     *
     * or the standard error response if a problem occurs
     * @throws P4Exception
     * @throws UnauthorizedException
     */
    public function get($encodedPath)
    {
        $errors      = null;
        $contentLink = null;
        $file        = StringHelper::base64DecodeUrl($encodedPath);
        $revision    = $this->getRequest()->getQuery(IFile::REVISION);
        $fileModel   = null;
        // Add the file name from the URL for the filter to check
        $data = [IFile::FILE_NAME => $file];
        try {
            $filter = $this->services->get(IFile::GET_FILTER);
            if ($revision) {
                $data[IFile::REVISION] = $revision;
            }
            $filter->setData($data);
            if ($filter->isValid()) {
                $dao       = $this->services->get(IDao::FILE_DAO);
                $fileModel = $dao->fetch($file . ($revision ? $revision : ''));
                if (!$revision) {
                    $revision = '#' . $fileModel->getStatus('headRev');
                }
                $contentLink = $this->getContentLink($file);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (ForbiddenException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_403);
            $errors = [$this->buildMessage(Response::STATUS_CODE_403, $e->getMessage())];
        } catch (FileNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (UnauthorizedException $e) {
            // Re-throw unauthorised, the standard handler copes well
            throw($e);
        } catch (Exception $e) {
            // Catch all for other exceptions
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $result = [
                IFile::FILE_NAME    => $file,
                IFile::CONTENT_LINK => $contentLink,
                IFile::REVISION     => $revision,
                IFile::CONTENT_TYPE => MimeType::getTypeFromFile($fileModel),
                // headChange for the file can be used to get the change for all cases.
                // For example requesting @1234 would have a headChange of 1234 and
                // getting by revision # would be the change for that revision
                IFile::CHANGE_ID    => $fileModel->getStatus('headChange')
            ];
            $json = $this->success($result);
        }
        return $json;
    }

    /**
     * Use URL configuration to build a link to the 'view' end point that can be used to get file contents
     * @param string        $file       file spec
     * @return mixed a link
     */
    protected function getContentLink($file)
    {
        $urlHelper = $this->services->get('ViewHelperManager')->get(ViewHelperFactory::QUALIFIED_URL);
        return $urlHelper('view', ['path' => ltrim($file, '/')]);
    }

    /**
     * // TODO further detail and implementation will be added and remove the dummy response
     * GET endpoint for the diff of a filepath between the given from and to revisions
     * @return JsonModel
     */
    public function diffAction()
    {
        $diffResult = [];
        $errors     = [];
        // Filepath is expected to be a base64, url-safe encoded representation, as per StringHelper::base64EncodeUrl.
        $filePath = StringHelper::base64DecodeUrl($this->getEvent()->getRouteMatch()->getParam('id'));

        try {
            // Run the query through our filter
            $filter = $this->services->get(IDiffFilter::NAME);
            $filter->setData($this->getRequest()->getQuery());

            if ($filter->isValid()) {
                $params     = $filter->getValues();
                $dao        = $this->services->get(IDao::FILE_DAO);
                $diffResult = $dao->diff($filePath, $params);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
                $errors = $filter->getMessages();
            }
        } catch (ForbiddenException $e) {
            // For security reasons we reset the error from forbidden to not found
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            $errors     = [$this->buildMessage(Response::STATUS_CODE_404, $translator->t('File not found'))];
        } catch (FileNotFoundException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            $errors = [$this->buildMessage(Response::STATUS_CODE_404, $e->getMessage())];
        } catch (Exception $e) {
            // Catch all for other exceptions
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }

        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($diffResult);
        }

        return $json;
    }
}
