<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\EndRecords;
use SorgeIt\SafeUnzip\Archive\Zip64Locator;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S8: one file, one disk. Each disk number is 0, each entry starts on disk 1 as `zipinfo` counts.
 *
 * @internal
 */
final readonly class MultiDiskRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::MultiDisk;
    }

    public function check(Archive $archive): ?string
    {
        $end = $archive->headers->end;

        if ($end instanceof EndRecords) {
            $regular = $end->regular;

            foreach (['end record' => $regular, 'ZIP64 end record' => $end->zip64] as $name => $record) {
                if ($record !== null && ($record->disk !== 0 || $record->centralDisk !== 0)) {
                    return sprintf('the %s names disk %d and the central directory on disk %d.', $name, $record->disk, $record->centralDisk);
                }

                if ($record !== null && $record->diskEntries !== $record->entries) {
                    return sprintf('the %s counts %d entries on this disk and %d in all.', $name, $record->diskEntries, $record->entries);
                }
            }

            if ($end->locator instanceof Zip64Locator && ($end->locator->disk !== 0 || $end->locator->disks !== 1)) {
                return sprintf('the ZIP64 locator names disk %d of %d disks.', $end->locator->disk, $end->locator->disks);
            }
        }

        foreach ($archive->listing->entries as $index => $entry) {
            $disk = $archive->headers->central[$index]->disk ?? 0;

            if ($entry->disk !== 1 || $disk !== 0) {
                return sprintf('the archive entry `%s` starts on another disk.', $entry->name);
            }
        }

        return null;
    }
}
