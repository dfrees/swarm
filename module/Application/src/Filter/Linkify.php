<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Filter;

use Application\Config\ConfigManager;
use Application\Config\ConfigException;
use Application\Escaper\Escaper;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Laminas\Filter\AbstractFilter;

class Linkify extends AbstractFilter implements InvokableService, ILinkify
{
    // Pattern to determine what can separate words for linking
    const WORD_PATTERN = '/([\s<>{}]+)/';

    protected $baseUrl;
    // Indication of whether we are currently within a code block
    protected $betweenCode      = false;
    protected static $callbacks = [];

    // blacklisted common terms (e.g. @todo) that are likely false-positives.
    protected static $blacklist = [
        '@see', '@todo', '@return', '@returns', '@param',
        '@throws', '@license', '@copyright', '@version'
    ];
    private $services;

    /**
     * Linkify constructor.
     * @param ContainerInterface    $services   application services
     * @param array|null            $options    linkify options. Supports 'base_url' for defining the base url
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $baseUrl        = "";
        if ($options && isset($options[self::BASE_URL])) {
            $baseUrl = $options[self::BASE_URL];
        }
        $this->setBaseUrl($baseUrl);
    }

    /**
     * Add a custom linkify callback. The passed 'callable' will be invoked
     * before the built in filters. It will receive the arguments:
     *  string  $trimmed    the trimmed word currently being process
     *  Escaper $escaper    the escaper that _must_ be used to sanitize result
     *  string  $last       the previous (trimmed) word or empty string
     *  string  $baseUrl    the pre-escaped base url (e.g. http://swarm, /path, etc)
     *
     * The escaper can return false to indicate it doesn't wish to linkify the
     * passed word. It can return a string to replace the word with a link e.g.:
     *  '<a href="/' . $escaper->escapeFullUrl($trimmed) . '">' . $escaper->escapeHtml($trimmed) . '</a>';
     * Note the string must be escaped!
     *
     * @param   callable    $callback   the callback to add
     * @param   string      $name       the index to add it under (replaces any existing entry)
     * @param   int|null    $min        the minimum input length to bother being called on or null/0 for all
     * @throws \InvalidArgumentException    if the passed callback or name are invalid
     */
    public static function addCallback($callback, $name, $min = 6)
    {
        if (!is_callable($callback) || !is_string($name) || !strlen($name) || !(is_int($min) || is_null($min))) {
            throw new \InvalidArgumentException(
                'Add callback expects a callable, a non-empty name and a min length to be passed.'
            );
        }

        static::$callbacks[$name] = [
            'callback' => $callback,
            'min'      => $min
        ];
    }

    /**
     * If a callback exists with the specified name it is removed. Otherwise no effect.
     */
    public static function removeCallback($name)
    {
        unset(static::$callbacks[$name]);
    }

    /**
     * Returns the specified callback callable or throws if the passed name is unknown/invalid.
     *
     * @param   string|null     $name       pass a string to get a specific callback or null for all
     * @return  callable|array  the requested callable or an array of all callbacks on null
     * @throws  \InvalidArgumentException   if a specific callback is requested but cannot be found
     */
    public static function getCallback($name = null)
    {
        if ($name === null) {
            $callables = [];
            foreach (static::$callbacks as $callable) {
                $callables[] = $callable['callback'];
            }

            return $callables;
        }

        if (!isset(static::$callbacks[$name])) {
            throw new \InvalidArgumentException(
                'Unknown callback name specified.'
            );
        }

        return static::$callbacks[$name]['callback'];
    }

    /**
     * Set the blacklisted terms array. Values should be in the format @foo.
     *
     * @param   array   $blacklist  an array of blacklisted terms to use
     * @throws \InvalidArgumentException
     */
    public static function setBlacklist($blacklist)
    {
        if (!is_array($blacklist) || in_array(false, array_map('is_string', $blacklist))) {
            throw new \InvalidArgumentException(
                'Blacklist must be an array of string values.'
            );
        }

        static::$blacklist = $blacklist;
    }

    public static function getBlacklist()
    {
        return static::$blacklist;
    }

