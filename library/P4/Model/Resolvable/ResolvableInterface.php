<?php
/**
 * A very basic interface to define the various resolve option
 * constants. Intended for use by P4\File\File and P4\Spec\Change.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\Model\Resolvable;

interface ResolvableInterface
{
    const RESOLVE_ACCEPT_MERGED     = 'acceptMerged';
    const RESOLVE_ACCEPT_YOURS      = 'acceptYours';
    const RESOLVE_ACCEPT_THEIRS     = 'acceptTheirs';
    const RESOLVE_ACCEPT_SAFE       = 'acceptSafe';
    const RESOLVE_ACCEPT_FORCE      = 'acceptForce';
    const IGNORE_WHITESPACE_CHANGES = 'ignoreWhitespaceChanges';
    const IGNORE_WHITESPACE         = 'ignoreWhitespace';
    const IGNORE_LINE_ENDINGS       = 'ingoreLineEndings';
}
