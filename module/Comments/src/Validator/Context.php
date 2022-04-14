<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Validator;

use Comments\Filter\ContextAttributes;
use Comments\Filter\CreateComment;
use Comments\Model\IComment;
use Laminas\Validator\AbstractValidator;

/**
 * Validate that a context object contains the correct data, with respect to the type of comment being created
 * - a review comment
 * - a change comment
 * @package Comments\Validator
 */
class Context extends AbstractValidator
{
    // Message keys
    const BAD_CONTEXT                    = 'badContext';
    const BAD_COMMENT_ID                 = 'badCommentId';
    const BAD_VERSION_NUMBER             = 'badVersionNumber';
    const INLINE_COMMENT_MISSING_CONTEXT = 'inlineCommentMissingContext';
    const LINE_NOT_POSITIVE_INTEGER      = 'lessThanZero';
    const LINE_NEEDS_START_AND_FINISH    = 'needsExactlyTwoElements';
    const START_LINE_AFTER_FINISH        = 'startLineAfterFinish';
    const BAD_ATTRIBUTE                  = 'badAttribute';

    // Messages
    protected $messageTemplates = [
        self::BAD_CONTEXT => 'A comment context must reference either a review or a change',
        self::BAD_COMMENT_ID => 'A comment reply must have a numeric comment id greater than zero',
        self::BAD_VERSION_NUMBER => 'A review comment must refer to a numeric review version',
        self::INLINE_COMMENT_MISSING_CONTEXT =>
            'An inline comment must have a file name, type, content, right and left line numbers',
        self::LINE_NOT_POSITIVE_INTEGER => 'Line numbers must be integer values greater than 0',
        self::LINE_NEEDS_START_AND_FINISH => 'A line range must have a start and finish line number',
        self::START_LINE_AFTER_FINISH => 'The first element of the line range must not be greater than the second',
        self::BAD_ATTRIBUTE => "The only supported value is 'description'"
    ];

    // Topic data
    private $topicInput;

    /**
     * @inheritDoc
     */
    public function __construct(array $options = null)
    {
        $this->topicInput = $options[IComment::TOPIC] ?? null;
        parent::__construct($options);
    }

    /**
     * @inheritDoc
     */
    public function isValid($value): bool
    {
        $topic = [];

        if ($this->topicInput) {
            preg_match(CreateComment::TOPIC_REGEX, $this->topicInput->getValue(), $topic);
        }

        if (isset($value[ContextAttributes::COMMENT])) {
            if (!is_int($value[ContextAttributes::COMMENT]) || $value[ContextAttributes::COMMENT] <= 0) {
                $this->error(self::BAD_COMMENT_ID);
                return false;
            }
        }

        /*
         * The only valid contexts are those for reviews or changes, each of which
         * has separate formats.
         */
        switch ($topic[2] ?? null) {
            case IComment::TOPIC_REVIEWS:
                return $this->isReviewContextValid($value);
            case IComment::TOPIC_CHANGES:
                return $this->isChangeContextValid($value);
            case null:
                // Comment replies are allowed to have no topic value in the request
                if (isset($value[ContextAttributes::COMMENT])) {
                    return true;
                }

                $this->error(self::BAD_CONTEXT, $this->messageTemplates[self::BAD_CONTEXT]);
                break;
            default:
                $this->error(self::BAD_CONTEXT, $this->messageTemplates[self::BAD_CONTEXT]);
                break;
        }

        return false;
    }

    /**
     * Validate that all of the review context data
     *  - where attribute is provided it is for description
     *  - version is a positive integer
     *  - file context data is consistent
     * @param $value
     * @return bool
     */
    protected function isReviewContextValid($value): bool
    {
        // Description comments need nothing other than attribute
        if (isset($value[ContextAttributes::ATTRIBUTE])) {
            return $this->isAttributeValid($value);
        }

        // Review must have a version number
        $reviewVersion = $value[ContextAttributes::REVIEW_VERSION] ?? null;
        if (isset($reviewVersion)) {
            if (!is_int($reviewVersion)) {
                $this->error(self::BAD_VERSION_NUMBER);
                return false;
            }
        }

        // Check the integrity of a file context
        if (isset($value[ContextAttributes::FILE_PATH])) {
            return $this->isFileContextValid($value);
        }
        return true;
    }

    /**
     * Validate that all of the change context data
     *  - where attribute is provided it is for description
     *  - file context data is consistent
     * @param $value
     * @return bool
     */
    protected function isChangeContextValid($value): bool
    {
        // Description comments need nothing other than attribute
        if (isset($value[ContextAttributes::ATTRIBUTE])) {
            return $this->isAttributeValid($value);
        }

        // Check the integrity of a file context
        if (isset($value[ContextAttributes::FILE_PATH])) {
            return $this->isFileContextValid($value);
        }
        return true;
    }

    /**
     * Validate that an inline file context contains all of the required fields.
     *  - Where there is one of [content/left/right], they must all be present
     *  - left and right must either be a number or an array of 2 numbers [lower,higher], all greater than 0
     * @param $value
     * @return bool
     */
    protected function isFileContextValid($value)
    {
        $contentFields = array_filter(
            array_keys($value),
            function ($key) {
                switch ($key) {
                    case ContextAttributes::FILE_CONTENT:
                    case ContextAttributes::LEFT_LINE:
                    case ContextAttributes::RIGHT_LINE:
                        return true;
                    default:
                        return false;
                }
            }
        );
        if ($contentFields) {
            if (count($contentFields) !== 3) {
                $this->error(self::INLINE_COMMENT_MISSING_CONTEXT);
                return false;
            }
            // Check the line numbers
            foreach ([ContextAttributes::LEFT_LINE, ContextAttributes::RIGHT_LINE] as $lineNumber) {
                if (!$this->isLineValid($value[$lineNumber])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validate that a line value is either an integer greater than zero or an array of
     * two integers greater than zero with the second integer not earlier than the first
     * @param $line
     * @return bool
     */
    protected function isLineValid($line)
    {
        if (is_array($line)) {
            if (count($line)!=2) {
                $this->error(self::LINE_NEEDS_START_AND_FINISH);
                return false;
            } elseif (!is_int($line[0]) || $line[0] <= 0 ||
                !is_int($line[1]) || $line[1] <= 0) {
                $this->error(self::LINE_NOT_POSITIVE_INTEGER);
                return false;
            } elseif ($line[0] > $line[1]) {
                $this->error(self::START_LINE_AFTER_FINISH);
                return false;
            }
        } else {
            if ($line !== null && (!is_int($line) || $line <= 0)) {
                $this->error(self::LINE_NOT_POSITIVE_INTEGER);
                return false;
            }
        }
        return true;
    }

    /**
     * Validate that only allowed value of attribute is 'description'
     * @param $value
     * @return bool
     */
    protected function isAttributeValid($value): bool
    {
        if ($value[ContextAttributes::ATTRIBUTE] !== ContextAttributes::DESCRIPTION_CONTEXT) {
            $this->error(self:: BAD_ATTRIBUTE);
            return false;
        }

        return true;
    }
}
