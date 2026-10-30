<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Extraction;

use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Exception\ExtractionMismatchException;

/**
 * After `unzip -q`: the staged tree holds exactly the listing, each file with its size, each
 * directory listed or the parent of a name, and no link. Each node then gets its mode. The check
 * pass compared the CRC-32 of each file already.
 *
 * @internal
 */
final class TreeComparison
{
    /**
     * @param  list<Entry>  $entries
     */
    public static function check(string $directory, array $entries): void
    {
        $files = [];
        $directories = [];

        foreach ($entries as $entry) {
            $name = rtrim($entry->name, '/');

            if ($entry->isDirectory()) {
                $directories[$name] = $entry->mode();
            } else {
                $files[$name] = $entry;
            }

            // Each parent that the listing does not name, as `unzip` makes it.
            for ($slash = strrpos($name, '/'); $slash !== false; $slash = strrpos(substr($name, 0, $slash), '/')) {
                $directories[substr($name, 0, $slash)] ??= 0o755;
            }
        }

        $umask = umask();
        $seen = [];

        foreach (Filesystem::tree($directory, false) as $path => $stat) {
            // Bytes, not characters: a path is not always UTF-8.
            $name = substr($path, strlen($directory) + 1);
            $type = $stat['mode'] & 0o170000;
            $seen[$name] = true;

            if ($type === 0o040000 && isset($directories[$name])) {
                Filesystem::chmod($path, $directories[$name] & ~$umask | 0o700, 'get its mode');

                continue;
            }

            $entry = $files[$name] ?? null;

            if ($type !== 0o100000 || ! $entry instanceof Entry) {
                throw new ExtractionMismatchException(sprintf('unzip wrote `%s`, %s that the listing does not name. The archive is not what its listing says.', $name, $type === 0o120000 ? 'a link' : 'a node'));
            }

            if ($stat['size'] !== $entry->size) {
                throw new ExtractionMismatchException(sprintf('unzip wrote `%s` with another size than the listing says. The archive is not what its listing says.', $name));
            }

            Filesystem::chmod($path, $entry->mode() & ~$umask, 'get its mode');
        }

        $missing = array_diff_key($files, $seen);

        if ($missing !== []) {
            throw new ExtractionMismatchException(sprintf('unzip did not write `%s`. The archive is not what its listing says.', array_key_first($missing)));
        }
    }
}
