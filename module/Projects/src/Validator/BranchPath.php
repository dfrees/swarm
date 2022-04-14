<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Validator;

use Application\Validator\ConnectedAbstractValidator;
use P4\Connection\AbstractConnection;

/**
 * Check if the given path is valid branch path.
 */
class BranchPath extends ConnectedAbstractValidator
{
    const UNSUPPORTED_WILDCARDS = 'unsupportedWildcards';
    const RELATIVE_PATH         = 'relativePath';
    const INVALID_DEPOT         = 'invalidDepot';
    const UNFOLLOWED_DEPOT      = 'unfollowedDepot';
    const NULL_DIRECTORY        = 'nullDirectory';
    const NO_PATHS              = 'noPaths';
    const UNSUPPORTED_OVERLAY   = 'unsupportedOverlay';
    const CLIENT_MAP_TWISTED    = 'clientMapTwisted';

    protected $messageTemplates = [
        self::UNSUPPORTED_WILDCARDS => "The only permitted wildcard is trailing '...'.",
        self::RELATIVE_PATH         => "Relative paths (., ..) are not allowed.",
        self::INVALID_DEPOT         => "The first path component must be a valid depot name.",
        self::UNFOLLOWED_DEPOT      => "Depot name must be followed by a path or '/...'.",
        self::NULL_DIRECTORY        => "The path cannot contain null directories ('//') or end with a '/'.",
        self::NO_PATHS              => "No depot paths specified.",
        self::UNSUPPORTED_OVERLAY   => "Overlay '+' mappings are not supported",
        self::CLIENT_MAP_TWISTED    => "Client map too twisted for directory list."
    ];

    /**
     * In-memory cache of existing depots in Perforce (per connection).
     */
    protected $depots = null;

    /**
     * Extend parent to also clear in-memory cache for depots.
     *
     * @param  mixed        $connection
     * @return ConnectedAbstractValidator   provides a fluent interface
     */
    public function setConnection(AbstractConnection $connection)
    {
        $this->depots = null;
        return parent::setConnection($connection);
    }

    /**
     * Returns true if $value is a valid branch path or a list of valid branch paths.
     *
     * @param   string|array    $value  value or list of values to check for
     * @return  boolean         true if value is valid branch path, false otherwise
     */
    public function isValid($value)
    {
        // normalize to an array and knock out whitespace
        $value = array_filter(array_map('trim', (array)$value), 'strlen');

        // value must contain at least one path
        if (!count($value)) {
            $this->error(self::NO_PATHS);
            return false;
        }

        $depots = $this->getDepots();
        foreach ($value as $path) {
            // check for embedded '...' and '*' (anywhere) in the path;
            // reject them as '*' in path(s) makes it impossible to run p4 dirs,
            // and embedded '...' may cause p4 dirs to be very slow
            if (preg_match('#(\.{3}.|\*)#', $path)) {
                $this->error(self::UNSUPPORTED_WILDCARDS);
                return false;
            }

            // verify that the first path component is an existing depot
            preg_match('#^(-){0,1}//([^/]+)#', $path, $match);
            if (!isset($match[2]) || !in_array($match[2], $depots)) {
                preg_match('#^(\+){1}//([^/]+)#', $path, $plusMatch);
                if (isset($plusMatch) && count($plusMatch) > 0) {
                    $this->error(self::UNSUPPORTED_OVERLAY);
                    return false;
                }
                $this->error(self::INVALID_DEPOT);
                return false;
            }

            // check that depot name is followed by something ('//depot' or '//depot/'
            // are not permitted paths)
            if (!preg_match('#^(-){0,1}//[^/]+/[^/]+#', $path)) {
                $this->error(self::UNFOLLOWED_DEPOT);
                return false;
            }

            // check for existence of relative paths ('.', '..') which are not allowed
            // (i.e //depot/.. and //depot/../folder are not permitted, but //depot/a..b/folder is permitted)
            if (preg_match('#/\.\.?(/|$)#', $path)) {
                $this->error(self::RELATIVE_PATH);
                return false;
            }

            // ensure that the path doesn't end with a slash or contain null directories
            // as such paths are not allowed in client view mappings
            if (substr($path, -1) === '/' || preg_match('#.+.(-){0,1}//+#', $path)) {
                $this->error(self::NULL_DIRECTORY);
                return false;
            }
        }

        return true;
    }

    /**
     * Truncate a path, or some paths, at the first Perforce wildcard; i.e. ... or *
     * @param $paths
     * @return string|string[]|null
     */
    public static function trimWildcards($paths)
    {
        return preg_replace('/[^\/]*(\.[^a-zA-Z0-9]|\*).*/', '', $paths);
    }

    /**
     * Returns list of existing depots in Perforce based on the connection set on this instance.
     * Supports in-memory cache, so 'p4 depots' doesn't run every time this function is called
     * (as long as connection hasn't changed).
     */
    protected function getDepots()
    {
        if ($this->depots === null) {
            $this->depots = array_map('current', $this->getConnection()->run('depots')->getData());
        }

        return $this->depots;
    }
    /**
     * Split the depot file path
     * @param $path
     * @return array
     */
    public static function splitPath($path)
    {
        return preg_split(
            '/([^\/]*[\/])/',
            str_replace('//', '', $path),
            0,
            PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE
        );
    }
}
