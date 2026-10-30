<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S16: the version needed to extract is at most 4.5, ZIP64. `unzip` skips an entry that needs
 * more and stops with exit code 81 (measured 2026-10-29).
 *
 * @internal
 */
final readonly class VersionRule implements Rule
{
    private const int NEEDED = 45;

    public function reason(): Reason
    {
        return Reason::Version;
    }

    public function check(Archive $archive): ?string
    {
        // S1 runs before: the local header needs the same version as the central one.
        foreach ($archive->listing->entries as $index => $entry) {
            $needed = $archive->headers->central[$index]->needed;

            if ($needed > self::NEEDED) {
                return sprintf('the archive entry `%s` needs version %d.%d.', $entry->name, intdiv($needed, 10), $needed % 10);
            }
        }

        return null;
    }
}
