<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Exception\ToolException;
use SorgeIt\SafeUnzip\Exception\UnsupportedException;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->file = archive(ZipFixture::new()->file('a.txt', "a\n"));
});

it('names a tool that is not installed', function (): void {
    mkdir($this->directory.'/empty');
    $zip = new ZipArchive(environment: ['PATH' => $this->directory.'/empty']);

    expect(fn (): bool => $zip->open($this->file))->toThrow(ToolException::class, '`unzip` is not installed');
});

it('finds no tool in a relative directory of PATH', function (): void {
    $zip = new ZipArchive(environment: ['PATH' => 'bin:.']);

    expect(fn (): bool => $zip->open($this->file))->toThrow(ToolException::class, '`unzip` is not installed');
});

it('names the exit code of a tool that stops otherwise', function (): void {
    $environment = fakeTool($this->directory, 'zipinfo', 'echo "killed" >&2; exit 137');

    expect(fn (): bool => new ZipArchive(environment: $environment)->open($this->file))->toThrow(ToolException::class, '`zipinfo` stopped with exit code 137: killed');
});

it('stops a tool at its timeout', function (): void {
    $environment = fakeTool($this->directory, 'zipinfo', '[ "$1" = -v ] && sleep 5; exec /usr/bin/zipinfo "$@"');
    $zip = new ZipArchive(environment: $environment, timeout: 1);
    $start = hrtime(true);

    expect(fn (): bool => $zip->open($this->file))->toThrow(ToolException::class, '`zipinfo` stopped: it exceeded the timeout of 1 seconds.')
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(4.0);
});

it('stops a tool with an exception where stream_select() fails, as a pipe lies beyond FD_SETSIZE', function (): void {
    // 1100 open files push the pipes of the tools beyond the 1024 of `FD_SETSIZE`.
    $code = sprintf('$files = []; for ($i = 0; $i < 1100; $i++) { $files[] = fopen(%s, "rb"); } try { (new SorgeIt\SafeUnzip\ZipArchive)->open(%s); echo "opened"; } catch (Throwable $throwable) { echo $throwable::class, ": ", $throwable->getMessage(); }', var_export(__FILE__, true), var_export($this->file, true));

    expect(php($code, files: 4096))->toStartWith('SorgeIt\SafeUnzip\Exception\ToolException: `unzip` stopped: stream_select() failed: ');
});

it('names a tool that cannot start, as no file descriptor is free', function (): void {
    // The first `open()` and `class_exists()` load each class; then each descriptor is taken, and
    // the second object checks the release of the tools again.
    $code = sprintf('$zip = new SorgeIt\SafeUnzip\ZipArchive; $zip->open(%1$s); class_exists(%3$s); $files = []; while (($file = @fopen(%2$s, "rb")) !== false) { $files[] = $file; } try { (new SorgeIt\SafeUnzip\ZipArchive)->open(%1$s); } catch (Throwable $throwable) { echo $throwable::class, ": ", $throwable->getMessage(); } finally { $files = []; }', var_export($this->file, true), var_export(__FILE__, true), var_export(ToolException::class, true));

    expect(php($code, files: 64))->toStartWith('SorgeIt\SafeUnzip\Exception\ToolException: `unzip` did not start: proc_open()');
});

it('stops a tool at its timeout that closed its pipes and still runs', function (): void {
    $environment = fakeTool($this->directory, 'zipinfo', '[ "$1" = -v ] && { exec >&- 2>&-; sleep 5; }; exec /usr/bin/zipinfo "$@"');
    $zip = new ZipArchive(environment: $environment, timeout: 1);
    $start = hrtime(true);

    expect(fn (): bool => $zip->open($this->file))->toThrow(ToolException::class, '`zipinfo` stopped: it exceeded the timeout of 1 seconds.')
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(4.0);
});

it('refuses a negative timeout', function (): void {
    expect(fn (): ZipArchive => new ZipArchive(timeout: -1))->toThrow(ValueError::class, 'must be greater than or equal to 0');
});

