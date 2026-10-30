<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S7: of the general purpose flags only bits 1 and 2 (the deflate option), 3 (data descriptor, with
 * deflate only) and 11 (UTF-8), in the central and the local header. `zipinfo -v` shows bit 0 only,
 * so PHP reads them.
 *
 * @internal
 */
final readonly class FlagsRule implements Rule
{
    private const int ALLOWED = 0x2 | 0x4 | 0x8 | 0x800;

    public function reason(): Reason
    {
        return Reason::Flags;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $index => $entry) {
            if ($entry->encrypted) {
                return sprintf('the archive entry `%s` is encrypted.', $entry->name);
            }

            foreach (['central' => $archive->headers->central[$index] ?? null, 'local' => $archive->headers->local[$index] ?? null] as $header => $read) {
                if ($read !== null && ($read->flags & ~self::ALLOWED) !== 0) {
                    return sprintf('the %s header of `%s` has the flags 0x%04x, of which 0x%04x are outside the profile, such as encryption, patch data or a masked header.', $header, $entry->name, $read->flags, $read->flags & ~self::ALLOWED);
                }

                // Stored data with a data descriptor has no end that a reader of the stream can
                // know: some take the first descriptor signature in the data, so the data can hold
                // a second entry (ZipDiff C1). Java ZipInputStream refuses this case too.
                if ($read !== null && $read->method === 0 && ($read->flags & 0x8) !== 0) {
                    return sprintf('the archive entry `%s` is stored with a data descriptor, by its %s header.', $entry->name, $header);
                }
            }
        }

        if ($archive->headers->next === "PK\5\5") {
            return 'a digital signature record follows the central directory.';
        }

        return null;
    }
}
