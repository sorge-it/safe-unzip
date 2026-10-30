<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Profile\Reason;

/**
 * Reads `zipinfo -v` of Info-ZIP UnZip 6.0 against a closed grammar. It refuses an archive comment
 * that is not one line of printable ASCII and any entry comment itself: zipinfo prints both raw, so
 * no line after them can be trusted.
 *
 * @internal
 */
final readonly class Listing
{
    private const string TOOL = 'zipinfo -v';

    private const string HEX = '\([0-9A-F]{16}h\)';

    /**
     * The keys of an entry that each block has once.
     */
    private const array REQUIRED = [
        'file system or operating system of origin',
        'version of encoding software',
        'minimum file system compatibility required',
        'minimum software version required to extract',
        'compression method',
        'file security status',
        'extended local header',
        'file last modified on (DOS date/time)',
        '32-bit CRC value (hex)',
        'compressed size',
        'uncompressed size',
        'length of filename',
        'length of extra field',
        'length of file comment',
        'disk number on which file begins',
        'apparent file type',
    ];

    /**
     * @param  list<Entry>  $entries  in the order of the central directory; fewer than the header counts where `zipinfo` stopped early
     * @param  int  $exit  the exit code of `zipinfo`: 1 for a warning, 2 and 3 for errors
     * @param  string  $errors  what `zipinfo` printed as warnings and errors
     */
    private function __construct(public ListingHeader $header, public array $entries, public int $exit, public string $errors) {}

    /**
     * @param  string  $file  the archive, as the tool got it
     */
    public static function parse(string $output, int $exit, string $errors, string $file): self
    {
        $lines = new Lines($output, self::TOOL);
        $lines->expect('Archive:  '.$file);

        $header = self::header($lines);
        $entries = [];

        if ($lines->peek() === '  Empty zipfile.') {
            $lines->next('the end');
        } else {
            while (! $lines->done()) {
                $entries[] = self::entry($lines, count($entries));
            }
        }

        $lines->end();

        return new self($header, $entries, $exit, $errors);
    }

    private static function header(Lines $lines): ListingHeader
    {
        [$comment, $truncated] = self::comment($lines);
        $lines->expect('End-of-central-directory record:');
        $lines->expect('-------------------------------');
        $lines->expect('');
        [$fileSize] = $lines->match('~\A  Zip archive file size: +(\d+) '.self::HEX.'\z~', 'the file size');
        [$actual] = $lines->match('~\A  Actual end-cent-dir record offset: +(\d+) '.self::HEX.'\z~', 'the offset of the end record');
        [$expected] = $lines->match('~\A  Expected end-cent-dir record offset: +(\d+) '.self::HEX.'\z~', 'the expected offset of the end record');
        $lines->expect('  (based on the length of the central directory and its expected offset)');
        $lines->expect('');

        if (str_starts_with((string) $lines->peek(), '  This zipfile constitutes disk ')) {
            throw new RefusedException(Reason::MultiDisk, 'zipinfo lists the archive as one disk of a multi-part archive.');
        }

        $lines->expect('  This zipfile constitutes the sole disk of a single-part archive; its');
        [$entries] = $lines->match('~\A  central directory contains (\d+) entr(?:y|ies)\.\z~', 'the count of entries');
        [$centralSize] = $lines->match('~\A  The central directory is (\d+) '.self::HEX.' bytes long,\z~', 'the size of the central directory');
        $lines->expect('  and its (expected) offset in bytes from the beginning of the zipfile');
        [$centralOffset] = $lines->match('~\A  is (\d+) '.self::HEX.'\.\z~', 'the offset of the central directory');
        $lines->expect('');

        // Where zipinfo cannot find the central directory, it stops here.
        if (! $lines->done()) {
            $lines->expect('');
        }

        return new ListingHeader($comment, $truncated, (int) $fileSize, (int) $actual, (int) $expected, (int) $entries, (int) $centralSize, (int) $centralOffset);
    }

    /**
     * The archive comment, where it is one line of printable ASCII, and whether `zipinfo` calls it
     * truncated.
     *
     * @return array{string, bool}
     */
    private static function comment(Lines $lines): array
    {
        $line = $lines->next('the archive comment');

        if ($line === 'There is no zipfile comment.') {
            $lines->expect('');

            return ['', false];
        }

        if (preg_match('~\AThe zipfile comment is (\d+) bytes? long and contains the following text:\z~', $line, $length) !== 1) {
            throw Lines::invalid(self::TOOL, sprintf('has `%s` where the archive comment belongs', $line));
        }

        $refused = new RefusedException(Reason::ArchiveComment, sprintf('the archive comment of %d bytes is not one line of printable ASCII.', (int) $length[1]));
        $ends = '~\A=+ zipfile comment ends =+\z~';

        if (preg_match('~\A=+ zipfile comment begins =+\z~', (string) $lines->peek()) !== 1) {
            throw $refused;
        }

        $lines->next('the archive comment');
        $shown = $lines->next('the archive comment');

        if (preg_match($ends, $shown) === 1) {
            $shown = '';
        } elseif (preg_match('~\A[\x20-\x7E]*\z~', $shown) !== 1 || preg_match($ends, (string) $lines->peek()) !== 1) {
            throw $refused;
        } else {
            $lines->next('the end of the archive comment');
        }

        $lines->expect('');

        if ($lines->peek() === '  The zipfile comment is truncated.') {
            $lines->next('the end of the archive comment');
            $lines->expect('');

            return [$shown, true];
        }

        if (strlen($shown) !== (int) $length[1]) {
            throw $refused;
        }

        return [$shown, false];
    }

    private static function entry(Lines $lines, int $index): Entry
    {
        $lines->expect(sprintf('Central directory entry #%d:', $index + 1));
        $lines->match('~\A(-+)\z~', 'the rule under the title of an entry');
        $lines->expect('');

        // An estimate from the central extra field, wrong by 4 bytes for a UT field with two
        // times in the central header: S3 walks the local headers instead.
        if (preg_match('~\A  There are an extra -?\d+ bytes? preceding this file\.\z~', (string) $lines->peek()) === 1) {
            $lines->next('the bytes before the entry');
            $lines->expect('');
        }

        [$name] = $lines->match('~\A  (.*)\z~s', 'the name of entry #'.($index + 1));
        $lines->expect('');
        [$offset] = $lines->match('~\A  offset of local header from start of archive: +(\d+)\z~', 'the offset of the local header');
        $lines->match('~\A +'.self::HEX.' bytes\z~', 'the offset of the local header in hex');
        $values = self::values($lines, $name);
        $extraFields = self::extraFields($lines);

        // A paragraph on a local time field; PHP reads the local extra fields itself.
        while (preg_match('~\A  There is a local extra field with ID 0x[0-9a-f]{4} ~', (string) $lines->peek()) === 1) {
            while ($lines->next('the end of the local extra field') !== '') {
                // Its words run over two lines.
            }
        }

        $lines->expect('  There is no file comment.');

        // After an error, zipinfo stops without the blank line.
        if (! $lines->done()) {
            $lines->expect('');
        }

        return new Entry(
            $index,
            $name,
            (int) $offset,
            $values['file system or operating system of origin'],
            $values['minimum software version required to extract'],
            $values['compression method'],
            self::choice($values['file security status'], ['not encrypted' => false, 'encrypted' => true]),
            self::choice($values['extended local header'], ['no' => false, 'yes' => true]),
            (int) hexdec(self::value($values['32-bit CRC value (hex)'], '~\A([0-9a-f]{8})\z~')),
            (int) self::value($values['compressed size'], '~\A(\d+) bytes?\z~'),
            (int) self::value($values['uncompressed size'], '~\A(\d+) bytes?\z~'),
            (int) self::value($values['length of filename'], '~\A(\d+) characters?\z~'),
            (int) self::value($values['length of extra field'], '~\A(\d+) bytes?\z~'),
            (int) self::value($values['disk number on which file begins'], '~\Adisk (\d+)\z~'),
            isset($values['unix']) ? (int) octdec($values['unix']) : null,
            isset($values['external']) ? (int) hexdec(self::value($values['external'], '~\A([0-9A-F]{6}) hex\z~i')) : null,
            (int) hexdec($values['dos']),
            isset($values['other']),
            $extraFields,
        );
    }

    /**
     * The `key: value` lines of an entry, up to the blank line. An attribute key carries its value,
     * such as `Unix file attributes (100644 octal)`; it goes in as `unix`, `external`, `dos` or `other`.
     *
     * @return array<string, string>
     */
    private static function values(Lines $lines, string $name): array
    {
        $values = [];

        while (($line = $lines->next('the end of entry '.$name)) !== '') {
            if (preg_match('~\A  (\S[^:]*?): +(.*)\z~', $line, $match) !== 1) {
                throw Lines::invalid(self::TOOL, sprintf('lists `%s` for entry `%s`, a line it does not know', $line, $name));
            }

            [, $key, $value] = $match;
            $value = rtrim($value);

            if ($key === 'length of file comment' && $value !== '0 characters') {
                throw new RefusedException(Reason::EntryComment, sprintf('the archive entry `%s` has a comment.', $name));
            }

            if (preg_match('~\AUnix file attributes \(([0-7]{6}) octal\)\z~', $key, $mode) === 1) {
                [$key, $value] = ['unix', $mode[1]];
            } elseif ($key === 'non-MSDOS external file attributes') {
                $key = 'external';
            } elseif (preg_match('~\AMS-DOS file attributes \(([0-9A-F]{2}) hex\)\z~i', $key, $dos) === 1) {
                [$key, $value] = ['dos', $dos[1]];
            } elseif (str_ends_with($key, ' file attributes') || str_contains($key, ' file attributes (')) {
                $key = 'other';
            } elseif (str_starts_with($key, 'file last modified on (') && $key !== 'file last modified on (DOS date/time)') {
                // An extended timestamp is listed twice, in local time and in UTC; times are not read.
                continue;
            } elseif (! in_array($key, [...self::REQUIRED, 'compression sub-type (deflation)'], true)) {
                throw Lines::invalid(self::TOOL, sprintf('lists `%s` for entry `%s`, a key it does not know', $key, $name));
            }

            if (isset($values[$key])) {
                throw Lines::invalid(self::TOOL, sprintf('lists `%s` twice for entry `%s`', $key, $name));
            }

            $values[$key] = $value;
        }

        foreach ([...self::REQUIRED, 'dos'] as $key) {
            if (! isset($values[$key])) {
                throw Lines::invalid(self::TOOL, sprintf('lists no `%s` for entry `%s`', $key, $name));
            }
        }

        if (count(array_intersect_key($values, ['unix' => true, 'external' => true, 'other' => true])) !== 1) {
            throw Lines::invalid(self::TOOL, sprintf('lists other than one kind of attributes for entry `%s`', $name));
        }

        return $values;
    }

    /**
     * The IDs of the central extra field, from its section: one line per subfield, each with its
     * details on lines that are indented further.
     *
     * @return list<int>
     */
    private static function extraFields(Lines $lines): array
    {
        if ($lines->peek() !== '  The central-directory extra field contains:') {
            return [];
        }

        $lines->next('the extra field');
        $ids = [];

        while (($line = $lines->next('the end of the extra field')) !== '') {
            // Some descriptions go on in the same line, such as of NTFS: `… 32 data bytes.  The first`.
            if (preg_match('~\A  - A subfield with ID 0x([0-9a-f]{4}) \(.*\) and \d+ data bytes?[.:]~', $line, $id) === 1) {
                $ids[] = (int) hexdec($id[1]);
            } elseif ($ids === [] || ! str_starts_with($line, '    ')) {
                throw Lines::invalid(self::TOOL, sprintf('lists `%s` in an extra field, a line it does not know', $line));
            }
        }

        return $ids;
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $choices
     * @return T
     */
    private static function choice(string $value, array $choices): mixed
    {
        if (! array_key_exists($value, $choices)) {
            throw Lines::invalid(self::TOOL, sprintf('lists `%s`, a value it does not know', $value));
        }

        return $choices[$value];
    }

    private static function value(string $value, string $pattern): string
    {
        if (preg_match($pattern, $value, $match) !== 1) {
            throw Lines::invalid(self::TOOL, sprintf('lists `%s`, a value it does not know', $value));
        }

        return $match[1];
    }
}
