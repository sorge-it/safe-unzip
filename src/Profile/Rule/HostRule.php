<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;
use SorgeIt\SafeUnzip\Profile\Standard;

/**
 * S14: Unix, FAT and NTFS. An entry of FAT or NTFS has no Unix bits: Info-ZIP would take such
 * bits as the type and the mode, a link among them.
 *
 * @internal
 */
final readonly class HostRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Host;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $entry) {
            $host = Standard::HOSTS[$entry->host] ?? null;

            if ($host === null || $entry->otherAttributes) {
                return sprintf('the archive entry `%s` comes from the host system `%s`.', $entry->name, $entry->host);
            }

            if ($host !== Standard::UNIX && $entry->externalHigh !== 0) {
                return sprintf('the archive entry `%s` of host `%s` has Unix attributes.', $entry->name, $entry->host);
            }
        }

        return null;
    }
}
