<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use LogicException;
use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;
use SorgeIt\SafeUnzip\Profile\Standard;

/**
 * S13: only the extra fields of the allow list, each once and with a length that fits, in both
 * headers.
 *
 * @internal
 */
final readonly class ExtraFieldRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::ExtraField;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $index => $entry) {
            // S3 refuses an entry without a local header first.
            $local = $archive->headers->local[$index] ?? throw new LogicException('S3 runs before S13.');

            foreach (['central' => $archive->headers->central[$index], 'local' => $local] as $header => $read) {
                if (! $read->extra->wellFormed) {
                    return sprintf('the %s extra field of `%s` has subfields whose lengths do not add up to its length.', $header, $entry->name);
                }

                $other = array_diff($read->extra->ids, Standard::EXTRA_FIELDS);

                if ($other !== []) {
                    return sprintf('the %s header of `%s` has the extra field 0x%04x.', $header, $entry->name, reset($other));
                }

                // Parsers differ in which of two fields with one ID they take.
                $twice = array_diff_assoc($read->extra->ids, array_unique($read->extra->ids));

                if ($twice !== []) {
                    return sprintf('the %s header of `%s` has the extra field 0x%04x twice.', $header, $entry->name, reset($twice));
                }
            }
        }

        return null;
    }
}
