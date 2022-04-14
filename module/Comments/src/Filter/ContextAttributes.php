<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Filter;

use Application\Connection\ConnectionFactory;
use Application\Model\IModelDAO;
use Comments\Validator\Context;
use Laminas\Filter\AbstractFilter;
use P4\Connection\AbstractConnection;

class ContextAttributes extends AbstractFilter
{
    // Context attributes
    const REVIEW_VERSION      = 'version';
    const LEFT_LINE           = 'leftLine';
    const RIGHT_LINE          = 'rightLine';
    const FILE_PATH           = 'file';
    const FILE_CONTENT        = 'content';
    const ATTRIBUTE           = 'attribute';
    const DESCRIPTION_CONTEXT = 'description';
    const COMMENT             = 'comment';
    const REVIEW              = 'review';
    const CHANGE              = 'change';
    const LINE                = 'line';
    const NAME                = 'name';
    const MD5                 = 'md5';

    public function __construct($topicInput)
    {
        $this->topicInput = $topicInput;
    }

    /**
     * Normalise the content of the context field
     * @param mixed $value
     * @return array|mixed
     */
    public function filter($value)
    {
        // Get rid of extraneous values
        return array_intersect_key(
            $value??[],
            array_fill_keys(
                isset($value[self::ATTRIBUTE])
                ? [ self::ATTRIBUTE ]
                : ([
                    self::COMMENT,
                    self::REVIEW_VERSION,
                    self::FILE_PATH,
                    self::LEFT_LINE,
                    self::RIGHT_LINE,
                    self::FILE_CONTENT
                ]),
                'expected'
            )
        );
    }
}
