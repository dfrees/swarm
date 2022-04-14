<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\Validator\ArrayValuesValidator;

/**
 * Class EncodingValidator to validate the encoding on a test definition
 * @package TestIntegration\Filter
 */
class EncodingValidator extends ArrayValuesValidator
{
    const JSON             = 'json';
    const URL              = 'url';
    const XML              = 'xml';
    const ENCODINGS        = [self::JSON, self::URL, self::XML];
    const INVALID_ENCODING = 'invalidEncoding';

    /**
     * EncodingValidator constructor. Performs a case sensitive match on valid encoding values to determine validity
     * @param mixed     $translator     to translate messages
     */
    public function __construct($translator)
    {
        parent::__construct(
            $translator,
            self::ENCODINGS,
            self::INVALID_ENCODING,
            'encoding',
            [self::CASE_SENSITIVE => true]
        );
    }
}
