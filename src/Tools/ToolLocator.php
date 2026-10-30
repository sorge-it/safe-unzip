<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tools;

use SorgeIt\SafeUnzip\Exception\ToolException;

/**
 * Finds a tool in the `PATH` that the tool gets, once: the binary whose release was checked is the
 * one that runs each time, also where the `PATH` of this process changes later.
 *
 * @internal
 */
final class ToolLocator
{
    /**
     * @var array<string, string>
     */
    private array $found = [];

    /**
     * @param  string|null  $path  the `PATH` of the tools; `null` for the one of this process
     */
    public function __construct(private readonly ?string $path) {}

    /**
     * `proc_open()` looks a name up in the `PATH` of this PHP process, not in the `PATH` given to
     * the child. So the name is resolved here, against the `PATH` the child gets.
     */
    public function find(Tool $tool): string
    {
        return $this->found[$tool->value] ??= $this->search($tool);
    }

    private function search(Tool $tool): string
    {
        // Without `PATH`, such as in FPM with `clear_env`, where `execvp()` of macOS looks; glibc
        // looks there too, and musl also in `/usr/local/bin`.
        $path = $this->path ?? (getenv('PATH', true) ?: '/usr/bin:/bin');

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            // Only an absolute directory: the tool runs in its own directory, and a relative one
            // would name another one there.
            if (! str_starts_with($directory, '/')) {
                continue;
            }

            $file = $directory.'/'.$tool->value;

            if (is_file($file) && is_executable($file)) {
                return $file;
            }
        }

        throw new ToolException(sprintf('`%s` is not installed. It comes with the package `unzip` of the system.', $tool->value));
    }
}