it('refuses a name of a variable that is empty or a number, or holds `=` or a NUL byte', function (array $environment): void {
    expect(fn (): ZipArchive => new ZipArchive(environment: $environment))->toThrow(ValueError::class, 'Argument #2 ($environment) must only have names without `=` and NUL bytes as keys, and no number');
})->with([
    'empty' => [['' => 'x']],
    'a number' => [['5' => 'x']],
    'with =' => [['PATH=/x' => 'y']],
    'with NUL' => [["PATH\0" => 'y']],
]);

it('refuses a negative limit', function (): void {
    expect(fn (): Limits => new Limits(entries: -1))->toThrow(ValueError::class, 'Argument #1 ($entries) must be greater than or equal to 0')
        ->and(fn (): Limits => new Limits(bytes: -1))->toThrow(ValueError::class, 'Argument #2 ($bytes) must be greater than or equal to 0');
});

it('does not overflow the bound of the listing for an archive that counts PHP_INT_MAX entries', function (): void {
    $file = archive(ZipFixture::new()->file('a.txt', "a\n")->zip64(['entries' => -1, 'diskEntries' => -1]));

    $code = sprintf('try { (new SorgeIt\SafeUnzip\ZipArchive(new SorgeIt\SafeUnzip\Limits(entries: PHP_INT_MAX)))->open(%s); echo "opened"; } catch (SorgeIt\SafeUnzip\Exception\RefusedException $e) { echo $e->reason->value; } catch (Throwable $t) { echo get_class($t); }', var_export($file, true));

    // Without memory_limit, the bound of the listing is the only one; the profile then refuses the
    // two counts.
    expect(php($code, ['memory_limit=-1']))->toBe('S10');
});

it('stops a tool that prints beyond its limit', function (): void {
    // The count of entries, whose size the archive does not set.
    $environment = fakeTool($this->directory, 'zipinfo', '[ "$1" = -h ] || exec /usr/bin/zipinfo "$@"; yes "Archive:  x"');

    expect(fn (): bool => new ZipArchive(environment: $environment)->open($this->file))->toThrow(ToolException::class, '`zipinfo` printed more than');
});

it('starts zipinfo without the options of the environment, in the locale C and in UTC', function (): void {
    $log = $this->directory.'/environment';
    $environment = fakeTool($this->directory, 'zipinfo', sprintf('echo "[$UNZIP][$UNZIPOPT][$ZIPINFO][$ZIPINFOOPT][$LC_ALL][$TZ]" >> %s; exec /usr/bin/zipinfo "$@"', escapeshellarg($log)));
    $zip = new ZipArchive(environment: $environment + ['UNZIP' => '-K', 'UNZIPOPT' => '-K', 'ZIPINFO' => '-1', 'ZIPINFOOPT' => '-1', 'LC_ALL' => 'de_DE.UTF-8', 'TZ' => 'Europe/Berlin']);
    $zip->open($this->file);

    // The release, the count and the listing.
    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['[][][][][C][UTC]', '[][][][][C][UTC]', '[][][][][C][UTC]']);
});

it('starts unzip without the options of the environment, in the locale C and in the zone of the process', function (): void {
    $log = $this->directory.'/environment';
    $environment = fakeTool($this->directory, 'unzip', sprintf('echo "[$UNZIP][$UNZIPOPT][$ZIPINFO][$ZIPINFOOPT][$LC_ALL][$TZ]" >> %s; exec /usr/bin/unzip "$@"', escapeshellarg($log)));
    $zip = new ZipArchive(environment: $environment + ['UNZIP' => '-K', 'UNZIPOPT' => '-K', 'ZIPINFO' => '-1', 'ZIPINFOOPT' => '-1', 'LC_ALL' => 'de_DE.UTF-8', 'TZ' => 'Europe/Berlin']);
    $zip->open($this->file);
    $zip->extractTo($this->directory.'/target');

    // The release, the check pass, then the run that writes.
    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['[][][][][C][Europe/Berlin]', '[][][][][C][Europe/Berlin]', '[][][][][C][Europe/Berlin]']);
});

