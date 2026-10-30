<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Order;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N7: no file has the name of a directory of another entry, such as `a` and `a/b`, also in another
 * case: `unzip` would write the second through the first where the first were a link.
 *
 * @internal
 */
final readonly class FileAndDirectoryRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::FileAndDirectory;
    }

    public function check(Archive $archive): ?string
    {
        $entries = $archive->listing->entries;
        $keys = Order::keys($entries);
        // Each directory by its key: a directory entry, or a parent of any entry. In any order of
        // the archive.
        $directories = [];

        foreach ($keys as $index => $key) {
            if ($entries[$index]->isDirectory()) {
                $directories[$key] ??= sprintf('`%s` a directory of the same name', $entries[$index]->name);
            }

            for ($end = strrpos($key, "\0"); $end !== false; $end = strrpos(substr($key, 0, $end), "\0")) {
                $directories[substr($key, 0, $end)] ??= sprintf('the directory of `%s`', $entries[$index]->name);
            }
        }

        foreach ($keys as $index => $key) {
            if (! $entries[$index]->isDirectory() && isset($directories[$key])) {
                return sprintf('the archive entry `%s` is a file and %s.', $entries[$index]->name, $directories[$key]);
            }
        }

        return null;
    }
}
