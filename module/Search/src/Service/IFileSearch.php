<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Search\Service;

/**
 * Interface IFileSearch. Describes values and responsibilities for a file search service
 * @package Search\Service
 */
interface IFileSearch
{
    const FILE_SEARCH_SERVICE = 'fileSearch';
    const FILE_PATH           = 'filePath';
    const FILE_CONTENT        = 'fileContent';
    const P4_SEARCH_HOST      = 'p4SearchHost';
    const P4_SEARCH_API_PATH  = 'p4SearchApiPath';
    const SEARCH              = 'search';
    const FILES_COUNT         = 'filesCount';
    const MAX_SCORE           = 'maxScore';

    const RESULTS           = 'results';
    const RESULT_TYPE       = 'type';
    const RESULT_CHANGE     = 'change';
    const RESULT_DEPOT_FILE = 'depotFile';
    const RESULT_FILE_NAME  = 'fileName';
    const RESULT_ACTION     = 'action';
    const RESULT_FILE_TYPE  = 'fileType';
    const RESULT_REV        = 'rev';
    const RESULT_FILE_SIZE  = 'fileSize';

    /**
     * Search for a file path or content.
     * File Path uses P4Search API if available else P4 Fstat
     * File Content uses P4Search only
     * @param string    $context       the context filePath or fileContent
     * @param array     $options       the search options includes: term, path, limit and more.
     */
    public function search($context, $options);
}
