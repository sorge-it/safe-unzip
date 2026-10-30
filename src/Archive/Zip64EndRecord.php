<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The ZIP64 end of central directory record. A 64-bit value above PHP_INT_MAX reads as negative.
 *
 * @internal
 */
final readonly class Zip64EndRecord
{
    /**
     * @param  int  $size  the length of the record without its first 12 bytes
     */
    public function __construct(
        public int $size,
        public int $disk,
        public int $centralDisk,
        public int $diskEntries,
        public int $entries,
        public int $centralSize,
        public int $centralOffset,
    ) {}
}
