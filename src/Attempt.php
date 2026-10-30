<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip;

use Closure;

/**
 * Runs a call of PHP and keeps its warning for the exception that replaces it, so that the caller
 * gets one error, not a warning and then an exception.
 *
 * @internal
 */
final class Attempt
{
    /**
     * @param  Closure(): (int|bool)  $call
     * @return string|null the warning of a call that failed, `null` for one that worked
     */
    public static function failure(Closure $call): ?string
    {
        $warning = 'no reason given';
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return $result === false ? $warning : null;
    }
}
