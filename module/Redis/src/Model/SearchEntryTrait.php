<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Redis\Model;

use Application\Helper\ArrayHelper;
use Redis\RedisService;

trait SearchEntryTrait
{
    /**
     * This description relates to both projects and groups, groups are used here as an example
     *
     * We need to create set members in such a way that they can be matched by either their lowercase id or their
     * lowercase name, while still retaining their original (potentially, case-sensitive) id and name.
     * For example, if we have a group with an id of 'Group1' and a name of 'Zebras', we need to create an entry that
     * will allow you to match on any part of 'Group1' (in any case) and any part of 'Zebras' (in any case). As long as
     * we convert the search term to lowercase, having `group1` and 'zebras' as part of our entry will accomplish this.
     *
     * We also need a separator between the words so we don't match on `oup1zeb`. For this example, let's take ':' as
     * our separator. Assuming the name (if different from  the id) is what will be most familiar to searchers, we
     * should have the name come first. So we are now looking at 'zebras:group1'.
     *
     * Since, we don't actually have enough information from 'zebras:group1' to identify the group (I.e. if the server
     * is case-sensitive) we need to include the original information. Including the original information will also
     * allow us to return matches to something like an autocomplete API, without the need for a further lookup. We
     * should have another separator, though, so we can easily split off the original data from the lowercase data, say,
     * '^'. So, we now have something like:
     *     'zebras:group1^'Zebras:Group1'
     *
     * However, we are not quite done yet because we are also dealing with a sorted set, which is ordered
     * lexicographically. This type of set allows you to do a `starts with` search. If we only had the
     * 'zebras:group1^'Zebras:Group1' entry, we could never match on 'starts with "group"'! So we need an extra entry
     * for the sorted set. It needs to have the form:
     *     'group1:zebras^'Zebras:Group1'
     *
     * If you noticed, we switched the order of the lowercase words but not the order of the original words. If we build
     * the search entries with the original id as the last component, then we can always get the id with
     * end(split(':', $entry).
     *
     * For this example we would wind up with a single entry for our includes set:
     *     'zebras:group1^'Zebras:Group1'
     * and two entries for our starts with, sorted set:
     *     'zebras:group1^'Zebras:Group1'
     *     'group1:zebras^'Zebras:Group1'
     *
     * Lastly, if you have a case where the name and the id are the same, say, 'Group1' then you will wind up building
     * duplicate entries for the starts with sorted set, example:
     *     'group1:group1^Group1:Group1
     *     'group1:group1^Group1:Group1
     * However, it doesn't matter because we are dealing with a set, which automatically deals with duplicates.
     *
     * @param mixed $models project or group models
     * @return array[]
     */
    public function constructEntries($models) : array
    {
        $full              = RedisService::SEARCH_FULL_SEPARATOR;
        $part              = RedisService::SEARCH_PART_SEPARATOR;
        $includesEntries   = [];
        $startsWithEntries = [];

        foreach ($models as $model) {
            if (!$this->includeSearchEntry($model)) {
                continue;
            }
            $id        = $model->getId();
            $name      = $this->getSearchEntryValue($model);
            $lowerId   = ArrayHelper::lowerCase($id);
            $lowerName = ArrayHelper::lowerCase($name);
            // Add the search entry to both the includes and starts with arrays
            $includesEntries[] = $startsWithEntries[] = $lowerName . $part . $lowerId . $full . $name . $part . $id;
            // Additionally, add the extra entry with the lowercase id and name switched
            $startsWithEntries[] = $lowerId . $part . $lowerName . $full . $name . $part . $id;
        }
        return [
            AbstractDAO::SEARCH_STARTS_WITH => $startsWithEntries,
            AbstractDAO::SEARCH_INCLUDES    => $includesEntries
        ];
    }

    /**
     * Utility to format the results of a search
     * @param array     $matches        the matches to format
     * @param mixed     $key            the key
     * @param mixed     $value          the value
     * @return array
     */
    public function formatResults(array $matches, $key, $value) : array
    {
        $results = [];
        foreach ($matches as $entry) {
            $data      = explode(RedisService::SEARCH_PART_SEPARATOR, $entry);
            $results[] = [$key => $data[1], $value => $data[0]];
        }
        return $results;
    }
}
