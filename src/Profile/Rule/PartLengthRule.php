<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Names;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N8: each part fits into a name of the file system, 255 bytes on Linux and macOS. The name comes
 * from the archive, so a longer one is a refusal, not an error of `unzip` in the staging directory.
 *
 * @internal
 */
final readonly class PartLengthRule implements Rule
{
    private const int PART = 255;

    public function reason(): Reason
    {
        return Reason::PartLength;
    }

    public function check(Archive $archive): ?string
    {
        foreach (array_keys($archive->listing->entries) as $index) {
            foreach (Names::of($archive, $index) as $name) {
                foreach (Names::parts($name) as $part) {
                    if (strlen($part) > self::PART) {
                        return sprintf('the archive entry `%s…` has a part of %d bytes.', substr($name, 0, 64), strlen($part));
                    }
                }
            }
        }

        return null;
    }
}
