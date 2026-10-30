<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The data descriptor after the data of an entry, with or without its signature.
 *
 * @internal
 */
final readonly class DataDescriptor
{
    public function __construct(public int $crc, public int $compressedSize, public int $size, public int $length) {}
}
