<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

use SorgeIt\SafeUnzip\Profile\Reason;

/**
 * The archive or the target is outside the profile "standard ZIP": `$reason` names the rule.
 * Nothing was unpacked.
 */
final class RefusedException extends UnzipException
{
    public function __construct(public readonly Reason $reason, string $detail)
    {
        parent::__construct(sprintf('%s %s: %s Nothing was unpacked.', $reason->value, $reason->title(), $detail));
    }
}
