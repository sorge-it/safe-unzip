<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tools;

use Closure;
use SorgeIt\SafeUnzip\Attempt;
use SorgeIt\SafeUnzip\Exception\ToolException;
use Throwable;

/**
 * Starts `zipinfo` and `unzip` from argument lists: no shell, the locale `C`, and without the
 * variables `UNZIP`, `UNZIPOPT`, `ZIPINFO` and `ZIPINFOOPT` of the environment. Each chunk goes to the caller as it is read, so a
 * fast tool cannot fill the memory. Modelled on the POSIX runner of `amphp/process`.
 *
 * @internal
 */
final readonly class Process
{
    /**
     * `false` removes a variable.
     */
    private const array FIXED = ['LC_ALL' => 'C', 'UNZIP' => false, 'UNZIPOPT' => false, 'ZIPINFO' => false, 'ZIPINFOOPT' => false];

    /**
     * A DOS time has no zone. `unzip` reads it in the zone of this process, as libzip does for
     * the extension; `zipinfo` lists in UTC, so that its output does not depend on the process.
     */
    private const array LISTING = ['TZ' => 'UTC'];

    /**
     * The most bytes of one read, of which a pipe gives at most 8 KiB, and the most of the errors
     * of a tool that a message keeps.
     */
    private const int CHUNK = 65536;

    private ToolLocator $locator;

    /**
     * @param  array<string, string>  $environment  added to the environment of each process
     * @param  int  $timeout  the seconds of each run of a tool; 0 is none
     */
    public function __construct(private array $environment, private int $timeout)
    {
        $this->locator = new ToolLocator($environment['PATH'] ?? null);
    }

    /**
     * Runs a tool to its end and keeps what it printed, up to `$limit` bytes: beyond them, the tool
     * stops and this throws `ToolException`, or what `$beyond` gives where the archive sets the size.
     *
     * @param  list<string>  $arguments
     * @param  list<int>  $success  the exit codes that mean success
     * @param  (Closure(): Throwable)|null  $beyond
     */
    public function run(Tool $tool, array $arguments, int $limit, array $success = [0], ?Closure $beyond = null): Output
    {
        $output = '';
        [$exit, $errors] = $this->stream($tool, $arguments, static function (string $chunk) use (&$output, $limit, $tool, $beyond): void {
            if (strlen($output) + strlen($chunk) > $limit) {
                throw $beyond instanceof Closure ? $beyond() : new ToolException(sprintf('`%s` printed more than %d bytes, its limit.', $tool->value, $limit));
            }

            $output .= $chunk;
        }, $success);

        return new Output($output, $exit, $errors);
    }

    /**
     * Hands the output on chunk by chunk and keeps none of it, so that a large entry does not fill
     * the memory. When `$chunk` throws, the tool is stopped at once.
     *
     * @param  list<string>  $arguments
     * @param  Closure(string): void  $chunk
     * @param  list<int>  $success  the exit codes that mean success
     * @return array{int, string} the exit code and the errors
     */
    public function stream(Tool $tool, array $arguments, Closure $chunk, array $success = [0]): array
    {
        $name = $tool->value;
        [$process, $output, $error] = $this->start($tool, [$this->locator->find($tool), ...$arguments]);
        // A timeout of 0 is none, as for Symfony Process; so is one too large for `hrtime()`.
        $deadline = $this->timeout > 0 && $this->timeout < 9_000_000_000 ? hrtime(true) + $this->timeout * 1_000_000_000 : PHP_INT_MAX;
        $errors = '';
        $exit = null;

        try {
            $open = [$output, $error];

            // Both pipes until each ends: a tool that fills its error pipe would wait for a read.
            while ($open !== []) {
                foreach ($this->select($name, $open, $deadline) as $pipe) {
                    $data = (string) fread($pipe, self::CHUNK);

                    if ($data !== '' && $pipe === $output) {
                        $chunk($data);
                    } elseif ($data !== '') {
                        $errors = substr($errors.$data, -self::CHUNK);
                    } elseif (feof($pipe)) {
                        $open = array_filter($open, static fn (mixed $other): bool => $other !== $pipe);
                    }
                }
            }

            $exit = $this->wait($name, $process, $deadline);
        } finally {
            // A tool that still runs, such as after `$chunk` threw or at the timeout, stops at once.
            if ($exit === null) {
                proc_terminate($process, 9);
            }

            fclose($output);
            fclose($error);
            proc_close($process);
        }

        if (! in_array($exit, $success, true)) {
            throw new ToolException(sprintf('`%s` stopped with exit code %d: %s', $name, $exit, trim($errors)));
        }

        return [$exit, $errors];
    }

    /**
     * The tool runs in its own directory: each path is absolute, and the working directory of this
     * process can be gone. Its input is empty.
     *
     * @param  non-empty-list<string>  $command
     * @return array{resource, resource, resource} the process, and the pipes of its output and its errors
     */
    private function start(Tool $tool, array $command): array
    {
        $name = $tool->value;
        $variables = $this->variables($tool);
        $process = false;
        $pipes = [];
        $reason = Attempt::failure(static function () use ($command, $variables, &$process, &$pipes): bool {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($command[0]), $variables);

            return $process !== false;
        });

        if ($reason !== null || ! is_resource($process) || ! isset($pipes[0], $pipes[1], $pipes[2])) {
            throw new ToolException(sprintf('`%s` did not start: %s', $name, $reason ?? 'no reason given'));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * The environment of a tool: this process, `$this->environment`, then the fixed ones; `false`
     * removes a variable.
     *
     * @return array<string, string>
     */
    private function variables(Tool $tool): array
    {
        // In a SAPI such as FPM, also the parameters of the request. A name of digits, as from
        // `env 1=UNZIP=-j`, comes as an integer key, and `proc_open()` passes its value as
        // `UNZIP=-j`, past `FIXED`.
        $variables = array_filter(getenv(), is_string(...), ARRAY_FILTER_USE_KEY);

        foreach ([...$this->environment, ...self::FIXED, ...($tool === Tool::Zipinfo ? self::LISTING : [])] as $variable => $value) {
            if ($value === false) {
                unset($variables[$variable]);
            } else {
                $variables[$variable] = $value;
            }
        }

        return $variables;
    }

    /**
     * The pipes that can be read within 100 ms.
     *
     * @param  array<resource>  $pipes
     * @return array<resource>
     */
    private function select(string $name, array $pipes, int $deadline): array
    {
        $left = $deadline - hrtime(true);

        if ($left <= 0) {
            throw new ToolException(sprintf('`%s` stopped: it exceeded the timeout of %d seconds.', $name, $this->timeout));
        }

        $read = $pipes;
        $write = null;
        $except = null;
        $wait = intdiv((int) min($left, 100_000_000), 1000);
        // Such as for a pipe beyond `FD_SETSIZE`, or a signal that stops the wait.
        $reason = Attempt::failure(static function () use (&$read, &$write, &$except, $wait): bool {
            return stream_select($read, $write, $except, 0, $wait) !== false;
        });

        if ($reason !== null) {
            throw new ToolException(sprintf('`%s` stopped: stream_select() failed: %s', $name, $reason));
        }

        return $read;
    }

    /**
     * The exit code, once the tool ended; a signal counts as 128 and its number, as in a shell.
     *
     * @param  resource  $process
     */
    private function wait(string $name, mixed $process, int $deadline): int
    {
        while (($status = proc_get_status($process))['running']) {
            if (hrtime(true) >= $deadline) {
                throw new ToolException(sprintf('`%s` stopped: it exceeded the timeout of %d seconds.', $name, $this->timeout));
            }

            usleep(1000);
        }

        return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
    }
}