    /**
     * Base url to prepend to otherwise relative urls.
     *
     * @param   string|null     $baseUrl    the base url to prepend (e.g. http://example.com, /path, etc) or null
     * @return  Linkify         to maintain a fluent interface
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
     * Returns a list of all unique @<value> and @*<value> callouts that could,
     * potentially, be user ids. It is up to the caller to validate the returned
     * values are users, items such as job ids, project ids, etc. could also be
     * included. Note by default both @ and @* entries are returned as a single
     * list, if you prefer to see just starred entries pass $onlyStarred = true.
     *
     * @param   string  $value          the text to scan for callouts
     * @param   bool    $onlyStarred    optional - only returned @*mention callouts
     * @param   bool    $onlyRequireOne optional - only returned @!mention callouts
     * @return  array   an array of zero or more potential username callouts
     */
    public static function getCallouts($value, $onlyStarred = false, $onlyRequireOne = false)
    {
        $trimPattern    = '/^[”’"\'(<]*(.+?)[.”’"\'\,!?:;)>]*$/';
        $calloutPattern = '/^\@{1,2}(?P<starred>\*|!?)(?P<value>[\w]+[\\\\\w\.\-]{0,253})$/iu';
        $groupPattern   = '/^\@@/';
        $words          = preg_split(self::WORD_PATTERN, $value);
        $plain          = [];
        $starred        = [];
        $requiredOne    = [];
        foreach ($words as $word) {
            if (!strlen($word)) {
                continue;
            }

            // strip the leading/trailing punctuation from the actual word
            preg_match($trimPattern, $word, $matches);
            $word = $matches[1];

            // if the trimmed word isn't empty, matches our pattern and isn't black listed dig in more
            // if removing the leading @ leaves us with something that isn't purely numeric and we haven't
            // seen before it counts towards callouts.
            if (strlen($word)
                && preg_match($calloutPattern, $word, $matches)
                && !in_array($matches['value'], static::$blacklist)
            ) {
                if ($matches['starred'] === "*") {
                    $starred[] = $matches['value'];
                } elseif ($matches['starred'] === "!") {
                    // We only want to add value to requiredOne if we have @@ infront.
                    if (preg_match($groupPattern, $matches[0], $groupMatches)) {
                        $requiredOne[] = $matches['value'];
                    }
                } else {
                    $plain[] = $matches['value'];
                }
            }
        }

        // return the unique entries we found, excluding plain entries if requested
        if ($onlyStarred) {
            return array_values(array_unique($starred));
        } elseif ($onlyRequireOne) {
            return array_values(array_unique($requiredOne));
        } else {
            return array_values(array_unique(array_merge($plain, $starred, $requiredOne)));
        }
    }

    /**
     * Build an array of a link to put in an href and the text to display for the link
     * @param mixed     $escaper    to escape values
     * @param mixed     $matches    matches from matching against the URL reference regular expression
     * @return array an array with the href in position 0 and the link text in position 1
     */
    public function getUrlRef($escaper, $matches)
    {
        $linkText = null;
        $href     = null;
        if (isset($matches[2])) {
            // The text had [link] as an alias
            $linkText = str_replace(['[', ']'], '', $matches[2][0]);
            $href     = substr($matches[0][0], strlen($matches[2][0]));
            $brace    = strpos($href, '(');
            if ($brace !== false) {
                $href = substr_replace($href, '', $brace, strlen('('));
            }
            $brace = strrpos($href, ')');
            if ($brace !== false) {
                $href = substr_replace($href, '', $brace, strlen(')'));
            }
            $href = $escaper->escapeFullUrl(rawurldecode($href));
        } else {
            $href     = $escaper->escapeFullUrl(rawurldecode($matches[1][0]));
            $linkText = $href;
        }
        return [$href, $linkText];
    }

