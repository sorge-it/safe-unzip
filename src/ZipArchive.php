<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip;

use Countable;
use Error;
use Exception;
use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\UnsupportedException;
use SorgeIt\SafeUnzip\Extraction\CheckPass;
use SorgeIt\SafeUnzip\Extraction\Placement;
use SorgeIt\SafeUnzip\Extraction\Staging;
use SorgeIt\SafeUnzip\Extraction\TreeComparison;
use SorgeIt\SafeUnzip\Profile\Profile;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\Tools\Versions;
use ValueError;

/**
 * Unpacks a standard ZIP file with Info-ZIP, with the four methods of `\ZipArchive` that this
 * needs: `open()`, `extractTo()` without a list, `close()`, `count()`. Each error throws instead of
 * returning `false` or an error code.
 */
final class ZipArchive implements Countable
{
    /**
     * The values of `\ZipArchive`, the two flags that `open()` takes.
     */
    public const int CHECKCONS = 4;

    public const int RDONLY = 16;

    public private(set) int $numFiles = 0;

    public private(set) string $filename = '';

    public private(set) string $comment = '';

    private ?Archive $archive = null;

    /**
     * Whether the releases of the tools were checked, once for each object.
     */
    private bool $checked = false;

    private readonly Process $process;

    private readonly Profile $profile;

    /**
     * Beyond `\ZipArchive`: the limits, and the environment and timeout of each tool.
     *
     * @param  array<array-key, string>  $environment  added to the environment of each tool, such as `PATH`
     * @param  int  $timeout  the seconds of each run of a tool; 0 is none
     */
    public function __construct(private readonly Limits $limits = new Limits, array $environment = [], int $timeout = 900)
    {
        // A negative count, such as a budget that ran out, would be no timeout at all.
        if ($timeout < 0) {
            throw new ValueError(sprintf('%s::__construct(): Argument #3 ($timeout) must be greater than or equal to 0', self::class));
        }

        $variables = [];

        // A name with `=` would set another variable, as in `PATH=/x=y`; PHP holds a name of digits
        // as an integer, whose value `proc_open()` passes alone.
        foreach ($environment as $name => $value) {
            if (! is_string($name) || $name === '' || strpbrk($name, "=\0") !== false) {
                throw new ValueError(sprintf('%s::__construct(): Argument #2 ($environment) must only have names without `=` and NUL bytes as keys, and no number', self::class));
            }

            $variables[$name] = $value;
        }

        $this->process = new Process($variables, $timeout);
        $this->profile = new Profile($this->limits);
    }

    /**
     * As the extension, whose objects cannot be cloned.
     */
    public function __clone()
    {
        throw new Error('Trying to clone an uncloneable object of class '.self::class);
    }

    /**
     * As the extension: an archive is a file and a process, nothing to store.
     *
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        throw new Exception(sprintf("Serialization of '%s' is not allowed", self::class));
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new Exception(sprintf("Unserialization of '%s' is not allowed", self::class));
    }

    /**
     * Lists the archive and checks it against the profile "standard ZIP". Nothing is written. The
     * checks always run, so `CHECKCONS` changes nothing; an archive open before is closed first.
     *
     * @return true
     */
    public function open(string $filename, int $flags = 0): bool
    {
        if (str_contains($filename, "\0")) {
            throw new ValueError('ZipArchive::open(): Argument #1 ($filename) must not contain any null bytes');
        }

        if ($filename === '') {
            throw new ValueError('ZipArchive::open(): Argument #1 ($filename) must not be empty');
        }

        if (($flags & ~(self::RDONLY | self::CHECKCONS)) !== 0) {
            throw new UnsupportedException('ZipArchive::open() is supported with RDONLY and CHECKCONS only: this ZipArchive creates and changes no archive.');
        }

        $this->reset();
        $file = $this->path($filename);

        if (! $this->checked) {
            Versions::check($this->process);
            $this->checked = true;
        }

        $archive = Archive::read($file, $this->process, $this->limits);
        $this->profile->check($archive);

        $this->archive = $archive;
        $this->numFiles = count($archive->listing->entries);
        $this->filename = $file;
        $this->comment = $archive->listing->header->comment;

        return true;
    }

