<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Validator;

use Application\Helper\ArrayHelper;
use Laminas\Validator\Identical;

/**
 * Check if the given value is identical to the expect value.
 */
class IdenticalCaseSensitive extends Identical
{
    const CASE_SENSITIVE = 'caseSensitive';
    const TOKEN          = 'token';
    const STRICT         = 'strict';

    // If we are running against a case sensitive server.
    protected $caseSensitive;

    /**
     * IdenticalCaseSensitive constructor.
     * @param array $options
     *            ['token'] -> this is the value we expect to get.
     *    ['caseSensitive'] -> this is if we are running caseSensitive or not.
     *           ['strict'] -> if we should be strict.
     */
    public function __construct($options = [])
    {
        $this->setCaseSensitive($options[self::CASE_SENSITIVE] ?? true);
        parent::__construct($options);
    }

    /**
     * Set the case sensitivity so we can use it in the isValid
     * @param bool $caseSensitive
     */
    public function setCaseSensitive(bool $caseSensitive)
    {
        $this->caseSensitive = $caseSensitive ?? false;
    }
    /**
     * Set token against which to compare
     * @param  mixed $token the token
     * @return $this
     */
    public function setToken($token): IdenticalCaseSensitive
    {
        $this->token = $this->caseSensitive ? $token : ArrayHelper::lowerCase($token);
        return $this;
    }

    /**
     * Compare the value against the token set taking into account the case sensitivity.
     * @param mixed     $value      value to test
     * @param null      $context    context
     * @return bool
     */
    public function isValid($value, $context = null): bool
    {
        $value = $this->caseSensitive ? $value : ArrayHelper::lowerCase($value);
        return parent::isValid($value, $context);
    }
}
