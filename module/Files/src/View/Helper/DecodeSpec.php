<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\View\Helper;

use P4\File\File;
use Laminas\View\Helper\AbstractHelper;

/**
 * Helper to decode file data that may be depot file information or stream spec information
 * treated as a file.
 * @package Files\View\Helper
 */
class DecodeSpec extends AbstractHelper
{
    const DEPOT_FILE    = 'depotFile';
    const STREAM        = 'stream';
    const STREAM_PREFIX = self::STREAM . ':';
    const TYPE          = 'type';

    /**
     * Decode the file data adding the stream prefix if applicable
     * @param array $fileData   file data
     * @param string $fileKey   key to fileData to get the file name, defaulting to 'depotFile'
     * @return string
     */
    public function __invoke(array $fileData, string $fileKey = self::DEPOT_FILE) : string
    {
        return $this->getView()->escapeHtml(self::decode($fileData, $fileKey));
    }

    /**
     * Gets whether the fileData is describing a stream spec treated as a file
     * @param array $fileData   file data
     * @return bool true if $fileData['type'] is 'stream'
     */
    public static function isStream(array $fileData) : bool
    {
        return isset($fileData[self::TYPE]) && $fileData[self::TYPE] === self::STREAM;
    }

    /**
     * Decode the file data adding the stream prefix if applicable
     * @param array $fileData   file data
     * @param string $fileKey   key to fileData to get the file name
     * @return string
     */
    public static function decode(array $fileData, string $fileKey) : string
    {
        return (self::isStream($fileData) ? self::STREAM_PREFIX: '') . File::decodeFilespec($fileData[$fileKey]);
    }
}
