<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class AuthorChange extends AbstractHelper
{
    protected $changes   = null;
    protected $baseUrl   = null;
    protected $plainText = false;

    /**
     * Entry point for helper. See __toString() for rendering behavior.
     *
     * @param   array   $changes    array of reviewer modifications to describe
     * @return  AuthorChange     provides a fluent interface
     */
    public function __invoke($changes)
    {
        $this->changes = $changes;
        return $this;
    }

    /**
     * Given the old and new authors of the review
     * The basic style output (which will all combine into one flowing paragraph) is:
     *
     * Changed from phavlik to kboyd.
     *
     * @return  string  the formatted description of author change
     */
    public function __toString()
    {
        $view    = $this->getView();
        $changes = (array) $this->changes + [
            'oldAuthor' => null,
            'newAuthor' => null
            ];

        $authors = [$changes[ 'oldAuthor'], $changes[ 'newAuthor']];

        if (!$this->plainText) {
            foreach ($authors as &$author) {
                $author = $view->userLink($author, false, $this->getBaseUrl());
            }
        }

        $description = $view->plugin('t')->getTranslator()->t(
            "Changed from %s to %s.",
            $authors
        ) . ' ';

        // restore default settings after each 'run'
        $this->changes   = null;
        $this->baseUrl   = null;
        $this->plainText = false;

        return trim($description);
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path, etc) or null
     * @return  AuthorChange to maintain a fluent interface
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * The base url that will be prepended to otherwise relative urls.
     *
     * @return  string|null     the base url to prepend (e.g. http://example.com, /path, etc) or null
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Set the output mode to render description as plain-text or html.
     *
     * @param   bool    $plainText      true for plain-text output - defaults to false for html
     * @return  AuthorChange to maintain a fluent interface
     */
    public function setPlainText($plainText)
    {
        $this->plainText = (bool) $plainText;
        return $this;
    }

    /**
     * The current plain-text setting.
     *
     * @return  bool    true for plain-text output, false for html
     */
    public function getPlainText()
    {
        return $this->plainText;
    }
}
