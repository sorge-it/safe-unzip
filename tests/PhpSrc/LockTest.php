<?php

declare(strict_types=1);

/**
 * The commit, its date, and the git blob of each file by name, as `php-src.lock` lists them.
 *
 * @return array{string, string, array<string, string>}
 */
function phpSrcLock(): array
{
    $commit = '';
    $date = '';
    $files = [];

    foreach (file(fixture('php-src.lock'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^# .* at ([0-9a-f]{40}) \((\d{4}-\d{2}-\d{2})\)/', $line, $head) === 1) {
            [, $commit, $date] = $head;
        } elseif (preg_match('/^([0-9a-f]{40})  (\S.*)$/', $line, $file) === 1) {
            $files[$file[2]] = $file[1];
        } else {
            throw new UnexpectedValueException(sprintf('php-src.lock has a line that is neither its head nor a blob and a name: "%s"', $line));
        }
    }

    return [$commit, $date, $files];
}

/**
 * The git blob of each fixture of php-src by name, without the license.
 *
 * @return array<string, string>
 */
function phpSrcFixtures(): array
{
    $fixtures = [];

    // A name that starts with a dot belongs to the system, such as `.DS_Store` of macOS.
    foreach (array_diff(preg_grep('/^\./', scandir(fixture('php-src')) ?: [], PREG_GREP_INVERT) ?: [], ['LICENSE']) as $name) {
        $content = (string) file_get_contents(fixture('php-src/'.$name));
        $fixtures[$name] = sha1('blob '.strlen($content)."\0".$content);
    }

    ksort($fixtures);

    return $fixtures;
}

it('holds each archive of the lock byte for byte, and no other file', function (): void {
    [, , $files] = phpSrcLock();
    // The tests and their helper files stay in php-src; a new suffix needs a decision.
    $archives = array_filter($files, static fn (string $name): bool => preg_match('/\.(phpt|inc|xml)$/', $name) !== 1, ARRAY_FILTER_USE_KEY);
    ksort($archives);

    expect($archives)->not->toBe([])
        ->and(phpSrcFixtures())->toBe($archives);
});

it('gives each fixture of php-src a case in a dataset of PhpSrcTest', function (): void {
    // Without its comments, so that a case in a comment does not count.
    $code = implode('', array_map(
        static fn (array|string $token): string => is_string($token) ? $token : (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]),
        token_get_all((string) file_get_contents(__DIR__.'/PhpSrcTest.php')),
    ));
    preg_match_all("/^\s+'[^']+' => \[.*$/m", $code, $rows);
    preg_match_all("/'php-src\/([^']+)'/", implode("\n", $rows[0]), $cases);

    expect(phpSrcFixtures())->not->toBe([])
        ->and(array_values(array_diff(array_keys(phpSrcFixtures()), $cases[1])))->toBe([]);
});

it('names only the commit of the lock in each README', function (string $readme): void {
    [$commit, $date] = phpSrcLock();
    preg_match_all('/`([0-9a-f]{8})` \((\d{4}-\d{2}-\d{2})\)/', (string) file_get_contents(__DIR__.'/../../'.$readme), $named, PREG_SET_ORDER);

    expect($commit)->toMatch('/^[0-9a-f]{40}$/')
        ->and($named)->not->toBe([])
        ->and(array_values(array_unique(array_map(static fn (array $match): string => $match[0], $named))))->toBe([sprintf('`%s` (%s)', substr($commit, 0, 8), $date)]);
})->with(['README.md', 'tests/Fixtures/README.md']);
