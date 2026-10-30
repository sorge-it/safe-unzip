<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Extraction;

use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Attempt;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Profile\Reason;

/**
 * Every path of the target is checked first, so that a link stops the move before anything moves
 * (L2). A new directory moves with all in it; a file into a directory of the target moves alone.
 * A process that writes into the target at the same time can still come between.
 *
 * @internal
 */
final class Placement
{
    /**
     * The name of the directory that tests a move into each directory of the target, before 12
     * hex digits.
     */
    public const string PROBE = '.safe-unzip-probe-';

    /**
     * What stays in the staging directory, such as a directory that was in the target already,
     * goes with it.
     *
     * @param  list<Entry>  $entries
     */
    public static function place(string $staging, string $target, array $entries): void
    {
        $directories = [];
        $files = [];

        foreach ($entries as $entry) {
            $parts = explode('/', rtrim($entry->name, '/'));
            $path = $target;

            foreach ($entry->isDirectory() ? $parts : array_slice($parts, 0, -1) as $part) {
                $path .= '/'.$part;

                // Once per path: entries below the same directory share the check.
                if (! isset($directories[$path]) && (is_link($path) || (file_exists($path) && ! is_dir($path)))) {
                    throw new RefusedException(Reason::Target, sprintf('%s is a link or a file in the target, where `%s` needs a directory.', $path, $entry->name));
                }

                $directories[$path] = true;
            }

            if (! $entry->isDirectory()) {
                $destination = $target.'/'.$entry->name;

                if (is_link($destination) || is_dir($destination)) {
                    throw new RefusedException(Reason::Target, sprintf('%s is a link or a directory in the target, where `%s` is a file.', $destination, $entry->name));
                }

                $files[$staging.'/'.$entry->name] = $destination;
            }
        }

        // Before anything is made or moved: each directory that is already in the target and gets a
        // file or a new directory. A directory that this class makes is never a mount.
        $made = array_filter($directories, static fn (string $path): bool => ! is_dir($path), ARRAY_FILTER_USE_KEY);
        // The highest new directories, each with all in it, and the files into the target.
        $tops = array_keys(array_filter($made, static fn (string $path): bool => ! isset($made[dirname($path)]), ARRAY_FILTER_USE_KEY));
        $alone = array_filter($files, static fn (string $to): bool => ! isset($made[dirname($to)]));
        $parents = array_unique(array_map(dirname(...), [...array_values($alone), ...$tops]));
        self::sameFileSystem($staging, array_values(array_filter($parents, static fn (string $path): bool => ! isset($made[$path]))));

        foreach ($tops as $path) {
            self::move($staging.substr($path, strlen($target)), $path);
        }

        foreach ($alone as $from => $to) {
            self::move($from, $to);
        }
    }

    private static function move(string $from, string $to): void
    {
        $reason = Attempt::failure(static fn (): bool => rename($from, $to));
        Filesystem::done($reason === null, sprintf('%s could not be moved to %s: %s', $from, $to, $reason));
    }

    /**
     * Across a mount, `rename()` of PHP copies a file, into a hard link or a device there, but not
     * a directory. So an empty directory moves into each of `$directories`, and back.
     *
     * @param  list<string>  $directories
     */
    private static function sameFileSystem(string $staging, array $directories): void
    {
        if ($directories === []) {
            return;
        }

        // `st_dev` would not do: a bind mount has the same one.
        $probe = $staging.'/'.self::PROBE.bin2hex(random_bytes(6));

        Filesystem::done(mkdir($probe, 0o700), sprintf('The directory %s could not be created.', $probe));

        foreach ($directories as $directory) {
            $there = $directory.'/'.basename($probe);

            if (($reason = Attempt::failure(static fn (): bool => rename($probe, $there))) !== null) {
                throw new RefusedException(Reason::Target, sprintf('nothing can be moved into %s, as a move there would copy across a mount or another file system, or fail: %s.', $directory, $reason));
            }

            self::move($there, $probe);
        }
    }
}
