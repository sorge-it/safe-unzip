<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The fixed fields of a local header, as PHP reads them, and the data descriptor after its data
 * where flag bit 3 announces one.
 *
 * @internal
 */
final readonly class LocalHeader
{
    /**
     * @param  int  $time  the DOS time in the low and the DOS date in the high 16 bits
     * @param  int  $end  the offset after the data and the data descriptor, where the next entry belongs
     */
    public function __construct(
        public int $needed,
        public int $flags,
        public int $method,
        public int $time,
        public int $crc,
        public int $compressedSize,
        public int $size,
        public string $name,
        public ExtraFields $extra,
        public int $dataOffset,
        public ?DataDescriptor $descriptor,
        public int $end,
    ) {}
}
