<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

use SorgeIt\SafeUnzip\Attempt;
use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\Tools\Tool;

/**
 * An archive as Info-ZIP lists it, and the header fields PHP read at the offsets of that listing.
 *
 * @internal
 */
final readonly class Archive
{
    /**
     * The bytes of `zipinfo -v` for the header and for each entry, beyond which it stops. The header
     * holds the archive comment, which can have 65535 bytes.
     */
    private const int LISTING = 65536 + 65535;

    private const int ENTRY = 16384;

    /**
     * @param  string  $hash  the SHA-256 of the file before it was listed
     */
    private function __construct(public string $file, public string $hash, public Listing $listing, public Headers $headers) {}

    /**
     * @param  string  $file  an absolute path, which no tool reads as an option
     */
    public static function read(string $file, Process $process, Limits $limits): self
    {
        $hash = Headers::hash($file);
        $count = self::count($file, $process);

        // The count first, so that an archive of millions of entries is not listed at all.
        if ($count > $limits->entries) {
            throw new RefusedException(Reason::Limit, sprintf('the archive has %d entries, the limit is %d.', $count, $limits->entries));
        }

        // A count near PHP_INT_MAX, under a limit as high, would make the product a float.
        $bound = $count > intdiv(PHP_INT_MAX - self::LISTING, self::ENTRY) ? PHP_INT_MAX : self::LISTING + self::ENTRY * $count;
        $free = self::free();
        $limit = $free === null ? $bound : min($bound, intdiv($free, 6));
        // The archive sets the size of its listing, so beyond the limit it is refused.
        $output = $process->run(Tool::Zipinfo, ['-v', $file], $limit, [0, 1, 2, 3, 11, 51], static fn (): RefusedException => new RefusedException(Reason::Limit, sprintf('zipinfo lists more than %d bytes, the most that %d entries and memory_limit allow.', $limit, $count)));

        // Each line costs more than its bytes: an empty one takes 1 byte of output and about 32 in
        // the parse, so lines in comments could fill the memory before the grammar refuses them,
        // with a fatal error that no caller can catch.
        $need = 6 * strlen($output->output) + 32 * substr_count($output->output, "\n");

        if ($free !== null && $need > $free) {
            throw new RefusedException(Reason::Limit, sprintf('the listing of zipinfo needs about %d bytes of memory, and %d are free under memory_limit.', $need, $free));
        }

        $listing = Listing::parse($output->output, $output->exit, $output->errors, $file);

        return new self($file, $hash, $listing, Headers::read($file, $listing));
    }

    /**
     * Throws when the file is not the one that `open()` hashed, such as one written again since:
     * the tools would then have read bytes that no check has seen.
     */
    public function unchanged(): void
    {
        if (Headers::hash($this->file) !== $this->hash) {
            throw new FilesystemException(sprintf('%s changed after it was hashed: its SHA-256 differs. Nothing was unpacked.', $this->file));
        }
    }

    /**
     * The bytes that are free under `memory_limit`, `null` for no limit. The parse of a listing
     * holds up to 4.6 times its output (measured with 5000 entries), so a sixth may be output.
     */
    private static function free(): ?int
    {
        $value = ini_get('memory_limit');
        $limit = -1;
        // Without a warning for a value that `ini_parse_quantity()` reads in another way than PHP.
        Attempt::failure(static function () use ($value, &$limit): bool {
            $limit = ini_parse_quantity($value);

            return true;
        });

        // `-1` and the lowest integer are no limit; a size beyond PHP_INT_MAX without a sign reads
        // as negative here, and PHP reads it without a sign.
        if ($limit === -1 || $limit === PHP_INT_MIN || ($limit < 0 && ! str_starts_with(ltrim($value), '-'))) {
            return null;
        }

        // PHP takes a negative size as positive, such as `-1G` as 1 GiB.
        return max(0, abs($limit) - memory_get_usage(true));
    }

    /**
     * The count of entries in the end record, from `zipinfo -h`.
     */
    private static function count(string $file, Process $process): int
    {
        // 11 and 51 also come for bytes of the archive: an end record on another disk, a name
        // beyond the central directory (measured 2026-10-29).
        $output = $process->run(Tool::Zipinfo, ['-h', $file], self::LISTING, [0, 1, 2, 3, 9, 11, 51]);

        if ($output->exit === 9) {
            throw new InvalidArchiveException(sprintf('%s is no ZIP archive: zipinfo finds no end record.', $file));
        }

        if ($output->exit === 11 && str_contains($output->output.$output->errors, ' of a multi-disk archive')) {
            throw new RefusedException(Reason::MultiDisk, 'zipinfo finds the end record on another disk than the central directory.');
        }

        if ($output->exit === 11 || $output->exit === 51) {
            throw new InvalidArchiveException(sprintf('zipinfo stopped with exit code %d: %s Nothing was unpacked.', $output->exit, trim($output->errors)));
        }

        $lines = new Lines($output->output, 'zipinfo -h');
        $lines->expect('Archive:  '.$file);
        [$count] = $lines->match('~\AZip file size: \d+ bytes, number of entries: (\d+)\z~', 'the count of entries');

        if ($lines->peek() === 'Empty zipfile.') {
            $lines->next('the end');
        }

        $lines->end();

        return (int) $count;
    }
}
