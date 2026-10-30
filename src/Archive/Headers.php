<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

use SorgeIt\SafeUnzip\Attempt;
use SorgeIt\SafeUnzip\Exception\FilesystemException;

/**
 * The only class that reads bytes of the archive: fixed header fields at the offsets that
 * `zipinfo -v` names, for the patterns Info-ZIP does not report. It never searches for a
 * signature, never decompresses and never writes.
 *
 * @internal
 */
final readonly class Headers
{
    private const int MAXIMUM = 0xFFFFFFFF;

    /**
     * @param  list<LocalHeader|null>  $local  for each entry of the listing; `null` where no local header is at its offset
     * @param  list<CentralHeader>  $central  each central header from the offset of the central directory, up to the first that is none
     * @param  int  $centralEnd  the offset after the last central header that was read
     * @param  string  $next  the signature after the last central header that was read, such as `PK\5\5` of a digital signature
     * @param  EndRecords|null  $end  `null` where no end record is at the offset that `zipinfo` names
     */
    private function __construct(
        public array $local,
        public array $central,
        public int $centralEnd,
        public string $next,
        public ?EndRecords $end,
    ) {}

    /**
     * The SHA-256 of the archive: a safety net against a file written again by mistake.
     */
    public static function hash(string $file): string
    {
        $hash = '';
        $reason = Attempt::failure(static function () use ($file, &$hash): bool {
            $hash = (string) hash_file('sha256', $file);

            return $hash !== '';
        });

        if ($reason !== null) {
            throw new FilesystemException(sprintf('%s could not be read: %s', $file, $reason));
        }

        return $hash;
    }

    public static function read(string $file, Listing $listing): self
    {
        // `hash()` read the file before, so only another process makes this fail.
        $handle = fopen($file, 'rb') ?: throw new FilesystemException(sprintf('%s could not be read.', $file));

        try {
            $central = [];
            $position = $listing->header->centralOffset;

            while (count($central) < count($listing->entries) && ($header = self::central($handle, $position)) instanceof CentralHeader) {
                $central[] = $header;
                $position += $header->length;
            }

            $local = [];

            foreach ($listing->entries as $entry) {
                $local[] = self::local($handle, $entry);
            }

            return new self($local, $central, $position, self::bytes($handle, $position, 4) ?? '', self::end($handle, $listing->header->actualOffset));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private static function central(mixed $handle, int $position): ?CentralHeader
    {
        $fixed = self::bytes($handle, $position, 46);

        if ($fixed === null || ! str_starts_with($fixed, "PK\1\2")) {
            return null;
        }

        /** @var array{madeBy: int, needed: int, flags: int, method: int, time: int, crc: int, compressed: int, size: int, nameLength: int, extraLength: int, commentLength: int, disk: int, external: int, offset: int} $field */
        $field = unpack('x4/vmadeBy/vneeded/vflags/vmethod/Vtime/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/x2/Vexternal/Voffset', $fixed);
        $name = self::bytes($handle, $position + 46, $field['nameLength']);
        $extra = self::bytes($handle, $position + 46 + $field['nameLength'], $field['extraLength']);

        if ($name === null || $extra === null) {
            return null;
        }

        $fields = ExtraFields::parse($extra);
        [$size, $compressed, $offset] = $fields->zip64([$field['size'] === self::MAXIMUM, $field['compressed'] === self::MAXIMUM, $field['offset'] === self::MAXIMUM]);

        return new CentralHeader(
            $position,
            $field['madeBy'],
            $field['needed'],
            $field['flags'],
            $field['method'],
            $field['time'],
            $field['crc'],
            $field['compressed'] === self::MAXIMUM ? $compressed ?? -1 : $field['compressed'],
            $field['size'] === self::MAXIMUM ? $size ?? -1 : $field['size'],
            $field['commentLength'],
            $field['disk'],
            $field['external'],
            $field['offset'] === self::MAXIMUM ? $offset ?? -1 : $field['offset'],
            $name,
            $fields,
            46 + $field['nameLength'] + $field['extraLength'] + $field['commentLength'],
        );
    }

    /**
     * @param  resource  $handle
     */
    private static function local(mixed $handle, Entry $entry): ?LocalHeader
    {
        $fixed = self::bytes($handle, $entry->offset, 30);

        if ($fixed === null || ! str_starts_with($fixed, "PK\3\4")) {
            return null;
        }

        /** @var array{needed: int, flags: int, method: int, time: int, crc: int, compressed: int, size: int, nameLength: int, extraLength: int} $field */
        $field = unpack('x4/vneeded/vflags/vmethod/Vtime/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', $fixed);
        $name = self::bytes($handle, $entry->offset + 30, $field['nameLength']);
        $extra = self::bytes($handle, $entry->offset + 30 + $field['nameLength'], $field['extraLength']);

        if ($name === null || $extra === null) {
            return null;
        }

        $fields = ExtraFields::parse($extra);
        // A local ZIP64 subfield holds both sizes, where one of them is at its maximum.
        $wide = $field['size'] === self::MAXIMUM || $field['compressed'] === self::MAXIMUM;
        [$size, $compressed] = $fields->zip64([$wide, $wide]);
        $dataOffset = $entry->offset + 30 + $field['nameLength'] + $field['extraLength'];
        // The data ends where the central directory says: a local header with flag bit 3 has no sizes.
        $descriptor = ($field['flags'] & 0x8) !== 0 ? self::descriptor($handle, $dataOffset + $entry->compressedSize, $fields->zip64 !== '') : null;

        return new LocalHeader(
            $field['needed'],
            $field['flags'],
            $field['method'],
            $field['time'],
            $field['crc'],
            $wide ? $compressed ?? -1 : $field['compressed'],
            $wide ? $size ?? -1 : $field['size'],
            $name,
            $fields,
            $dataOffset,
            $descriptor,
            $dataOffset + $entry->compressedSize + ($descriptor->length ?? 0),
        );
    }

    /**
     * @param  resource  $handle
     */
    private static function descriptor(mixed $handle, int $position, bool $zip64): ?DataDescriptor
    {
        $signed = self::bytes($handle, $position, 4) === "PK\7\x08";
        $start = $position + ($signed ? 4 : 0);
        $bytes = self::bytes($handle, $start, $zip64 ? 20 : 12);

        if ($bytes === null) {
            return null;
        }

        /** @var array{crc: int, compressed: int, size: int} $field */
        $field = unpack($zip64 ? 'Vcrc/Pcompressed/Psize' : 'Vcrc/Vcompressed/Vsize', $bytes);

        return new DataDescriptor($field['crc'], $field['compressed'], $field['size'], $start - $position + strlen($bytes));
    }

    /**
     * @param  resource  $handle
     */
    private static function end(mixed $handle, int $position): ?EndRecords
    {
        $start = $position;
        $zip64 = self::zip64($handle, $position);
        $locator = null;

        if ($zip64 instanceof Zip64EndRecord) {
            // The size counts the record without its first 12 bytes; beyond 44 it has extensible data.
            $position += 12 + min(max($zip64->size, 44), PHP_INT_MAX - $position - 12);
            $locator = self::locator($handle, $position);

            if (! $locator instanceof Zip64Locator) {
                return null;
            }

            $position += 20;
        } elseif (($locator = self::locator($handle, $position - 20)) instanceof Zip64Locator) {
            // A ZIP64 end record that zipinfo did not take: its locator stands before the end record.
            $zip64 = self::zip64($handle, $locator->offset);
            $start = $locator->offset;
        }

        $fixed = self::bytes($handle, $position, 22);

        if ($fixed === null || ! str_starts_with($fixed, "PK\5\6")) {
            return null;
        }

        /** @var array{disk: int, centralDisk: int, diskEntries: int, entries: int, centralSize: int, centralOffset: int, commentLength: int} $field */
        $field = unpack('x4/vdisk/vcentralDisk/vdiskEntries/ventries/VcentralSize/VcentralOffset/vcommentLength', $fixed);
        $comment = self::bytes($handle, $position + 22, $field['commentLength']) ?? '';

        return new EndRecords($zip64, $locator, new EndRecord(...$field), $comment, $start, $position + 22 + strlen($comment));
    }

    /**
     * @param  resource  $handle
     */
    private static function zip64(mixed $handle, int $position): ?Zip64EndRecord
    {
        $record = self::bytes($handle, $position, 56);

        if ($record === null || ! str_starts_with($record, "PK\6\6")) {
            return null;
        }

        /** @var array{size: int, disk: int, centralDisk: int, diskEntries: int, entries: int, centralSize: int, centralOffset: int} $field */
        $field = unpack('x4/Psize/x4/Vdisk/VcentralDisk/PdiskEntries/Pentries/PcentralSize/PcentralOffset', $record);

        return new Zip64EndRecord(...$field);
    }

    /**
     * @param  resource  $handle
     */
    private static function locator(mixed $handle, int $position): ?Zip64Locator
    {
        $bytes = self::bytes($handle, $position, 20);

        if ($bytes === null || ! str_starts_with($bytes, "PK\6\7")) {
            return null;
        }

        /** @var array{disk: int, offset: int, disks: int} $field */
        $field = unpack('x4/Vdisk/Poffset/Vdisks', $bytes);

        return new Zip64Locator(...$field);
    }

    /**
     * Exactly `$length` bytes at `$offset`; `null` where the file has fewer.
     *
     * @param  resource  $handle
     */
    private static function bytes(mixed $handle, int $offset, int $length): ?string
    {
        if ($length === 0) {
            return '';
        }

        if ($length < 0 || $offset < 0 || fseek($handle, $offset) !== 0) {
            return null;
        }

        $bytes = fread($handle, $length);

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }
}
