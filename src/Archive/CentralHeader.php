<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The fixed fields of a central header, as PHP reads them; sizes and offset with their ZIP64
 * values, `-1` where the ZIP64 subfield lacks one.
 *
 * @internal
 */
final readonly class CentralHeader
{
    /**
     * @param  int  $time  the DOS time in the low and the DOS date in the high 16 bits
     */
    public function __construct(
        public int $position,
        public int $madeBy,
        public int $needed,
        public int $flags,
        public int $method,
        public int $time,
        public int $crc,
        public int $compressedSize,
        public int $size,
        public int $commentLength,
        public int $disk,
        public int $external,
        public int $offset,
        public string $name,
        public ExtraFields $extra,
        public int $length,
    ) {}
}
