<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;

/*
 * Each PHP example of the README is a whole file. These tests run it as the README shows it, with
 * only its paths or its URL replaced, so the README shows no code that does not run.
 */

/**
 * The README, the one source of each example.
 */
function readme(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
}

/**
 * The first PHP block after a heading of the README, without its fence.
 */
function readmeBlock(string $heading): string
{
    $at = strpos(readme(), "\n".$heading."\n");

    if ($at === false) {
        throw new LogicException(sprintf('The README has no heading `%s`.', $heading));
    }

    $start = (int) strpos(readme(), "```php\n", $at) + strlen("```php\n");

    return substr(readme(), $start, (int) strpos(readme(), "```\n", $start) - $start);
}

/**
 * Writes a README block into a file, with the autoloader of this checkout and each replacement,
 * and returns the path of the file.
 *
 * @param  array<string, string>  $replacements  each must occur in the block
 */
function readmeScript(string $code, string $directory, array $replacements = []): string
{
    $replacements["__DIR__.'/vendor/autoload.php'"] = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);

    foreach (array_keys($replacements) as $search) {
        if (! str_contains($code, $search)) {
            throw new LogicException(sprintf('The README block has no `%s`.', $search));
        }
    }

    $file = $directory.'/script.php';
    file_put_contents($file, strtr($code, $replacements));

    return $file;
}

/**
 * Runs a script in a PHP process of its own, whose temporary directory is the given one, and
 * returns its exit code, output and errors.
 *
 * @return array{int, string, string}
 */
function runReadmeScript(string $file, string $temporary): array
{
    $process = proc_open([PHP_BINARY, '-d', 'sys_temp_dir='.$temporary, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert(is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $output, $errors];
}

/**
 * Posts an archive as the form field `archive`, as a browser does, and returns the status and the
 * body of the answer.
 *
 * @return array{int, string}
 */
function uploadArchive(string $url, string $archive): array
{
    $boundary = bin2hex(random_bytes(8));
    $body = sprintf("--%s\r\nContent-Disposition: form-data; name=\"archive\"; filename=\"upload.zip\"\r\nContent-Type: application/zip\r\n\r\n%s\r\n--%s--\r\n", $boundary, $archive, $boundary);
    $answer = file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: multipart/form-data; boundary='.$boundary."\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 60,
    ]]));
    preg_match('~\AHTTP/\S+ (\d{3}) ~', (http_get_last_response_headers() ?? [])[0] ?? '', $status);

    return [(int) ($status[1] ?? 0), (string) $answer];
}

it('extracts with the quick start, and names the rule of a refused archive', function (): void {
    $code = readmeBlock('## Quick start');
    $directory = temporaryDirectory();
    $script = readmeScript($code, $directory, [
        '/path/to/archive.zip' => archive(ZipFixture::new()->directory('bin/')->file('bin/run.sh', "#!/bin/sh\n", ['mode' => 0o100755])),
        '/path/to/target' => $directory.'/target',
    ]);

    expect(runReadmeScript($script, $directory))->toBe([0, "Entries extracted: 2\n", ''])
        ->and(is_executable($directory.'/target/bin/run.sh'))->toBeTrue();

    $refused = temporaryDirectory();
    $script = readmeScript($code, $refused, [
        '/path/to/archive.zip' => archive(ZipFixture::new()->file('docs/My Report.pdf', "a\n")),
        '/path/to/target' => $refused.'/target',
    ]);
    [$exit, $output, $errors] = runReadmeScript($script, $refused);

    expect([$exit, $output])->toBe([1, ''])
        ->and($errors)->toStartWith('Refused (archive): Characters, N3 ')
        ->and(file_exists($refused.'/target'))->toBeFalse();
});

it('takes an upload with the upload example, and answers a refused one with its rule', function (): void {
    $directory = temporaryDirectory();
    $script = readmeScript(readmeBlock('## Extracting an upload'), $directory);
    $server = proc_open([PHP_BINARY, '-d', 'sys_temp_dir='.$directory, '-S', '127.0.0.1:0', $script], [2 => ['pipe', 'w']], $pipes);
    assert(is_resource($server));

    try {
        // PHP prints `… Development Server (http://127.0.0.1:<port>) started` once it listens; a
        // warning of the PHP build can come first.
        $printed = '';
        $match = [];
        while ($match === [] && ($line = fgets($pipes[2])) !== false) {
            $printed .= $line;
            preg_match('~\((http://127\.0\.0\.1:\d+)\) started~', $line, $match);
        }

        $url = $match[1] ?? throw new LogicException('The PHP server did not start: '.$printed);

        expect(uploadArchive($url, ZipFixture::new()->file('a.txt', "a\n")->build()))->toBe([200, "Entries extracted: 1\n"])
            ->and(glob($directory.'/upload-*/files/a.txt'))->toHaveCount(1)
            ->and(uploadArchive($url, ZipFixture::new()->file('My Report.pdf', "a\n")->build()))->toBe([422, 'Refused by rule N3: '.Reason::Characters->title()."\n"])
            ->and(uploadArchive($url, "not a ZIP file\n"))->toBe([422, "This is not a valid ZIP file.\n"])
            // Each archive is removed, extracted or refused; the extracted files stay.
            ->and(glob($directory.'/upload-*/upload.zip'))->toBe([]);
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
});

it('extracts the GitHub zipball with the zipball example, and prints what the README says', function (): void {
    $directory = temporaryDirectory();
    $script = readmeScript(readmeBlock('## Extracting a GitHub zipball'), $directory, [
        'https://api.github.com/repos/sorge-it/phpunit-pest-html-assertions/zipball/v1.4.2' => (string) realpath(__DIR__.'/../Fixtures/github-format.zip'),
    ]);
    [$exit, $output, $errors] = runReadmeScript($script, $directory);
    preg_match('~^It prints `Commit ([0-9a-f]{40}) in …(/files/[a-z0-9-]+)`\.$~m', readme(), $printed);

    expect([$exit, $errors])->toBe([0, ''])
        ->and($printed)->toHaveCount(3)
        ->and($output)->toMatch(sprintf('~\ACommit %s in %s/zipball-[0-9a-f]{16}%s\n\z~', $printed[1] ?? '', preg_quote($directory, '~'), $printed[2] ?? ''));
});

it('names the Reason case of each rule in the tables of refusals', function (): void {
    preg_match_all('~^\| ([SNL]\d+) \| `(\w+)` \|~m', readme(), $rows, PREG_SET_ORDER);
    $cases = [];

    foreach (Reason::cases() as $case) {
        $cases[$case->value] = $case->name;
    }

    expect(array_column($rows, 2, 1))->toBe($cases);
});
