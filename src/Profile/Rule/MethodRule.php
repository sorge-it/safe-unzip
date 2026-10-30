<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;
use SorgeIt\SafeUnzip\Profile\Standard;

/**
 * S12: store and deflate, the two methods of ISO/IEC 21320-1.
 *
 * @internal
 */
final readonly class MethodRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::Method;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $index => $entry) {
            $method = $archive->headers->central[$index]->method ?? null;

            if (! isset(Standard::METHODS[$entry->method]) || ($method !== null && ! in_array($method, Standard::METHODS, true))) {
                return sprintf('the archive entry `%s` uses the compression method `%s`.', $entry->name, $entry->method);
            }
        }

        return null;
    }
}