    /**
     * Checks every file in a pipe first, then unpacks into a staging directory inside the target,
     * compares the tree with the listing and moves each entry into place. No entry moves into the
     * target before the comparison passes; a target directory that this call made stays, empty.
     *
     * @param  array<mixed>|string|null  $files  only `null`, for all entries
     * @return true
     */
    public function extractTo(string $pathto, array|string|null $files = null): bool
    {
        if (str_contains($pathto, "\0")) {
            throw new ValueError('ZipArchive::extractTo(): Argument #1 ($pathto) must not contain any null bytes');
        }

        // The extension returns `false`; this package throws for each error.
        if ($pathto === '') {
            throw new ValueError('ZipArchive::extractTo(): Argument #1 ($pathto) must not be empty');
        }

        if ($files !== null) {
            throw new UnsupportedException('ZipArchive::extractTo() with a list of entries is not supported: this ZipArchive unpacks all of an archive.');
        }

        // A stream wrapper, such as `file://`: `mkdir()` would make it, the tools cannot open it.
        if (preg_match('~\A(?:[A-Za-z0-9+.-]{2,}://|data:)~', $pathto) === 1) {
            throw new UnsupportedException(sprintf('ZipArchive::extractTo() takes a path of the file system, not %s.', $pathto));
        }

        $archive = $this->opened();
        $entries = $archive->listing->entries;
        // Before the target is made: an archive that fails the check makes no directory.
        CheckPass::run($this->process, $archive->file, $entries);
        $target = $this->target($pathto);

        if ($entries === []) {
            return true;
        }

        $staging = Staging::create($target, $entries);

        try {
            $staging->unpack($this->process, $archive->file);
            // The one compare with the SHA-256 of `open()`: a file written again since then is
            // refused before anything moves into the target.
            $archive->unchanged();
            TreeComparison::check($staging->path, $entries);
            Placement::place($staging->path, $target, $entries);
        } finally {
            // Only another process makes the removal fail; PHP then keeps the first error as its
            // previous one.
            $staging->remove();
        }

        return true;
    }

    public function close(): bool
    {
        $this->opened();
        $this->reset();

        return true;
    }

    public function count(): int
    {
        return count($this->opened()->listing->entries);
    }

    /**
     * The absolute path of an existing file, which no tool reads as an option or a pattern.
     */
    private function path(string $filename): string
    {
        $file = false;
        $reason = Attempt::failure(static function () use ($filename, &$file): bool {
            $file = realpath($filename);

            return $file !== false;
        });

        if ($reason !== null) {
            throw new FilesystemException(sprintf('%s cannot be opened: %s', $filename, $reason === 'no reason given' ? 'it does not exist' : $reason));
        }

        // In a working directory beyond `PATH_MAX`, `realpath()` gives a relative path back, which
        // the tools would read in their own directory.
        if (! str_starts_with($file, '/')) {
            throw new FilesystemException(sprintf('%s cannot be resolved to an absolute path.', $filename));
        }

        if (! is_file($file)) {
            throw new FilesystemException(sprintf('%s is no file.', $filename));
        }

        // Info-ZIP reads its archive argument as a pattern: `x[ab].zip` would open `xa.zip`.
        if (strpbrk($file, "*?[]\\\n") !== false) {
            throw new UnsupportedException(sprintf('%s holds `*`, `?`, `[`, `]`, `\` or a line break, which unzip reads as a pattern or zipinfo prints as two lines.', $file));
        }

        return $file;
    }

    /**
     * The target as an absolute path, made where it is missing.
     */
    private function target(string $pathto): string
    {
        if (($reason = Attempt::failure(static fn (): bool => is_dir($pathto) || mkdir($pathto, 0o755, true))) !== null) {
            throw new FilesystemException(sprintf('The directory %s could not be created: %s', $pathto, $reason));
        }

        $target = realpath($pathto);

        // As for the archive: a relative path would name another directory for the tools.
        if ($target === false || ! str_starts_with($target, '/')) {
            throw new FilesystemException(sprintf('The directory %s cannot be resolved to an absolute path.', $pathto));
        }

        return $target;
    }

    private function opened(): Archive
    {
        return $this->archive ?? throw new ValueError('Invalid or uninitialized Zip object');
    }

    private function reset(): void
    {
        $this->archive = null;
        $this->numFiles = 0;
        $this->filename = '';
        $this->comment = '';
    }
}