it('removes a variable whose name getenv() gives as a number, whose value names an option', function (): void {
    $file = archive(ZipFixture::new()->directory('d/')->file('d/a.txt', "a\n"), 'dir.zip');
    $code = sprintf('$zip = new SorgeIt\SafeUnzip\ZipArchive; $zip->open(%s); $zip->extractTo(%s); echo json_encode(scandir(%s));', var_export($file, true), var_export($this->directory.'/out', true), var_export($this->directory.'/out/d', true));

    // With `-j`, `unzip` would write `a.txt` without its directory.
    expect(php($code, variables: ['1=UNZIP=-j']))->toBe('[".","..","a.txt"]');
});

it('runs the binary whose release it checked, also after the PATH of the process changed', function (): void {
    $log = $this->directory.'/runs';
    fakeTool($this->directory, 'unzip', sprintf('touch %s; exec /usr/bin/unzip "$@"', escapeshellarg($log)));
    $path = (string) getenv('PATH');
    $zip = new ZipArchive;
    $zip->open($this->file);

    try {
        putenv('PATH='.$this->directory.'/bin:'.$path);
        $zip->extractTo($this->directory.'/out');
    } finally {
        putenv('PATH='.$path);
    }

    expect(file_exists($log))->toBeFalse()
        ->and(file_get_contents($this->directory.'/out/a.txt'))->toBe("a\n");
});

it('refuses an archive or a target that resolves to a relative path, in a working directory beyond PATH_MAX', function (): void {
    copy($this->file, $this->directory.'/rel.zip');
    // `chdir()` goes one part at a time, so the working directory can grow beyond `PATH_MAX`.
    $code = sprintf('chdir(%s); for ($i = 0; $i < intdiv(PHP_MAXPATHLEN, 200) + 1; $i++) { @mkdir(str_repeat("c", 200)); chdir(str_repeat("c", 200)); } copy(%s, "rel.zip"); foreach (["open", "extractTo"] as $method) { try { $zip = new SorgeIt\SafeUnzip\ZipArchive; $method === "open" ? $zip->open("rel.zip") : ($zip->open(%2$s) && $zip->extractTo("out")); } catch (Throwable $t) { echo get_class($t), ": ", $t->getMessage(), "\n"; } }', var_export($this->directory, true), var_export($this->directory.'/rel.zip', true));

    expect(php($code))->toBe("SorgeIt\\SafeUnzip\\Exception\\FilesystemException: rel.zip cannot be resolved to an absolute path.\nSorgeIt\\SafeUnzip\\Exception\\FilesystemException: The directory out cannot be resolved to an absolute path.\n");
});

it('refuses another release of Info-ZIP', function (string $tool, string $first, string $print): void {
    $environment = fakeTool($this->directory, $tool, sprintf('if [ "$1" = -v ] || [ $# -eq 0 ]; then %s; fi; exec /usr/bin/%s "$@"', $print, $tool));
    $zip = new ZipArchive(environment: $environment);

    expect(fn (): bool => $zip->open($this->file))->toThrow(ToolException::class, sprintf('`%s` is not the release of Info-ZIP that this package reads: it prints `%s`', $tool, $first));
})->with([
    'UnZip 6.10' => ['unzip', 'UnZip 6.10b of 25 January 2009, by Info-ZIP.', "echo 'UnZip 6.10b of 25 January 2009, by Info-ZIP.'; exit 0"],
    'ZipInfo 3.10' => ['zipinfo', 'ZipInfo 3.10 of 25 January 2009, by Greg Roelofs and the Info-ZIP group.', "echo 'ZipInfo 3.10 of 25 January 2009, by Greg Roelofs and the Info-ZIP group.'; exit 0"],
    // As BusyBox prints its usage: to the errors, with exit code 1.
    'BusyBox' => ['unzip', 'BusyBox v1.36.1 (2024-06-10 07:11:47 UTC) multi-call binary.', "printf 'BusyBox v1.36.1 (2024-06-10 07:11:47 UTC) multi-call binary.\\n\\nUsage: unzip [-lnojpq] FILE.zip\\n' >&2; exit 1"],
]);

