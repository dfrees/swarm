<?php
/**
 * Diffs two arbitrary files in the depot.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\File;

use P4\Connection\Exception\CommandException;
use P4\Exception as P4Exception;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Filter\Utf8;
use P4\Log\Logger;
use P4\Model\Connected\ConnectedAbstract;
use P4\Spec\Stream;

class Diff extends ConnectedAbstract
{
    const   IGNORE_WS     = 'ignoreWs';
    const   UTF8_CONVERT  = 'convert';
    const   UTF8_SANITIZE = 'sanitize';
    const   LINES         = 'lines';
    const   CUT           = 'isCut';
    const   SAME          = 'isSame';
    const   ERROR         = 'isError';
    const   VALUE         = 'value';
    const   TYPE          = 'type';
    const   LINE_END      = 'lineEnd';
    const   RIGHT_LINE    = 'rightLine';
    const   LEFT_LINE     = 'leftLine';
    const   HEADER        = 'header';

    // Determines the type of data returned
    const   RAW_DIFF = 'rawDiff'; // Determines if we return raw or processed diff2 data
    const   SUMMARY  = 'summary';  // Determines if we return summary info

    // Keys for the summary array
    const   SUMMARY_ADDS    = 'adds';
    const   SUMMARY_DELETES = 'deletes';
    const   SUMMARY_UPDATES = 'updates';
    const   SUMMARY_LINES   = 'summary_lines';

    // Diff types
    const   META     = 'meta';
    const   NO_DIFF  = 'same';
    const   DELETE   = 'delete';
    const   ADD      = 'add';
    const   MOVE_ADD = 'move/add';

    // Flags for diff2 options
    const   STREAM_DIFF           = '-As';
    const   FORCE_BINARY_DIFF     = '-t';
    const   UNIFIED_MODE          = '-du';
    const   SUMMARY_MODE          = '-ds';
    const   IGNORE_ALL_WHITESPACE = '-dw';
    const   IGNORE_WHITESPACE     = '-db';
    const   IGNORE_LINE_ENDINGS   = '-dl';

    // Revision constants (P4 keyword revision identifiers)
    const REVISION_NONE = '#none';
    const REVISION_HEAD = '#head';
    const REVISION_HAVE = '#have';

    // Defaults
    // We expect 'lines' to come in as a param but for legacy reasons we need to make sure to have a default of 5
    const DEFAULT_CONTEXT_LINES = 5;

    /**
     * Compare left/right files.
     *
     * @param   File    $right      optional - right-hand file
     * @param   File    $left       optional - left-hand file
     * @param   array   $options    optional - influence diff behavior
     *                                    LINES - number of context lines, (defaults to 5)
     *                                IGNORE_WS - ignore whitespace and line-ending changes (defaults to 0)
     *                                            1 -> IGNORE_ALL_WHITESPACE
     *                                            2 -> IGNORE_WHITESPACE
     *                                            3 -> IGNORE_LINE_ENDINGS
     *                                RAW_DIFFS - if true, get raw diffs
     *                                SUMMARY   - if true and RAW_DIFFS is also true, get summary info as well
     *                             UTF8_CONVERT - attempt to covert non UTF-8 to UTF-8
     *                            UTF8_SANITIZE - replace invalid UTF-8 sequences with �
     *                            SUMMARY_LINES - if we are getting the summary counts of changes will be based on
     *                                            chunks unless this value is true in which case it will be based
     *                                            on lines
     * @return  array   array with three or five elements:
     *                     lines - added/deleted and contextual (common) lines
     *                             for RAW_DIFFS, will be in a unified git format
     *                     isCut - true if lines exceed max filesize (>1MB)
     *                    isSame - true if left and right file contents are equal
     *                    header - header in unified git format (only included if RAW_DIFFS is true)
     *                   summary - array of number of adds, deletes and edits (only included if SUMMARY is true)
     * @throws Exception\Exception
     * @throws P4Exception
     */
    public function diff(File $right = null, File $left = null, array $options = [])
    {
        $options += [
            static::LINES         => static::DEFAULT_CONTEXT_LINES,
            static::IGNORE_WS     => 0,
            static::UTF8_CONVERT  => false,
            static::UTF8_SANITIZE => false,
            static::RAW_DIFF      => false,
            static::SUMMARY       => false
        ];

        if (!$right && !$left) {
            throw new \InvalidArgumentException("Cannot diff. Must specify at least one file to diff.");
        }

        $diff = [
            static::LINES => [],
            static::CUT   => false,
            static::SAME  => false
        ];

        // only examine contents if both sides are non-binary and at least one has content
        $leftIsBinary    = $left  && $left->isBinary();
        $leftHasContent  = $left  && !$left->isDeletedOrPurged();
        $rightIsBinary   = $right && $right->isBinary();
        $rightHasContent = $right && !$right->isDeletedOrPurged();
        if (!$leftIsBinary && !$rightIsBinary && ($leftHasContent || $rightHasContent)) {
            // if only one file given or either file was deleted/purged,
            // can't use diff2, must print the file contents instead.
            if (!$left || !$right || $left->isDeletedOrPurged() || $right->isDeletedOrPurged()) {
                $diff = $this->diffAddDelete($diff, $right, $left, $options);
            } else {
                $diff = $this->diffEdit($diff, $right, $left, $options);
            }
        }

        // compare digests if we have no diff lines (need both sides)
        if (!$diff[static::LINES] && $left && $right) {
            $leftDigest         = $left->hasStatusField('digest')  ? $left->getStatus('digest')  : null;
            $rightDigest        = $right->hasStatusField('digest') ? $right->getStatus('digest') : null;
            $diff[static::SAME] = $leftDigest === $rightDigest;
        }

        return $diff;
    }
    /**
     * Compare from/to stream spec files and return diff
     *
     * @param   string    $to         right-hand stream spec.
     * @param   string    $from       left-hand stream spec.
     * @param   array     $options    influences diff behavior.
     * @return  array
     * @throws  P4Exception
     */
    public function diffStream($to, $from, $options)
    {
        $options += [
            static::LINES         => static::DEFAULT_CONTEXT_LINES,
            static::IGNORE_WS     => 0,
            static::UTF8_CONVERT  => false,
            static::UTF8_SANITIZE => false,
            static::RAW_DIFF      => false,
            static::SUMMARY       => false
        ];

        $diff = [
            static::LINES => [],
            static::CUT   => false,
            static::SAME  => false

        ];

        $diff = $this->diffStreamEdit($diff, $to, $from, $options);

        $diff[Diff::SAME] = count($diff[Diff::LINES]) === 0;
        return $diff;
    }

    /**
     * Run p4 diff2 against left/right files and parse output into array.
     * Note: this asks for normal output rather than ztag
     * This will return completely different output depending on whether the RAW_DIFF option is specified or not.
     * If RAW_DIFF is specified, it will skip the processDiffEdit step and return an array in the following form:
     *     [
     *         static::RAW_DIFF => [
     *             <header line>,
     *             <chunk1>,
     *             <chunk2>,
     *             ...
     *         ],
     *         static::SUMMARY => [
     *             static::ADDS    => <num adds>,
     *             static::DELETES => <num deletes>,
     *             static::UPDATES => <num updates>,
     *         ]
     *     ]
     *
     * @param   array   $diff       diff result array we are building.
     * @param   File    $right      right-hand file.
     * @param   File    $left       left-hand file.
     * @param   array   $options    influences diff behavior.
     * @return  array   diff result with lines added (if raw diff is not specified).
     * @throws  P4Exception
     */
    protected function diffEdit(array $diff, File $right, File $left, array $options)
    {
        $whitespace = $this->getWhitespaceFlag($options[static::IGNORE_WS]);
        $leftSpec   = $left->getFilespec();
        $rightSpec  = $right->getFilespec();

        // Set the flags for the diff2 command
        $flags = array_merge(
            $whitespace,
            [
                static::FORCE_BINARY_DIFF,
                static::UNIFIED_MODE . $options[Diff::LINES],
                $leftSpec,
                $rightSpec
            ]
        );

        $diffEdit = $this->getConnection()->run('diff2', $flags, null, false)->getData();
        $diff     = static::processDiffEdit($diff, $diffEdit, $options);

        // Get header & summary info
        if ($options[static::RAW_DIFF]) {
            // Get the unified header
            $leftName  = $left->getDepotFilenameWithRevision();
            $rightName = $right->getDepotFilenameWithRevision();
            $header    = "--- a/$leftName\n+++ b/$rightName";

            // For now we only concern ourselves with a summary if a raw diff is specified in the options
            $summary = [];
            if ($options[static::SUMMARY]) {
                $flags      = array_merge(
                    $whitespace,
                    [static::FORCE_BINARY_DIFF, static::SUMMARY_MODE, $leftSpec, $rightSpec]
                );
                $rawSummary = $this->getConnection()->run('diff2', $flags, null, false)->getData();
                $summary    = $this->processSummary($rawSummary, $options);
            }

            $diff[static::HEADER]  = $header;
            $diff[static::SUMMARY] = $summary;
        }

        return $diff;
    }

    /**
     * Converts diff2 summary output into a simple array
     * @param array $rawSummary     diff2 summary output
     * @param array $options        current options. Used in this function to determine if summary counts relate to
     *                              chunks or diffs. Chunks is default unless $options[self::SUMMARY_LINES] is true
     * @return array
     */
    protected function processSummary($rawSummary, $options)
    {
        // Summary can be based on lines or chunks. Default is chunks unless
        // self::SUMMARY_LINES is set to true
        $options += [
            self::SUMMARY_LINES => false
        ];
        $chunks   = $options[self::SUMMARY_LINES] !== true;
        $summary  = [
            static::SUMMARY_ADDS    => 0,
            static::SUMMARY_DELETES => 0,
            static::SUMMARY_UPDATES => 0
        ];

        if (count($rawSummary) > 1) {
            $pattern = "#add (\d+) chunks (\d+).+\ndeleted (\d+) chunks (\d+).+\nchanged (\d+) chunks (\d+) / (\d+)#";
            preg_match($pattern, $rawSummary[1], $matches);
            if ($matches) {
                $summary = [
                    static::SUMMARY_ADDS    => (int)$matches[$chunks ? 1 : 2],
                    static::SUMMARY_DELETES => (int)$matches[$chunks ? 3 : 4],
                    static::SUMMARY_UPDATES => ($chunks ? (int)$matches[5] : max((int)$matches[6], (int)$matches[7]))
                ];
            }
        }

        return $summary;
    }

   /**
     * Run p4 diff2 against from/to stream, parse output into array and return
     * with lines added
     *
     * @param   array     $diff       diff result array we are building.
     * @param   string    $to         right-hand stream spec.
     * @param   string    $from       left-hand stream spec.
     * @param   array     $options    influences diff behavior.
     * @return  array
     * @throws  P4Exception
     */
    protected function diffStreamEdit($diff, $to, $from, $options)
    {
        $whitespace = $this->getWhitespaceFlag($options[static::IGNORE_WS]);
        $from       = str_replace('#', '@', str_replace(self::REVISION_HEAD, '', $from));
        // Set the flags for the diff2 command
        $flags = array_merge(
            $whitespace,
            [
                static::FORCE_BINARY_DIFF,
                static::UNIFIED_MODE . $options[Diff::LINES],
                Diff::STREAM_DIFF,
                $from,
                $to
            ]
        );

        try {
            $diffStreamEdit = $this->getConnection()->run('diff2', $flags, null, false)->getData();
            $diff           = static::processDiffEdit($diff, $diffStreamEdit, $options);
            // Get header & summary info
            if ($options[static::RAW_DIFF]) {
                // Get the unified header
                $header = "--- a/$from\n+++ b/$to";

                // For now we only concern ourselves with a summary if a raw diff is specified in the options
                $summary = [];
                if ($options[static::SUMMARY]) {
                    $flags      = array_merge(
                        $whitespace,
                        [static::FORCE_BINARY_DIFF, Diff::STREAM_DIFF, static::SUMMARY_MODE, $from, $to]
                    );
                    $rawSummary = $this->getConnection()->run('diff2', $flags, null, false)->getData();
                    $summary    = $this->processSummary($rawSummary, $options);
                }

                $diff[static::HEADER]  = $header;
                $diff[static::SUMMARY] = $summary;
            }
        } catch (CommandException $ce) {
            Logger::log(
                Logger::DEBUG,
                "Diff command failed. Considering it as new stream add."
            );
            // stream spec add, new stream
            $diff = Diff::streamSpecAdd(
                $diff,
                $to,
                $this->getConnection()->run(
                    Stream::SPEC_TYPE,
                    [
                        "-o",
                        $to
                    ],
                    null,
                    false
                )->getData(),
                $options
            );
        }
        return $diff;
    }

   /**
     * Maps an integer value to a flag that can be used by the diff2 command for ignoring whitespace or line endings.
     *    0               => [] no ignore flags
     *    1               => ['-dw'] ignore all whitespace
     *    2               => ['-db'] ignore whitespace changes
     *    3               => ['-dl'] ignore line endings
     *    any other value => no ignore flags
     *
     * @param mixed   $ignoreWs either unset, false or an int between 0 and 3, inclusive
     * @return array
     */
    protected function getWhitespaceFlag($ignoreWs)
    {
        switch ($ignoreWs) {
            case 1:
                $flag = [static::IGNORE_ALL_WHITESPACE];
                break;
            case 2:
                $flag = [static::IGNORE_WHITESPACE];
                break;
            case 3:
                $flag = [static::IGNORE_LINE_ENDINGS];
                break;
            default:
                $flag = [];
        }
        return $flag;
    }

    /**
     * Break the response from a diff2 into lines, in preparation for being rendered into a diff pane.
     * It is expected that the format of the output is:
     *     ==== //cherwell/main@12123 - //cherwell/main@12127 ==== content
     *     @@ -34,11 +34,12 @@\n Parent:    none\n
     *     ...
     * The first line, file name, is discarded; the rest are concatenated, have utf8 conversions applied and then
     * split into an array of lines with metadata that can be used in the rendering process.
     * @param $diff         - an array contining existing diff data
     * @param $diff2Output  - the raw output of a diff2 command
     * @param $options      - the original options for the diff process, includes the utf8 settings for this function
     * @return mixed        - the original $diff array with the lines attribute set
     */
    public static function processDiffEdit($diff, $diff2Output, $options)
    {
        // diff output puts a file header in the first data block
        // (which we skip) and the diffs in one or more following blocks.
        $diffs = implode("\n", array_slice($diff2Output, 1));

        // if we are requested to convert or replace; do so prior to split
        if ($options[static::UTF8_CONVERT] || $options[static::UTF8_SANITIZE]) {
            $filter = new Utf8Filter;
            $diffs  = $filter->setConvertEncoding($options[static::UTF8_CONVERT])
                             ->setReplaceInvalid($options[static::UTF8_SANITIZE])
                             ->setNonUtf8Encodings(
                                 isset($options[Utf8::NON_UTF8_ENCODINGS])
                                    ? $options[Utf8::NON_UTF8_ENCODINGS]
                                    : Utf8::$fallbackEncodings
                             )
                             ->filter($diffs);
        }

        // parse diff block into lines
        // capture line-ending so we can detect line-end changes.
        $types     = array('@' => static::META, ' ' => static::NO_DIFF, '-' => static::DELETE, '+' => static::ADD);
        $lines     = preg_split("/(\r\n|\n|\r)/", $diffs, null, PREG_SPLIT_DELIM_CAPTURE);
        $leftLine  = null;
        $rightLine = null;
        $rawLines  = [];
        $numLines  = count($lines);
        for ($i = 0; $i < $numLines; $i += 2) {
            $line = $lines[$i];
            $end  = isset($lines[$i+1]) ? $lines[$i+1] : '';

            // skip empty or unexpected output
            if (!strlen($line) || !isset($types[$line[0]])) {
                continue;
            }

            $type = $types[$line[0]];

            // extract starting left/right line numbers from meta block
            // meta block has the format of "@@ -133,29 +133,27 @@"
            if ($type === static::META) {
                preg_match('/@@ \-([0-9]+),[0-9]+ \+([0-9]+),[0-9]+ @@/', $line, $matches);
                $leftLine  = $matches[1];
                $rightLine = $matches[2];
            }
            if (isset($options[static::RAW_DIFF]) && $options[static::RAW_DIFF]) {
                if ($type === static::META && !empty($rawLines)) {
                    $diff[static::LINES][] = implode('', $rawLines);
                    $rawLines              = [];
                }
                $rawLines[] = $line . $end;
            } else {
                $diff[static::LINES][] = [
                    static::VALUE      => $line,
                    static::TYPE       => $type,
                    static::LINE_END   => $end,
                    static::LEFT_LINE  => ($type === static::NO_DIFF || $type === static::DELETE) ? $leftLine++  : null,
                    static::RIGHT_LINE => ($type === static::NO_DIFF || $type === static::ADD)    ? $rightLine++ : null
                ];
            }
        }

        if (isset($options[static::RAW_DIFF]) && $options[static::RAW_DIFF] && !empty($rawLines)) {
            $diff[static::LINES][] = implode('', $rawLines);
        }

        return $diff;
    }

    /**
     * Get file contents of added/deleted files.
     *
     * @param array       $diff       diff result array we are building.
     * @param File        $right      optional - right-hand file.
     * @param File        $left       optional - left-hand file.
     * @param array|null  $options    influences diff behavior.
     * @return  array   diff result with lines added.
     * @throws Exception\Exception
     */
    protected function diffAddDelete(array $diff, File $right = null, File $left = null, $options = null)
    {
        // contents must come from the side we have, or the side that is not deleted/purged
        // contents from right imply add, contents from left imply delete
        $file  = $right && !$right->isDeletedOrPurged() ? $right : $left;
        $isAdd = $file === $right;

        // get file contents truncated to max filesize to avoid consuming too much memory.
        $options          += array(File::MAX_SIZE => File::MAX_SIZE_VALUE);
        $content           = $file->getDepotContents($options, $cropped);
        $name              = $file->getDepotFilenameWithRevision();
        $diff[static::CUT] = $cropped ? $options[File::MAX_SIZE] : false;
        return $this->diffContentGenerator($diff, $name, $isAdd, $content, $options);
    }

    /**
     * Get contents of stream spec.
     * @param   array       $diff         - an array containing existing diff data
     * @param   string      $right        - right-hand spec.
     * @param   array       $specOutPut   - the raw output of a stream command
     * @param   array|null  $options      - influences diff behavior.
     * @return  array diff result with lines added in stream spec
     */
    public static function streamSpecAdd($diff, $right, $specOutPut, $options): array
    {
        // get file contents truncated to max filesize to avoid consuming too much memory.
        $options          += array(File::MAX_SIZE => File::MAX_SIZE_VALUE);
        $diff[static::CUT] = false; // no need to cut the data as spec are majorly normal size
        return (new Diff)->diffContentGenerator($diff, $right, true, $specOutPut[0], $options);
    }

    /**
     * @param   array       $diff         - an array containing existing diff data
     * @param   string      $name         - name of the file/spec
     * @param   bool        $isAdd        - It's add or delete operation
     * @param   string      $content      - Content of file or spec
     * @param   array       $options      - influences diff behavior.
     * @return  array return the diff content for add/delete for file/stream spec
     */
    protected function diffContentGenerator($diff, $name, $isAdd, $content, $options): array
    {
        $diff[static::LINES][] = [
            static::VALUE      => null,
            static::TYPE       => static::META,
            static::LEFT_LINE  => null,
            static::RIGHT_LINE => null
        ];
        // Set loop vars
        $diffMeta = &$diff[static::LINES][count($diff[static::LINES]) - 1];
        $sign     = $isAdd ? '+' : '-';
        $type     = $isAdd ? static::ADD : static::DELETE;
        $lines    = preg_split("/(\r\n|\n|\r)/", $content, null, PREG_SPLIT_DELIM_CAPTURE);
        $rawLines = [];
        $count    = 0;
        for ($i = 0; $i < count($lines); $i += 2) {
            $line  = $lines[$i];
            $value = $sign . $line;
            if (isset($options[static::RAW_DIFF]) && $options[static::RAW_DIFF]) {
                $rawLines[] = $value;
            } else {
                $end                   = isset($lines[$i + 1]) ? $lines[$i + 1] : '';
                $diff[static::LINES][] = array(
                    static::VALUE      => $value,
                    static::TYPE       => $type,
                    static::LINE_END   => $end,
                    static::LEFT_LINE  => $isAdd ? null : $count + 1,
                    static::RIGHT_LINE => $isAdd ? $count + 1 : null
                );
            }
            $count++;
        }

        $meta = '@@ ' . ($isAdd ? '-1,0 +1,' . $count : '-1,' . $count . ' +1,0') . ' @@';

        if (isset($options[static::RAW_DIFF]) && $options[static::RAW_DIFF]) {
            $header        = '--- a/' . ($isAdd ? "dev/null" : $name) . "\n" . '+++ b/' . ($isAdd ? $name : 'dev/null');
            $rawLineString = $meta . "\n" . implode("\n", $rawLines);
            $summary       = [];
            if ($options[static::SUMMARY]) {
                $summary = [
                    static::SUMMARY_ADDS    => $isAdd ? $count : 0,
                    static::SUMMARY_DELETES => $isAdd ? 0 : $count,
                    static::SUMMARY_UPDATES => 0
                ];
            }

            $diff[static::HEADER]  = $header;
            $diff[static::LINES]   = [$rawLineString];
            $diff[static::SUMMARY] = $summary;
        } else {
            $diffMeta[static::VALUE] = $meta;
        }

        return $diff;
    }
}
