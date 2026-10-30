<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tools;

use SorgeIt\SafeUnzip\Exception\ToolException;

/**
 * The grammar of the listing is the output of UnZip 6.00 and ZipInfo 3.00. Another release fails
 * here with its first line, not later on a line of a listing that the grammar does not know.
 *
 * @internal
 */
final class Versions
{
    /**
     * The first line that each tool prints with these arguments, and what it has to start with.
     * Distributions change the part after "by".
     */
    private const array FIRST = [
        'unzip' => [['-v'], 'UnZip 6.00 of 20 April 2009, by '],
        'zipinfo' => [[], 'ZipInfo 3.00 of 20 April 2009, by '],
    ];

    public static function check(Process $process): void
    {
        foreach ([Tool::Unzip, Tool::Zipinfo] as $tool) {
            [$arguments, $start] = self::FIRST[$tool->value];
            // Also 1: another program, such as the `unzip` of BusyBox, prints its usage to the
            // errors and ends with it. A signal stays an error of the run.
            $output = $process->run($tool, $arguments, 65536, [0, 1]);
            $first = explode("\n", $output->output !== '' ? $output->output : $output->errors, 2)[0];

            if (! str_starts_with($first, $start)) {
                throw new ToolException(sprintf('`%s` is not the release of Info-ZIP that this package reads: it prints `%s`, not `%s…`.', $tool->value, $first, $start));
            }
        }
    }
}
