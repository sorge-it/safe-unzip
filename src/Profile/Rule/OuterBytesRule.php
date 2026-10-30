<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\EndRecords;
use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S4: the archive starts at offset 0 and ends with its end record. A self-extracting archive has
 * a program before it, with offsets counted from the file or from the archive.
 *
 * @internal
 */
final readonly class OuterBytesRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::OuterBytes;
    }

    public function check(Archive $archive): ?string
    {
        $header = $archive->listing->header;
        $entries = $archive->listing->entries;
        // Where each central header is at its stated offset, nothing moved them: bytes inside the
        // archive, such as a signature record, are for S7 and S3.
        $placed = $entries !== [] && count($archive->headers->central) === count($entries);

        // An end record before its place is a central directory too long for the file, for S3.
        if ($header->actualOffset > $header->expectedOffset && ! $placed) {
            return sprintf('the end record is at offset %d, its central directory places it at %d: %d bytes lie before the archive or inside it.', $header->actualOffset, $header->expectedOffset, $header->actualOffset - $header->expectedOffset);
        }

        $offsets = array_map(static fn (Entry $entry): int => $entry->offset, $entries);
        $first = $offsets === [] ? $header->actualOffset : min($offsets);

        if ($first !== 0) {
            return sprintf('the archive starts at offset %d, after %d bytes of something else.', $first, $first);
        }

        $end = $archive->headers->end;

        if ($end instanceof EndRecords && $end->end !== $header->fileSize) {
            return sprintf('%d bytes follow the end record.', $header->fileSize - $end->end);
        }

        return null;
    }
}
