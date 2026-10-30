<?php

declare(strict_types=1);

use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Tests\Support\Fixtures;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

/**
 * A new empty directory below `.tmp/tests`, removed after the run.
 */
function temporaryDirectory(): string
{
    $directory = __DIR__.'/../.tmp/tests/'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);
    register_shutdown_function(static fn () => removeDirectory($directory));

    return (string) realpath($directory);
}

function removeDirectory(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    chmod($path, 0o755);

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        removeDirectory($path.'/'.$entry);
    }

    rmdir($path);
}

/**
 * Writes a built archive into a new temporary directory.
 */
function archive(ZipFixture $fixture, string $name = 'a.zip'): string
{
    return $fixture->write(temporaryDirectory().'/'.$name);
}

/**
 * A `ZipArchive` with the small limits of the fixture catalogue.
 *
 * @param  array<string, string>  $environment
 */
function zip(array $environment = []): ZipArchive
{
    return new ZipArchive(new Limits(...Fixtures::LIMITS), $environment);
}

/**
 * Each node below a directory with its type and mode, and the content of each file.
 *
 * @return array<string, string>
 */
function tree(string $directory): array
{
    $nodes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $path => $info) {
        assert($info instanceof SplFileInfo);
        $name = substr((string) $path, strlen($directory) + 1);
        $nodes[$name] = match (true) {
            $info->isLink() => 'link',
            $info->isDir() => sprintf('dir %o', $info->getPerms() & 0o7777),
            default => sprintf('file %o %s', $info->getPerms() & 0o7777, file_get_contents((string) $path)),
        };
    }

    ksort($nodes);

    return $nodes;
}

/**
 * Runs PHP code in a new process with the autoloader and options of `php -d`, and returns what
 * it printed. Under a coverage run with pcov, the lines the process ran count for the test; under
 * Xdebug they do not, so `composer test:coverage` needs pcov.
 *
 * @param  list<string>  $options
 * @param  int|null  $files  the limit of open files of the process, as `ulimit -n`
 * @param  list<string>  $variables  set by `env` before PHP starts, such as `1=UNZIP=-j`, a name that
 *                                   `putenv()` cannot give
 */
function php(string $code, array $options = [], ?int $files = null, array $variables = []): string
{
    $coverage = extension_loaded('pcov') && ini_get('pcov.enabled') === '1' && CodeCoverage::instance()->isActive() ? temporaryDirectory().'/coverage' : null;
    // No test disables `file_put_contents()` or `serialize()`.
    $collect = $coverage === null ? '' : sprintf('\pcov\start(); register_shutdown_function(static function (): void { \pcov\stop(); file_put_contents(%s, serialize(\pcov\collect())); });', var_export($coverage, true));
    $command = [PHP_BINARY, ...array_merge(...array_map(static fn (string $option): array => ['-d', $option], $options)), '-r', $collect.'require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; '.$code];

    if ($variables !== []) {
        $command = ['/usr/bin/env', ...$variables, ...$command];
    }

    if ($files !== null) {
        $command = ['/bin/sh', '-c', sprintf('ulimit -n %d && exec "$@"', $files), 'sh', ...$command];
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert(is_resource($process));
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    proc_close($process);

    if ($coverage !== null) {
        if (! is_file($coverage)) {
            throw new LogicException('The PHP process of the test wrote no coverage, such as after a crash. It printed: '.$output);
        }

        $lines = unserialize((string) file_get_contents($coverage));
        assert(is_array($lines));
        // Both classes are internal to PHPUnit; only the coverage job of the CI, on composer.lock, reaches them.
        /** @var array<string, array<int, int>> $lines */
        CodeCoverage::instance()->codeCoverage()->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($lines));
    }

    return $output;
}

/**
 * Puts a tool first in `PATH`, as a POSIX sh body, and returns the environment for it.
 *
 * @return array<string, string>
 */
function fakeTool(string $directory, string $name, string $body): array
{
    if (! is_dir($directory.'/bin')) {
        mkdir($directory.'/bin', 0o755, true);
    }

    file_put_contents($directory.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
    chmod($directory.'/bin/'.$name, 0o755);

    return ['PATH' => $directory.'/bin:'.getenv('PATH')];
}
