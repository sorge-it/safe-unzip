<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Names;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N3: the portable characters A-Z a-z 0-9 . _ - and `/`. No space, no control character, no byte
 * above 0x7F: no question of the encoding, of Unicode normalization or of a terminal.
 *
 * @internal
 */
final readonly class CharactersRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Characters;
    }

    public function check(Archive $archive): ?string
    {
        foreach (array_keys($archive->listing->entries) as $index) {
            foreach (Names::of($archive, $index) as $name) {
                if (preg_match('~[^A-Za-z0-9._/-]~', $name, $character) === 1) {
                    return sprintf('the archive entry `%s` has the byte 0x%02x.', addcslashes($name, "\0..\37\177..\377"), ord($character[0]));
                }
            }
        }

        return null;
    }
}
