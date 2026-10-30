<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;
use SorgeIt\SafeUnzip\Profile\Standard;

/**
 * S6: no Unicode path field `0x7075` and no Unicode comment field `0x6375`, in neither header: a
 * reader that takes the field sees another name than one that takes the name field.
 *
 * @internal
 */
final readonly class UnicodeFieldRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::UnicodeField;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $index => $entry) {
            $ids = [
                ...$entry->extraFields,
                ...$archive->headers->central[$index]->extra->ids ?? [],
                ...$archive->headers->local[$index]->extra->ids ?? [],
            ];
            $found = array_intersect($ids, Standard::UNICODE_FIELDS);

            if ($found !== []) {
                return sprintf('the archive entry `%s` has the Unicode extra field 0x%04x.', $entry->name, reset($found));
            }
        }

        return null;
    }
}
