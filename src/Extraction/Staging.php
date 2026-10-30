<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Extraction;

use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Attempt;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\Tools\Tool;

/**
 * After the check pass: `unzip -q` writes into a new directory inside the target, on the same
 * file system, so that each move into place is a rename.
 *
 * @internal
 */
final readonly class Staging
{
    private function __construct(public string $path) {}

    /**
     * @param  list<Entry>  $entries
     */
    public static function create(string $target, array $entries): self
    {
        $path = $target.'/.safe-unzip-'.bin2hex(random_bytes(6));
        // L2 before N9: the target alone leaves no room for the probe directory of the placement.
        $probe = strlen($path) + 1 + strlen(Placement::PROBE) + 12;

        if ($probe >= PHP_MAXPATHLEN) {
            throw new RefusedException(Reason::Target, sprintf('the target %s is too long: the directories of the package below it would have %d bytes, the file system takes %d.', $target, $probe, PHP_MAXPATHLEN - 1));
        }

        // N9: the name comes from the archive; a path longer than the file system takes would stop
        // `unzip` in the staging directory.
        foreach ($entries as $entry) {
            $length = strlen($path) + 1 + strlen(rtrim($entry->name, '/'));

            if ($length >= PHP_MAXPATHLEN) {
                throw new RefusedException(Reason::PathLength, sprintf('the archive entry `%s…` would have a path of %d bytes in the staging directory, the file system takes %d.', substr($entry->name, 0, 64), $length, PHP_MAXPATHLEN - 1));
            }
        }

        // As in a directory of the target into which nothing can be moved (L2).
        if (($reason = Attempt::failure(static fn (): bool => mkdir($path, 0o700))) !== null) {
            throw new RefusedException(Reason::Target, sprintf('nothing can be written into %s: %s.', $target, $reason));
        }

        return new self($path);
    }

    public function unpack(Process $process, string $file): void
    {
        $process->stream(Tool::Unzip, ['-q', $file, '-d', $this->path], static function (string $chunk): void {});
        // `unzip` sets a mode such as `0500` of a directory at its end, which locks out its owner.
        $this->unlock($this->path);
    }

    /**
     * Removes the tree without following a link in it.
     */
    public function remove(): void
    {
        // Also where `unzip` failed before `unpack()` could unlock the tree.
        $this->unlock($this->path);

        foreach (Filesystem::tree($this->path, true) as $path => $stat) {
            Filesystem::done(($stat['mode'] & 0o170000) === 0o040000 ? rmdir($path) : unlink($path), sprintf('%s could not be removed.', $path));
        }

        Filesystem::done(rmdir($this->path), sprintf('The staging directory %s could not be removed.', $this->path));
    }

    /**
     * Gives the owner all access to each directory of the tree; a link is not followed.
     */
    private function unlock(string $directory): void
    {
        $mode = Filesystem::status($directory)['mode'] & 0o7777;

        if (($mode & 0o700) !== 0o700) {
            Filesystem::chmod($directory, $mode | 0o700, 'be opened for its owner');
        }

        foreach (Filesystem::names($directory) as $name) {
            $path = $directory.'/'.$name;

            if (! is_link($path) && is_dir($path)) {
                $this->unlock($path);
            }
        }
    }
}
