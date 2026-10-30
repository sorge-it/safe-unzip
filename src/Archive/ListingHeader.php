<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * What `zipinfo -v` says about the archive as a whole, before the first entry. With a ZIP64 end
 * record, `actualOffset` is its offset, and the counts are its counts.
 *
 * @internal
 */
final readonly class ListingHeader
{
    public function __construct(
        public string $comment,
        public bool $truncated,
        public int $fileSize,
        public int $actualOffset,
        public int $expectedOffset,
        public int $entries,
        public int $centralSize,
        public int $centralOffset,
    ) {}
}
