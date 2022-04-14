<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Filter;

use Laminas\Validator\AbstractValidator;

/**
 * Class RevisionValidator
 * @package Files\Filter
 */
class RevisionValidator extends AbstractValidator
{
    const VALID_SPECIFIERS  = ["@", "@=", "#"];
    const MATCHER           = "/(^\@=|\@|\#)(.*)/";
    const INVALID_SPECIFIER = 'invalidRevisionSpecifier';
    const ERROR_MESSAGE     = "Invalid revision specifier [%s] must be one of [%s] followed by a change list number";

    private $translator;

    /**
     * RevisionValidator constructor.
     * @param $translator
     */
    public function __construct($translator)
    {
        parent::__construct();
        $this->translator = $translator;
    }

    /**
     * Tests if a revision specifier is valid. Specifiers should be in the form of either '@', '@=' followed by
     * a change list number or '#' followed by a revision number or 'head'.
     * The change list or revision number itself is not validated just the form of the value is assessed
     * @param mixed     $value      value to test
     * @return bool true if the specifier is valid.
     */
    public function isValid($value)
    {
        preg_match(self::MATCHER, $value, $matches);
        $valid = $matches
            && sizeof($matches) === 3
            && in_array($matches[1], self::VALID_SPECIFIERS)
            && (ctype_digit(strval($matches[2])) || $matches[2] === 'head');
        if (!$valid) {
            $this->abstractOptions['messages'][self::INVALID_SPECIFIER] =
                $this->translator->t(self::ERROR_MESSAGE, [$value, implode(', ', self::VALID_SPECIFIERS)]);
        }
        return $valid;
    }
}
