<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use P4\Filter\Utf8;
use P4\Validate\MultibyteString;
use Laminas\View\Helper\AbstractHelper;

class Utf8Filter extends AbstractHelper
{
    protected $utf8Filter = null;

    /**
     * Filter utf-8 input, invalid UTF-8 byte sequences will be replaced with
     * an inverted question mark.
     *
     * @param   string|null     $value  the utf-8 input to filter
     * @returns string          the filtered result
     */
    public function __invoke($value)
    {
        $this->utf8Filter = $this->utf8Filter ?: new Utf8;
        return $this->utf8Filter->filter($value);
    }

    /**
     * Convert the string into UTF8 and encode into json
     *
     * @param   string       $contents the content that couldn't be encode.
     * @return  json_encode  The converted content and encoded into json
     */
    public function convertToUTF8($contents)
    {
        // Attempt to json encode the content.
        $expanded = json_encode($contents);
        // build the jsonError array and added additional one if they exist.
        $jsonErrors = [JSON_ERROR_CTRL_CHAR, JSON_ERROR_UTF8];

        if (defined('JSON_ERROR_UNSUPPORTED_TYPE')) {
            $jsonErrors[] = JSON_ERROR_UNSUPPORTED_TYPE;
        }
        if (defined('JSON_ERROR_UTF16')) {
            $jsonErrors[] = JSON_ERROR_UTF16;
        }

        $lastJsonError = json_last_error();

        if (in_array($lastJsonError, $jsonErrors)) {
            // Don't know whether other errors need to be circumvented
            $converted = [];
            foreach ($contents as $line => $content) {
                // Convert content into multi byte encoding.
                $converted[$line] = MultibyteString::convertEncoding($content, 'UTF-8');
            }
            if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
                $expanded = json_encode($converted, JSON_PARTIAL_OUTPUT_ON_ERROR);
            } else {
                $expanded = json_encode($converted);
            }
        }
        return $expanded;
    }
}
