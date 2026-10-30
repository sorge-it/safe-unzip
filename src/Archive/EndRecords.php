<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The end record, and the ZIP64 end record with its locator where there is one, as PHP reads
 * them at the offset that `zipinfo -v` names.
 *
 * @internal
 */
final readonly class EndRecords
{
    /**
     * @param  int  $start  the offset of the first record, the ZIP64 one where there is one
     * @param  int  $end  the offset after the comment of the end record
     */
    public function __construct(
        public ?Zip64EndRecord $zip64,
        public ?Zip64Locator $locator,
        public EndRecord $regular,
        public string $comment,
        public int $start,
        public int $end,
    ) {}
}