    /**
     * Attempts to linkify the passed text for display in an html context.
     * Looks for:
     *  @1234               - change
     *  @job1234            - job
     *  @alphaNum           - user/project
     *  @*alphaNum          - required user
     *  @path/to/something  - for files/folders
     *  job123456           - job followed by 6 digits
     *  job 1234            - word job followed by number
     *  change 1            - word change followed by number
     *  review 1            - word review followed by number
     *  http[s]://whatever  - makes a clickable link
     *  ftp://whatever
     *  user@host.com
     *
     * @param  string   $value  un-escaped text to linkify.
     * @return string   escaped (for html context) and linkified result
     * @throws ConfigException
     */
    public function filter($value)
    {
        // define the various regular expressions we will use
        $trimPattern  = '/^([_\*”’"\'(<{\[]*)(.+?)([._\*”’"\'\,!?:;)>}\]]*)$/';
        $urlPattern   = '/^(([^\]]*\])*\(*(?:http|https|ftp)\:\/\/(?:[\w-]+@)?(?:[\w.-]+)' .
                        '(?:\:[0-9]{1,6})?(?:\/[\w\.\-~!$&\'\(\)*+,;=:@?\/\#\:%]*[\w\-~!$\*+=@\/\#])?\/?)\)*$/iu';
        $emailPattern = '/^(([\w\-\.\+\'])+\@(?:[\w.-]+\.[a-z]{2,4}))$/iu';
        $gotoPattern  = '/^(\@{1,2}\*?([\w\/]+(?:[\\\\\w\/\.\-,!()\'%:#]*[\w\/]|[\w\/])?))$/iu';
        $requireOne   = '/^(\@{1,2}\!?([\w\/]+(?:[\\\\\w\/\.\-,!()\'%:#]*[\w\/]|[\w\/])?))$/iu';
        $jobPattern   = '/^(job[0-9]{6})$/i';
        // Use the configured limit for word length candidates
        $wordLengthLimit = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::LINKIFY_WORD_LENGTH_LIMIT,
            1024
        );

        // determine the smallest callback min length to assist in filtering
        $callbackMin = false;
        foreach (static::$callbacks as $callback) {
            if ($callbackMin === false || $callback['min'] < $callbackMin) {
                $callbackMin = $callback['min'];
            }
        }

