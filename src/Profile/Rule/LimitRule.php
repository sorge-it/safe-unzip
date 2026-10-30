<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * L1: no more bytes than the limits allow, by the sum of the listing, before a byte is written. The
 * check pass then holds each entry to its listed size. `Archive::read()` refuses more entries than
 * the limit before the listing, and S2 holds the listing to that count.
 *
 * @internal
 */
final readonly class LimitRule implements Rule
{
    public function __construct(private Limits $limits) {}

    public function reason(): Reason
    {
        return Reason::Limit;
    }

    public function check(Archive $archive): ?string
    {
        $bytes = array_sum(array_map(static fn (Entry $entry): int => $entry->size, $archive->listing->entries));

        if ($bytes > $this->limits->bytes) {
            return sprintf('the archive unpacks to %d bytes, the limit is %d.', $bytes, $this->limits->bytes);
        }

        return null;
    }
}
