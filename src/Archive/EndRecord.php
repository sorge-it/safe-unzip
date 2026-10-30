<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The end of central directory record.
 *
 * @internal
 */
final readonly class EndRecord
{
    public function __construct(
        public int $disk,
        public int $centralDisk,
        public int $diskEntries,
        public int $entries,
        public int $centralSize,
        public int $centralOffset,
        public int $commentLength,
    ) {}
}