        // scan over each word in the passed value. we queue up words till we hit
        // something that requires linkification to reduce the number of times we
        // have to call escapeHtml (speeds stuff up).
        $escaper = new Escaper;
        $words   = preg_split(self::WORD_PATTERN, $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $queue   = [];
        $escaped = '';
        $last    = '';
        $baseUrl = $this->baseUrl ? $escaper->escapeFullUrl(rtrim($this->baseUrl, '/')) : '';
        foreach ($words as $word) {
            // if its a whitespace hit, or empty just skip it
            $length = strlen($word);
            $first  = $length ? $word[0] : null;
            $second = $length && $length > 1 ? $word[1] : null;
            $third  = $length && $length > 2 ? $word[2] : null;
            if ($length == 0 || $first == "\t" || $first == "\n") {
                $queue[] = $word;
                continue;
            }

            // determine if the input is a candidate for our builtin handlers
            $candidate = $length <= $wordLengthLimit &&
                (($first == "@" && $length >= 2) || ($first != "@" && $length >= 6)
                || ctype_digit($first));

            // or if its a candidate for a callback (assuming we have any)
            $callbackCandidate = $callbackMin !== false && $length >= $callbackMin && $length <= $wordLengthLimit;
            $callbackHit       = false;

            // if it is a candidate or callback candidate do a bit more processing to trim it and update last word
            if ($candidate || $callbackCandidate) {
                // grab a copy of the last word for use this iteration and update last
                if ($last != " ") {
                    $lastWord = $last;
                }

                // separate the leading punctuation, actual word and trailing punctuation to ease matching
                preg_match($trimPattern, $word, $matches);

                $pre     = array_key_exists(1, $matches) ? $matches[1] : null;
                $trimmed = array_key_exists(2, $matches) ? $matches[2] : null;
                $post    = array_key_exists(3, $matches) ? $matches[3] : null;

                $last = $trimmed;

                // update first/length to reflect our trimmed value
                $length = strlen($trimmed);
                $first  = $length ? $trimmed[0] : null;
                $second = $length && $length > 1 ? $trimmed[1] : null;
                $third  = $length && $length > 2 ? $trimmed[2] : null;
            }

            // if we determined callbacks were in play; give them a shot
            if ($callbackCandidate) {
                foreach (static::$callbacks as $callback) {
                    if ($length >= $callback['min']) {
                        // call_user_func_array is needed to work with older versions of php (5.3.3)
                        $linkify = $this;
                        $replace =
                            call_user_func_array(
                                $callback['callback'],
                                [$linkify, $trimmed, $escaper, $lastWord, $baseUrl, $word]
                            );
                        if ($replace !== false) {
                            $trimmed     = $replace;
                            $callbackHit = true;
                            break;
                        }
                    }
                }
            }

            // if built-ins aren't a candidate and no callback hit, we're done
            if (!$candidate && !$callbackHit) {
                // just to catch excessively long words; otherwise we miss them
                if ($first != " ") {
                    $last = $word;
                }

                // add the word to the queue and carry on
                $queue[] = $word;
                continue;
            }

            // look for our various patterns, attempt to skip regex tests when possible
            if ($callbackHit) {
                // already handled; just skipping other checks
            } elseif ($length >= 10 && preg_match($urlPattern, $trimmed, $matches, PREG_OFFSET_CAPTURE, 0)) {
                $urlRef  = $this->getUrlRef($escaper, $matches);
                $trimmed = '<a href="' . $urlRef[0] . '">' . $urlRef[1] . '</a>';
                $pre     = '';
                $post    = '';
            } elseif ($length >= 6 && preg_match($emailPattern, $trimmed, $matches)) {
                $trimmed = '<a href="mailto:' . $escaper->escapeFullUrl($matches[1]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length >= 2 && $first == '@' && preg_match($gotoPattern, $trimmed, $matches)
                && !in_array($trimmed, static::$blacklist)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $escaper->escapeFullUrl($matches[2]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length >= 3 && $first == '@' && $second[0] == '@' && preg_match($gotoPattern, $trimmed, $matches)
                && !in_array($trimmed, static::$blacklist)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/group/' . $escaper->escapeFullUrl($matches[2]) . '">'
                    . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length >= 3 && $first == '@' && $second[0] == '@' && $third == '!'
                && preg_match($requireOne, $trimmed, $matches)
                && !in_array($trimmed, static::$blacklist)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/group/' . $escaper->escapeFullUrl($matches[2]) . '">'
                    . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif ($length == 9 && ($first == 'j' || $first == 'J')
                && preg_match($jobPattern, $trimmed, $matches)
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $escaper->escapeFullUrl($matches[1]) . '">'
                         . $escaper->escapeHtml($matches[1]) . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed
                && preg_match('/^change(list)?$/i', $lastWord)
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $trimmed . '">' . $trimmed . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed && strtolower($lastWord) == 'review'
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/@' . $trimmed . '">' . $trimmed . '</a>';
            } elseif (!$pre && $trimmed == (string)(int)$trimmed && strtolower($lastWord) == 'job'
                && strpos(end($queue), "\n") === false && strpos(end($queue), "\r") === false
            ) {
                $trimmed = '<a href="' . $baseUrl . '/jobs/' . $trimmed . '">' . $trimmed . '</a>';
            } else {
                $queue[] = $word;
                continue;
            }

            $queue[]  = $pre;
            $escaped .= $this->betweenCode ? implode('', $queue) : $escaper->escapeHtml(implode('', $queue));
            $escaped .= $trimmed;
            $queue    = [$post];
        }
        $this->betweenCode = false;
        return $escaped . $escaper->escapeHtml($escaped ? implode('', $queue) : $value);
    }

    /**
     * Linkify callback to detect text within a code block
     * @param $value
     * @param $escaper
     * @return bool
     */
    public function excludeCodeBlock($linkify, $value, $escaper, $lastWord, $baseUrl, $word)
    {
        $foundCb = $value === null ? 0 : preg_match('/^(`|~){3}[^`~]*$/', $value, $matches, PREG_OFFSET_CAPTURE);
        if ($foundCb === 0 && $value != null && strlen($value) > 0 && strpos($value, '```') === false) {
            $first = $value[0];
            $last  = substr($value, -1);
            if ($first == '`' || $last == '`') {
                $foundCb = 1;
            }
        }
        if (!$linkify->betweenCode && $foundCb === 1) {
            // The start of a code block has been found, set the flag unless it also ends immediately
            $linkify->betweenCode = !preg_match('/[`][^`]+[`]/', $value);
        } elseif ($linkify->betweenCode && $foundCb === 1) {
            $linkify->betweenCode = false;
        }
        if ($foundCb === 1) {
            return $value;
        } else {
            if ($linkify->betweenCode) {
                if ($value === null) {
                    return $word;
                } else {
                    return $value;
                }
            } else {
                return false;
            }
        }
    }
}
