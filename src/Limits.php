<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip;

use ValueError;

/**
 * How large an archive may be. The entries are counted from the end record, before the listing is
 * read; the bytes are the sum of the listing, before a byte is written.
 */
final readonly class Limits
{
    public function __construct(public int $entries = 10_000, public int $bytes = 2 * 1024 ** 3)
    {
        if ($entries < 0) {
            throw new ValueError(sprintf('%s::__construct(): Argument #1 ($entries) must be greater than or equal to 0', self::class));
        }

        if ($bytes < 0) {
            throw new ValueError(sprintf('%s::__construct(): Argument #2 ($bytes) must be greater than or equal to 0', self::class));
        }
    }
}
