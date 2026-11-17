<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S9: a directory has a trailing slash and no bytes. Where it has a Unix type, that type is a
 * directory. A file has no trailing slash and no directory type. Without a Unix type, the slash
 * decides, as Java writes no attributes.
 *
 * @internal
 */
final readonly class TypeMismatchRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::TypeMismatch;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $entry) {
            $type = ($entry->unixMode ?? 0) & 0o170000;
            $directory = match (true) {
                $type !== 0 => $type === 0o040000,
                // The MS-DOS bit of a directory may be missing, never wrong.
                ($entry->dosAttributes & 0x10) !== 0 => true,
                default => $entry->isDirectory(),
            };

            if ($directory !== $entry->isDirectory()) {
                return sprintf('the archive entry `%s` is a %s by its name and a %s by its attributes.', $entry->name, $entry->isDirectory() ? 'directory' : 'file', $directory ? 'directory' : 'file');
            }

            if ($directory && ($entry->size !== 0 || $entry->crc !== 0)) {
                return sprintf('the directory `%s` has %d bytes and the CRC-32 %08x.', $entry->name, $entry->size, $entry->crc);
            }

            // An empty deflate stream has 2 bytes, as zlib writes it; more are data that no tool
            // extracts or checks.
            if ($directory && $entry->compressedSize > 2) {
                return sprintf('the directory `%s` has %d compressed bytes.', $entry->name, $entry->compressedSize);
            }
        }

        return null;
    }
}
