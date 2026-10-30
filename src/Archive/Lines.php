<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;

/**
 * The lines of a tool output, read one after the other against a closed grammar: a line that does
 * not fit stops the read.
 *
 * @internal
 */
final class Lines
{
    /** @var list<string> */
    private readonly array $lines;

    private int $position = 0;

    public function __construct(string $output, private readonly string $tool)
    {
        if ($output !== '' && ! str_ends_with($output, "\n")) {
            throw self::invalid($tool, 'does not end with a line break');
        }

        // Bytes, not characters: the output is not always UTF-8.
        $this->lines = $output === '' ? [] : explode("\n", substr($output, 0, -1));
    }

    public static function invalid(string $tool, string $what): InvalidArchiveException
    {
        return new InvalidArchiveException(sprintf('The output of %s %s. Nothing was unpacked.', $tool, $what));
    }

    public function peek(): ?string
    {
        return $this->lines[$this->position] ?? null;
    }

    public function done(): bool
    {
        return $this->position === count($this->lines);
    }

    public function next(string $what): string
    {
        return $this->lines[$this->position++] ?? throw self::invalid($this->tool, sprintf('ends before %s', $what));
    }

    public function expect(string $line): void
    {
        $read = $this->next(sprintf('`%s`', $line));

        if ($read !== $line) {
            throw self::invalid($this->tool, sprintf('has `%s` where `%s` belongs', $read, $line));
        }
    }

    /**
     * @return list<string> the groups of the match, from the first
     */
    public function match(string $pattern, string $what): array
    {
        $read = $this->next($what);

        if (preg_match($pattern, $read, $match) !== 1) {
            throw self::invalid($this->tool, sprintf('has `%s` where %s belongs', $read, $what));
        }

        return array_values(array_slice($match, 1));
    }

    public function end(): void
    {
        if (! $this->done()) {
            throw self::invalid($this->tool, sprintf('has `%s` after its end', $this->lines[$this->position]));
        }
    }
}
