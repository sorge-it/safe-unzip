<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N5: a file or a directory. A link could point out of the target; a device, a FIFO or a socket
 * is no content.
 *
 * @internal
 */
final readonly class EntryTypeRule implements Rule
{
    private const array TYPES = [
        0o010000 => 'a FIFO',
        0o020000 => 'a character device',
        0o060000 => 'a block device',
        0o120000 => 'a symbolic link',
        0o140000 => 'a socket',
    ];

    public function reason(): Reason
    {
        return Reason::EntryType;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $entry) {
            $type = ($entry->unixMode ?? 0) & 0o170000;

            if (! in_array($type, [0, 0o100000, 0o040000], true)) {
                return sprintf('the archive entry `%s` is %s.', $entry->name, self::TYPES[$type] ?? sprintf('of the Unix type %o', $type >> 12));
            }

            if (($entry->dosAttributes & 0x08) !== 0) {
                return sprintf('the archive entry `%s` is a volume label.', $entry->name);
            }
        }

        return null;
    }
}
