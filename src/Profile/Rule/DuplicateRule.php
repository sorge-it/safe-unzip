<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Profile\Order;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N6: each name once, also where only the case differs, and each directory in one spelling: on a
 * file system that ignores case, as macOS does by default, `A` and `a` are one file.
 *
 * @internal
 */
final readonly class DuplicateRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Duplicate;
    }

    public function check(Archive $archive): ?string
    {
        $entries = $archive->listing->entries;
        $seen = [];

        foreach ($entries as $entry) {
            $key = strtolower($entry->name);

            if (isset($seen[$key])) {
                return sprintf('the archive names `%s` and `%s`.', $seen[$key], $entry->name);
            }

            $seen[$key] = $entry->name;
        }

        // In this order, the names below a directory follow it: each name is compared with the
        // next one only. A check of each parent of each name would take time by the square of the depth.
        $order = Order::of($entries);

        foreach ($order as $position => $index) {
            if (isset($order[$position + 1])) {
                $detail = $this->spelling($entries[$index], $entries[$order[$position + 1]]);

                if ($detail !== null) {
                    return $detail;
                }
            }
        }

        return null;
    }

    /**
     * Where two names spell a directory that both are in, or are, otherwise.
     */
    private function spelling(Entry $one, Entry $other): ?string
    {
        $a = rtrim($one->name, '/');
        $b = rtrim($other->name, '/');
        $limit = min($this->directories($one, $a), $this->directories($other, $b));
        // The parts before the first byte that differs are the same in both.
        $differ = strspn($a ^ $b, "\0", 0, $limit);

        if ($differ === $limit) {
            return null;
        }

        // The part of that byte, a directory in both, where it differs only in case.
        $end = (int) strpos($a.'/', '/', $differ);

        if (($b[$end] ?? '/') === '/' && strcasecmp(substr($a, 0, $end), substr($b, 0, $end)) === 0) {
            return sprintf('the archive names the directory `%s` also `%s`, which differs only in case.', substr($a, 0, $end), substr($b, 0, $end));
        }

        return null;
    }

    /**
     * The length of the directories of a name: all of it for a directory, up to its last `/` for
     * a file.
     */
    private function directories(Entry $entry, string $name): int
    {
        return $entry->isDirectory() ? strlen($name) : (int) strrpos($name, '/');
    }
}
