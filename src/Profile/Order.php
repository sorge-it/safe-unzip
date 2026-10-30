<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

use SorgeIt\SafeUnzip\Archive\Entry;

/**
 * The entries by name in lower case, with NUL, the lowest byte, for `/`: in this order the names
 * below a directory follow it directly.
 *
 * @internal
 */
final class Order
{
    /**
     * @param  list<Entry>  $entries
     * @return list<int> the indexes, in that order
     */
    public static function of(array $entries): array
    {
        return array_keys(self::keys($entries));
    }

    /**
     * @param  list<Entry>  $entries
     * @return array<int, string> each index with its key, in that order
     */
    public static function keys(array $entries): array
    {
        $keys = [];

        foreach ($entries as $index => $entry) {
            $keys[$index] = strtr(strtolower(rtrim($entry->name, '/')), '/', "\0");
        }

        asort($keys, SORT_STRING);

        return $keys;
    }
}