it('checks the release of the tools once for each object', function (): void {
    $log = $this->directory.'/runs';
    $environment = fakeTool($this->directory, 'unzip', sprintf('[ "$1" = -v ] && echo v >> %s; exec /usr/bin/unzip "$@"', escapeshellarg($log)));
    $zip = new ZipArchive(environment: $environment);
    $zip->open($this->file);
    $zip->open($this->file);

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['v']);
});

it('refuses a listing beyond the bytes that the count of entries allows', function (): void {
    // 2 entries allow 65536 + 65535 + 2 * 16384 bytes of zipinfo -v; three comments of 65535
    // bytes print more.
    $comment = str_repeat('c', 65535);
    $file = archive(ZipFixture::new()->file('a.txt', "a\n", ['comment' => $comment])->file('b.txt', "b\n", ['comment' => $comment])->comment($comment));

    expect(fn (): bool => new ZipArchive()->open($file))->toThrow(RefusedException::class, 'L1 The archive has more entries or bytes than the limits allow: zipinfo lists more than 163839 bytes, the most that 2 entries and memory_limit allow.');
});

it('refuses a listing that would not fit under memory_limit', function (): void {
    $fixture = ZipFixture::new();

    for ($index = 0; $index < 2000; $index++) {
        $fixture->file(sprintf('d/%04d.txt', $index), "x\n");
    }

    $file = $fixture->write(temporaryDirectory().'/many.zip');
    $code = sprintf('try { (new SorgeIt\SafeUnzip\ZipArchive(new SorgeIt\SafeUnzip\Limits(entries: 2000)))->open(%s); echo "opened"; } catch (Throwable $t) { echo get_class($t), ": ", $t->getMessage(); }', var_export($file, true));

    // About 2.6 MB of zipinfo -v: too much for a sixth of what 12 MB leave free, not for 256 MB.
    expect(php($code, ['memory_limit=12M']))->toContain('RefusedException: L1 The archive has more entries or bytes than the limits allow: zipinfo lists more than')
        ->and(php($code, ['memory_limit=256M']))->toBe('opened');
});

it('refuses a listing of many short lines before it splits them, as they would not fit under memory_limit', function (): void {
    $fixture = ZipFixture::new();

    // An entry comment of line breaks: 1 byte of output each, about 32 bytes in the parse.
    for ($index = 0; $index < 40; $index++) {
        $fixture->file(sprintf('%02d.txt', $index), "x\n", ['comment' => str_repeat("\n", 15000)]);
    }

    $file = $fixture->write(temporaryDirectory().'/lines.zip');
    $code = sprintf('try { (new SorgeIt\SafeUnzip\ZipArchive(new SorgeIt\SafeUnzip\Limits(entries: 40)))->open(%s); echo "opened"; } catch (Throwable $t) { echo get_class($t), ": ", $t->getMessage(); }', var_export($file, true));

    expect(php($code, ['memory_limit=16M']))->toContain('RefusedException: L1 ')
        ->and(php($code, ['memory_limit=16M']))->toContain('free under memory_limit');
});

it('refuses an archive path that unzip would read as a pattern', function (string $name): void {
    $file = ZipFixture::new()->file('a.txt', "a\n")->write($this->directory.'/'.$name);

    expect(fn (): bool => (new ZipArchive)->open($file))->toThrow(UnsupportedException::class, 'which unzip reads as a pattern');
})->with(['x[ab].zip', 'x*.zip', 'x?.zip', 'x\\a.zip', "x\na.zip"]);

it('refuses a file that is no ZIP archive, and one that does not exist', function (): void {
    file_put_contents($this->directory.'/text.zip', 'no archive');

    expect(fn (): bool => (new ZipArchive)->open($this->directory.'/text.zip'))->toThrow(InvalidArchiveException::class, 'is no ZIP archive')
        ->and(fn (): bool => (new ZipArchive)->open($this->directory.'/missing.zip'))->toThrow(FilesystemException::class, 'it does not exist')
        ->and(fn (): bool => (new ZipArchive)->open($this->directory))->toThrow(FilesystemException::class, 'is no file');
});
