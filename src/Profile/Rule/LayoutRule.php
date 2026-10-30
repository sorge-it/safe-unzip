<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\DataDescriptor;
use SorgeIt\SafeUnzip\Archive\EndRecords;
use SorgeIt\SafeUnzip\Archive\LocalHeader;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S3: entries without gap or overlap, then the central directory, then the end record. An overlap
 * is the zip bomb of CVE-2019-13232, which the `unzip` of macOS does not refuse. zipinfo only
 * estimates the local headers, so PHP walks them.
 *
 * @internal
 */
final readonly class LayoutRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Layout;
    }

    public function check(Archive $archive): ?string
    {
        $entries = $archive->listing->entries;
        $central = $archive->headers->central;

        if (count($central) < count($entries)) {
            // The end of the central headers PHP read; without one, the offset of the central directory.
            return sprintf('no central header of `%s` is at offset %d, where the central directory places it.', $entries[count($central)]->name, $archive->headers->centralEnd);
        }

        $order = [];

        foreach ($entries as $index => $entry) {
            $local = $archive->headers->local[$index] ?? null;

            if (! $local instanceof LocalHeader) {
                return sprintf('no local header of `%s` is at offset %d.', $entry->name, $entry->offset);
            }

            if (($local->flags & 0x8) !== 0 && ! $local->descriptor instanceof DataDescriptor) {
                return sprintf('the data descriptor of `%s` lies beyond the end of the file.', $entry->name);
            }

            $order[$index] = $entry->offset;
        }

        asort($order);
        $position = 0;
        $previous = null;

        foreach (array_keys($order) as $index) {
            $entry = $entries[$index];

            if ($entry->offset !== $position) {
                return $this->gap($position, $entry->offset, $previous === null ? 'the start' : sprintf('`%s`', $previous), sprintf('`%s`', $entry->name));
            }

            $position = $archive->headers->local[$index]->end ?? $position;
            $previous = $entry->name;
        }

        $header = $archive->listing->header;

        if ($position !== $header->centralOffset) {
            return $this->gap($position, $header->centralOffset, $previous === null ? 'the start' : sprintf('`%s`', $previous), 'the central directory');
        }

        if ($archive->headers->centralEnd !== $header->centralOffset + $header->centralSize) {
            return sprintf('the central headers end at offset %d, the end record says the central directory ends at %d.', $archive->headers->centralEnd, $header->centralOffset + $header->centralSize);
        }

        $end = $archive->headers->end;

        if ($end instanceof EndRecords && $archive->headers->centralEnd !== $end->start) {
            return $this->gap($archive->headers->centralEnd, $end->start, 'the central directory', 'the end record');
        }

        return null;
    }

    private function gap(int $end, int $start, string $before, string $after): string
    {
        return $start < $end
            ? sprintf('%s starts at offset %d, inside %s, which ends at %d.', ucfirst($after), $start, $before, $end)
            : sprintf('%d bytes that no entry uses lie between %s and %s, at offset %d.', $start - $end, $before, $after, $end);
    }
}
