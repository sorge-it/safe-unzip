<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

use SorgeIt\SafeUnzip\Archive\Archive;

/**
 * The names of an entry as its central and its local header hold them, so that no spelling of
 * one entry passes unchecked. `zipinfo` shows a byte outside ASCII as `?`, so its name counts
 * only where PHP read no header; S1 compares it with the central one.
 *
 * @internal
 */
final class Names
{
    /**
     * @return list<string>
     */
    public static function of(Archive $archive, int $index): array
    {
        $names = array_filter([$archive->headers->central[$index]->name ?? null, $archive->headers->local[$index]->name ?? null], is_string(...));

        return $names === [] ? [$archive->listing->entries[$index]->name] : array_values(array_unique($names));
    }

    /**
     * The parts of a name; a directory loses its one trailing slash first.
     *
     * @return list<string>
     */
    public static function parts(string $name): array
    {
        return explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
    }
}
