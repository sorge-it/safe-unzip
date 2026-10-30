<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S2: the end record counts exactly the central headers there are. Info-ZIP lists each central
 * header up to the first other signature, so the count of its listing is the count there is.
 *
 * @internal
 */
final readonly class EntryCountRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::EntryCount;
    }

    public function check(Archive $archive): ?string
    {
        $counted = $archive->listing->header->entries;
        $listed = count($archive->listing->entries);

        if ($counted !== $listed) {
            return sprintf('the end record counts %d entries, zipinfo lists %d.', $counted, $listed);
        }

        return null;
    }
}
