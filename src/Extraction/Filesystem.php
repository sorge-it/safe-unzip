<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Extraction;

use Generator;
use SorgeIt\SafeUnzip\Exception\FilesystemException;

/**
 * The calls on the file system that the steps share, each without following a link.
 *
 * @internal
 */
final class Filesystem
{
    /**
     * `lstat()` of a path.
     *
     * @return array{mode: int, size: int}
     */
    public static function status(string $path): array
    {
        $stat = lstat($path) ?: throw new FilesystemException(sprintf('%s could not be read.', $path));

        return ['mode' => $stat['mode'], 'size' => $stat['size']];
    }

    /**
     * The names in a directory, without `.` and `..`.
     *
     * @return list<string>
     */
    public static function names(string $directory): array
    {
        $names = scandir($directory) ?: throw new FilesystemException(sprintf('The directory %s could not be read.', $directory));

        return array_values(array_diff($names, ['.', '..']));
    }

    /**
     * Each node of a tree with its `lstat()`, each directory before or after what is in it.
     * `scandir()` closes a directory before the next one is read, as the archive sets the depth.
     *
     * @return Generator<string, array{mode: int, size: int}>
     */
    public static function tree(string $directory, bool $childrenFirst): Generator
    {
        foreach (self::names($directory) as $name) {
            $path = $directory.'/'.$name;
            $stat = self::status($path);

            if (! $childrenFirst) {
                yield $path => $stat;
            }

            if (($stat['mode'] & 0o170000) === 0o040000) {
                yield from self::tree($path, $childrenFirst);
            }

            if ($childrenFirst) {
                yield $path => $stat;
            }
        }
    }

    public static function chmod(string $path, int $mode, string $why): void
    {
        self::done(chmod($path, $mode), sprintf('%s could not %s.', $path, $why));
    }

    /**
     * Throws where a step failed that only another process or the file system makes fail, such as
     * a step on the staging directory, which this process made.
     */
    public static function done(bool $done, string $message): void
    {
        if (! $done) {
            // Untested: the checks before each step pass, so only another process or the file
            // system, such as with an immutable file, makes it fail.
            // @codeCoverageIgnoreStart
            throw new FilesystemException($message);
            // @codeCoverageIgnoreEnd
        }
    }
}
