<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */


namespace Application\View\Helper;

use Application\Filter\Preformat as PreformatFilter;
use Application\Filter\WordWrap as WordWrapFilter;

class Preformat extends AbstractHelper
{
    protected $value           = null;
    protected $linkify         = true;
    protected $emojify         = true;
    protected $markdown        = false;
    protected $markdownLimited = false;
    protected $baseUrl         = null;
    protected $wordWrap        = null;

    /**
     * Attempts to escape and adjust the passed text so it will respect the
     * original whitespace and line breaks but will still allow the text to
     * be wrapped should it over-run its containing element.
     *
     * @param   string  $value  the text to preformat (and linkify)
     * @return  string  the preformatted and linkified result
     */
    public function __invoke($value)
    {
        $this->value = $value;
        return $this;
    }

    /**
     * Turn helper into string
     *
     * @return string
     */
    public function __toString()
    {
        $filter = new PreformatFilter(
            $this->services,
            $this->baseUrl ?: $this->getView()->basePath()
        );
        $value  = $filter->setLinkify($this->linkify)
                         ->setEmojify($this->emojify)
                         ->setMarkdown($this->markdown, $this->markdownLimited)
                         ->filter($this->value);

        // wrap words to limit lines length in the output text
        if ($this->wordWrap) {
            $filter = new WordWrapFilter;
            $value  = $filter->setWidth($this->wordWrap)
                             ->filter($value);
        }

        // restore our default settings for next run
        $this->value    = null;
        $this->baseUrl  = null;
        $this->linkify  = true;
        $this->emojify  = true;
        $this->wordWrap = null;

        return $value;
    }

    /**
     * If enabled (default) the passed text will be linkified before it is preformatted.
     *
     * @param   bool        $enabled    true to enable linkification, false otherwise
     * @return  Preformat   to maintain a fluent interface
     */
    public function setLinkify($enabled)
    {
        $this->linkify = (bool)$enabled;
        return $this;
    }

    /**
     * If enabled (default) the passed text will be emojified before it is preformatted.
     *
     * @param   bool        $enabled    true to enable emojification, false otherwise
     * @return  Preformat   to maintain a fluent interface
     */
    public function setEmojify($enabled)
    {
        $this->emojify = (bool)$enabled;
        return $this;
    }

    /**
     * If enabled the passed text will be passed through markdown.
     *
     * @param   bool        $enabled    true to enable emojification, false otherwise
     * @param   bool        $limited    true to disable certain styles in markdown, false otherwise
     * @return  Preformat   to maintain a fluent interface
     */
    public function setMarkdown($enabled, $limited = false)
    {
        $this->markdown        = (bool)$enabled;
        $this->markdownLimited = (bool)$enabled;
        return $this;
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path) or null
     * @return  Preformat       to maintain a fluent interface
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * Set max length of each lines in the passed text.
     * To disable this feature, set the value to zero or null.
     *
     * @param   int|null    $width      maximum length of each line in the output text
     * @return  Preformat   to maintain fluent interface
     */
    public function setWordWrap($width)
    {
        $this->wordWrap = $width;
        return $this;
    }
}
