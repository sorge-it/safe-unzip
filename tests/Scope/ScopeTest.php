<?php

declare(strict_types=1);

/**
 * The text of each row of the table in the README section Scope, by its scope: `in` or `out`.
 *
 * @return array{in: list<string>, out: list<string>, all: int}
 */
function scopeRows(): array
{
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
    $start = (int) strpos($readme, "\n### Scope\n");
    $scope = substr($readme, $start, (int) strpos($readme, "\n## ", $start + 1) - $start);
    preg_match_all('/^\| (.+) \| in\b.* \|$/m', $scope, $in);
    preg_match_all('/^\| (.+) \| out \|$/m', $scope, $out);

    // The head of the table and the line below it are not rows.
    return ['in' => $in[1], 'out' => $out[1], 'all' => preg_match_all('/^\|.*\|$/m', $scope) - 2];
}

/**
 * The guards of `out.php`.
 *
 * @return list<array{row: string, absent?: array<string, string>, none?: string}>
 */
function outGuards(): array
{
    /** @var list<array{row: string, absent?: array<string, string>, none?: string}> $guards */
    $guards = require __DIR__.'/out.php';

    return $guards;
}

/**
 * Each line of code below `src`, without the comments, by file and line.
 *
 * @return array<string, string>
 */
function sourceLines(): array
{
    $root = dirname(__DIR__, 2).'/src/';
    $lines = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $code .= is_string($token) ? $token : (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1]);
        }

        foreach (explode("\n", $code) as $number => $line) {
            $lines[substr($file->getPathname(), strlen($root)).':'.($number + 1)] = $line;
        }
    }

    return $lines;
}

/**
 * Each pattern of each guard and the line it finds, named by the row.
 *
 * @return array<string, array{string, string}>
 */
function outPatterns(): array
{
    $patterns = [];

    foreach (outGuards() as $guard) {
        foreach ($guard['absent'] ?? [] as $pattern => $line) {
            $patterns[$guard['row'].' '.$pattern] = [$pattern, $line];
        }
    }

    return $patterns;
}

it('names one guard for each out row of the Scope table, in its order, and no other', function (): void {
    $rows = scopeRows();
    $guards = array_column(outGuards(), 'row');

    expect(count($rows['in']) + count($rows['out']))->toBe($rows['all'])
        ->and($rows['in'])->not->toBeEmpty()
        ->and($rows['out'])->not->toBeEmpty()
        ->and($guards)->toHaveCount(count($rows['out']))
        ->and(array_unique($guards))->toBe($guards)
        ->and($guards)->each->not->toBe('')
        ->and(array_map(static fn (string $row, string $guard): string => str_starts_with($row, $guard) ? $guard : $row, $rows['out'], $guards))->toBe($guards);
});

it('gives each out row a check, or the reason why it has none, but not both', function (): void {
    foreach (outGuards() as $guard) {
        expect(array_diff(array_keys($guard), ['row', 'absent', 'none']))->toBe([], $guard['row'])
            ->and(($guard['none'] ?? '') !== '')->toBe(($guard['absent'] ?? []) === [], $guard['row']);
    }
});

it('finds no pattern of an out row in the code of src, without its comments, and finds the line of the pattern', function (string $pattern, string $line): void {
    $lines = sourceLines();

    // A scan that read no code would find no pattern either.
    expect(array_filter($lines, static fn (string $code): bool => str_contains($code, 'function extractTo(')))->not->toBeEmpty()
        ->and(preg_match($pattern, $line))->toBe(1)
        ->and(array_keys(array_filter($lines, static fn (string $code): bool => preg_match($pattern, $code) === 1)))->toBe([]);
})->with(outPatterns());
