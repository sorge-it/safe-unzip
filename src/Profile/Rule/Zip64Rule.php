<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\EndRecords;
use SorgeIt\SafeUnzip\Archive\Zip64EndRecord;
use SorgeIt\SafeUnzip\Archive\Zip64Locator;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S10: where a ZIP64 end record is, each field of the end record holds its value or its maximum,
 * as libzip checks it; each field at its maximum has its ZIP64 value.
 *
 * @internal
 */
final readonly class Zip64Rule implements Rule
{
    /**
     * Each field of both end records, with its maximum in the end record.
     */
    private const array FIELDS = [
        'disk' => 0xFFFF,
        'centralDisk' => 0xFFFF,
        'diskEntries' => 0xFFFF,
        'entries' => 0xFFFF,
        'centralSize' => 0xFFFFFFFF,
        'centralOffset' => 0xFFFFFFFF,
    ];

    public function reason(): Reason
    {
        return Reason::Zip64Mismatch;
    }

    public function check(Archive $archive): ?string
    {
        $end = $archive->headers->end;

        if (! $end instanceof EndRecords) {
            return null;
        }

        // A ZIP64 locator without its ZIP64 end record: zipinfo finds no end record then.
        if (! $end->zip64 instanceof Zip64EndRecord) {
            foreach (self::FIELDS as $field => $maximum) {
                if ($end->regular->{$field} === $maximum) {
                    return sprintf('the end record holds the maximum in `%s`, and there is no ZIP64 end record.', $field);
                }
            }
        } else {
            if ($end->zip64->size !== 44) {
                return sprintf('the ZIP64 end record is %d bytes long, not 56: it has extensible data.', $end->zip64->size + 12);
            }

            if (! $end->locator instanceof Zip64Locator || $end->locator->offset !== $end->start) {
                return 'the ZIP64 locator names another offset than the one of the ZIP64 end record.';
            }

            foreach (self::FIELDS as $field => $maximum) {
                $regular = $end->regular->{$field};
                $wide = $end->zip64->{$field};

                if ($wide < 0 || ($regular !== $maximum && $regular !== $wide)) {
                    return sprintf('the end record has %d in `%s`, the ZIP64 end record %d.', $regular, $field, $wide);
                }
            }
        }

        foreach ($archive->headers->central as $index => $central) {
            $local = $archive->headers->local[$index] ?? null;

            if ($central->compressedSize < 0 || $central->size < 0 || $central->offset < 0 || ($local !== null && ($local->compressedSize < 0 || $local->size < 0))) {
                return sprintf('the archive entry `%s` has a field at its maximum without its ZIP64 value.', $archive->listing->entries[$index]->name);
            }
        }

        return null;
    }
}
