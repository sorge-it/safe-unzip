<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Names;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * N1: a relative name, each part a name: no `/` at the start, no empty part, no `.` and no `..`.
 * Go refuses the same as `ErrInsecurePath`.
 *
 * @internal
 */
final readonly class TraversalRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Traversal;
    }

    public function check(Archive $archive): ?string
    {
        foreach (array_keys($archive->listing->entries) as $index) {
            foreach (Names::of($archive, $index) as $name) {
                if (array_intersect(Names::parts($name), ['', '.', '..']) !== []) {
                    return sprintf('the archive entry `%s` is absolute or has an empty part, `.` or `..`.', $name);
                }
            }
        }

        return null;
    }
}
