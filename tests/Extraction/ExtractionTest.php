<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\ExtractionMismatchException;
use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Exception\ToolException;
use SorgeIt\SafeUnzip\Exception\UnsupportedException;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

function shell(): ZipFixture
{
    return ZipFixture::new()
        ->directory('shell/')
        ->file('shell/run', "#!/bin/sh\necho run\n", ['mode' => 0o100755])
        ->file('shell/data.pak', str_repeat('data ', 1000), ['method' => 8])
        ->file('shell/Read.Me', 'read me', ['mode' => 0o100600]);
}

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->file = shell()->write($this->directory.'/a.zip');
    $this->umask = umask();
});

/**
 * An `unzip` that runs the real one and then a shell command, only for the extraction:
 * `unzip -q <file> -d <directory>`, with `$d` the staging directory.
 *
 * @return array<string, string>
 */
function afterUnzip(string $directory, string $command): array
{
    return fakeTool($directory.'/tools', 'unzip', '[ "$1" = -q ] || exec /usr/bin/unzip "$@"; for d; do :; done; /usr/bin/unzip "$@" && '.$command);
}

it('unpacks each entry with its Unix mode, without write access for group and others', function (): void {
    $zip = new ZipArchive;
    $zip->open($this->file);
    $zip->extractTo($this->directory.'/out');

    expect(tree($this->directory.'/out'))->toBe([
        'shell' => sprintf('dir %o', 0o755 & ~$this->umask | 0o700),
        'shell/Read.Me' => sprintf('file %o read me', 0o600 & ~$this->umask),
        'shell/data.pak' => sprintf('file %o %s', 0o644 & ~$this->umask, str_repeat('data ', 1000)),
        'shell/run' => sprintf("file %o #!/bin/sh\necho run\n", 0o755 & ~$this->umask),
    ]);
});

it('gives an entry without Unix bits 0644, a directory 0755, and makes each parent', function (): void {
    $file = archive(ZipFixture::new()->directory('d/', ['host' => 0])->file('d/a.txt', "a\n", ['host' => 0])->file('x/y/z.txt', "z\n", ['host' => 11]));
    $zip = new ZipArchive;
    $zip->open($file);
    $zip->extractTo($this->directory.'/out');

    expect(tree($this->directory.'/out'))->toBe([
        'd' => sprintf('dir %o', 0o755 & ~$this->umask | 0o700),
        'd/a.txt' => sprintf("file %o a\n", 0o644 & ~$this->umask),
        'x' => sprintf('dir %o', 0o755 & ~$this->umask | 0o700),
        'x/y' => sprintf('dir %o', 0o755 & ~$this->umask | 0o700),
        'x/y/z.txt' => sprintf("file %o z\n", 0o644 & ~$this->umask),
    ]);
});

it('keeps what is in the target, and replaces a file of the same name', function (): void {
    mkdir($this->directory.'/out/shell', 0o755, true);
    file_put_contents($this->directory.'/out/keep', 'keep');
    file_put_contents($this->directory.'/out/shell/run', 'old');
    $zip = new ZipArchive;
    $zip->open($this->file);
    $zip->extractTo($this->directory.'/out');

    expect(file_get_contents($this->directory.'/out/keep'))->toBe('keep')
        ->and(file_get_contents($this->directory.'/out/shell/run'))->toBe("#!/bin/sh\necho run\n")
        ->and(glob($this->directory.'/out/.safe-unzip-*'))->toBe([]);
});

it('makes the target of an empty archive, and writes nothing into it', function (): void {
    $zip = new ZipArchive;
    $zip->open(archive(ZipFixture::new()));

    expect($zip->extractTo($this->directory.'/out'))->toBeTrue()
        ->and(scandir($this->directory.'/out'))->toBe(['.', '..']);
});

it('writes through no link that is already in the target', function (string $link): void {
    mkdir($this->directory.'/out'.($link === 'shell' ? '' : '/shell'), 0o755, true);
    mkdir($this->directory.'/elsewhere');
    symlink($this->directory.'/elsewhere'.($link === 'shell' ? '' : '/Read.Me'), $this->directory.'/out/'.$link);
    $zip = new ZipArchive;
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(function (RefusedException $refusedException): void {
        expect($refusedException->reason)->toBe(Reason::Target);
    })
        ->and(scandir($this->directory.'/elsewhere'))->toBe(['.', '..'])
        ->and(glob($this->directory.'/out/.safe-unzip-*'))->toBe([]);
})->with(['directory' => 'shell', 'file' => 'shell/Read.Me']);

it('refuses a file in the target where a directory belongs', function (): void {
    mkdir($this->directory.'/out');
    file_put_contents($this->directory.'/out/shell', 'a file');
    $zip = new ZipArchive;
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(RefusedException::class, 'L2')
        ->and(file_get_contents($this->directory.'/out/shell'))->toBe('a file');
});

it('refuses a directory in the target where a file belongs', function (): void {
    mkdir($this->directory.'/out/shell/run', 0o755, true);
    $zip = new ZipArchive;
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(RefusedException::class, 'is a link or a directory in the target, where `shell/run` is a file.')
        ->and(is_dir($this->directory.'/out/shell/run'))->toBeTrue()
        ->and(scandir($this->directory.'/out/shell'))->toBe(['.', '..', 'run']);
});

it('refuses a name longer below the target than the file system takes, and unpacks one byte less', function (): void {
    mkdir($this->directory.'/out');
    $target = (string) realpath($this->directory.'/out');
    // The staging directory adds `/.safe-unzip-` and 12 hex digits, the name its `/`.
    $length = PHP_MAXPATHLEN - strlen($target) - 26;
    $name = static fn (int $length): string => str_repeat(str_repeat('d', 199).'/', intdiv($length - 1, 200)).str_repeat('f', ($length - 1) % 200 + 1);
    $zip = new ZipArchive;
    $zip->open(archive(ZipFixture::new()->file($name($length), "a\n"), 'long.zip'));

    expect(fn (): bool => $zip->extractTo($target))->toThrow(RefusedException::class, sprintf('N9 A name is longer than the file system takes below the target: the archive entry `%s…` would have a path of %d bytes in the staging directory, the file system takes %d.', substr($name($length), 0, 64), PHP_MAXPATHLEN, PHP_MAXPATHLEN - 1))
        ->and(scandir($target))->toBe(['.', '..']);

    $zip->open(archive(ZipFixture::new()->file($name($length - 1), "a\n"), 'shorter.zip'));
    $zip->extractTo($target);

    expect(file_get_contents($target.'/'.$name($length - 1)))->toBe("a\n");
});

it('stops when unzip writes other than the listing says, and leaves the target as it was', function (string $command, string $message): void {
    mkdir($this->directory.'/out');
    file_put_contents($this->directory.'/out/keep', 'keep');
    $zip = new ZipArchive(environment: afterUnzip($this->directory, $command));
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(ExtractionMismatchException::class, $message)
        ->and(scandir($this->directory.'/out'))->toBe(['.', '..', 'keep']);
})->with([
    'another file' => ['touch "$d/extra"', 'unzip wrote `extra`, a node that the listing does not name.'],
    'another directory' => ['mkdir "$d/more"', 'unzip wrote `more`, a node that the listing does not name.'],
    'a link' => ['ln -s /etc/passwd "$d/passwd"', 'unzip wrote `passwd`, a link that the listing does not name.'],
    'another size of the last entry' => ['printf x > "$d/shell/Read.Me"', 'unzip wrote `shell/Read.Me` with another size than the listing says.'],
    'a file left out' => ['rm "$d/shell/run"', 'unzip did not write `shell/run`.'],
    'two files left out, named in the order of the listing' => ['rm "$d/shell/data.pak" "$d/shell/run"', 'unzip did not write `shell/run`.'],
    'a link for a file' => ['rm "$d/shell/run" && ln -s /etc/passwd "$d/shell/run"', 'unzip wrote `shell/run`, a link that the listing does not name.'],
]);

it('stops when the check pass returns other than the listing says, before it makes the target', function (string $command, string $message): void {
    $zip = new ZipArchive(environment: fakeTool($this->directory.'/tools', 'unzip', 'if [ "$1" = -p ]; then '.$command.'; else exec /usr/bin/unzip "$@"; fi'));
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(ExtractionMismatchException::class, $message)
        ->and(file_exists($this->directory.'/out'))->toBeFalse();
})->with([
    'fewer bytes' => ['printf "#!/bin/sh"', 'unzip returned fewer bytes of `shell/run`'],
    'another CRC-32' => ['printf "#!/bin/sh\necho mun\n"', 'unzip returned `shell/run` with another CRC-32'],
    'more bytes' => ['/usr/bin/unzip "$@"; printf extra', 'unzip returned more bytes than the listing says'],
]);

it('moves nothing into the target from an archive that was written again after open()', function (): void {
    // The same entries with another archive comment: only the SHA-256 tells the two apart.
    $zip = new ZipArchive;
    $zip->open($this->file);
    shell()->comment('written again')->write($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(FilesystemException::class, 'changed after it was hashed: its SHA-256 differs')
        ->and(scandir($this->directory.'/out'))->toBe(['.', '..']);
});

it('stops a zip bomb at the first byte beyond its claimed size', function (): void {
    $claimed = 1024;
    $crc = ZipFixture::crc(str_repeat("\0", $claimed));
    $file = archive(ZipFixture::new()->file('bomb.bin', '', [
        'method' => 8,
        'compressed' => ZipFixture::zeros(64),
        'local' => ['size' => $claimed, 'crc' => $crc],
        'central' => ['size' => $claimed, 'crc' => $crc],
    ]));
    $zip = new ZipArchive;
    $zip->open($file);

    $start = hrtime(true);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(ExtractionMismatchException::class, 'unzip returned more bytes than the listing says')
        ->and(file_exists($this->directory.'/out'))->toBeFalse()
        // Stopped at once: unzip had no time to inflate the 64 MiB.
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(5.0);
});

it('stops when unzip fails, and leaves no staging directory', function (): void {
    $zip = new ZipArchive(environment: fakeTool($this->directory.'/tools', 'unzip', 'if [ "$1" = -q ]; then echo "error: invalid zip file" >&2; exit 9; fi; exec /usr/bin/unzip "$@"'));
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(ToolException::class, '`unzip` stopped with exit code 9: error: invalid zip file')
        ->and(scandir($this->directory.'/out'))->toBe(['.', '..']);
});

it('refuses a deflate stream that does not inflate, before it makes the target', function (): void {
    $zip = new ZipArchive;
    $zip->open(archive(ZipFixture::new()->file('a.txt', str_repeat("a\n", 100), ['method' => 8, 'compressed' => str_repeat("\xff", 40)])));

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(InvalidArchiveException::class, 'unzip cannot decompress the archive, exit code 2: error:  invalid compressed data to inflate a.txt Nothing was unpacked.')
        ->and($this->directory.'/out')->not->toBeDirectory();
});

it('refuses a target with a scheme before a tool runs', function (string $scheme): void {
    $log = $this->directory.'/runs';
    $zip = new ZipArchive(environment: fakeTool($this->directory.'/tools', 'unzip', sprintf('[ "$1" = -p ] && touch %s; exec /usr/bin/unzip "$@"', escapeshellarg($log))));
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($scheme.$this->directory.'/new/deep'))->toThrow(UnsupportedException::class, 'ZipArchive::extractTo() takes a path of the file system, not '.$scheme)
        ->and(file_exists($this->directory.'/new'))->toBeFalse()
        ->and(file_exists($log))->toBeFalse();
})->with(['file://', 'phar://', 'data:']);

it('refuses an empty target before a tool runs', function (): void {
    $zip = new ZipArchive;
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo(''))->toThrow(ValueError::class, 'ZipArchive::extractTo(): Argument #1 ($pathto) must not be empty');
});

it('refuses a target too long for the directories of the package, before N9 and before it writes', function (): void {
    // Below `PATH_MAX` with the staging directory, at it with the probe directory in it.
    $length = PHP_MAXPATHLEN - 40 - strlen((string) realpath($this->directory));
    $target = realpath($this->directory).str_repeat('/'.str_repeat('t', 199), intdiv($length, 200)).'/'.str_repeat('u', $length % 200);
    mkdir($target, 0o755, true);
    $zip = new ZipArchive;
    $zip->open($this->file);

    expect(fn (): bool => $zip->extractTo($target))->toThrow(RefusedException::class, sprintf('L2 The target does not take the archive: a link, a file or a directory in the way, a mount, or no room or access: the target %s is too long', $target))
        ->and(scandir($target))->toBe(['.', '..']);
});

it('opens a directory that unzip locks for its owner, and removes the staging directory', function (): void {
    $file = archive(ZipFixture::new()->directory('locked/', ['mode' => 0o040500])->file('locked/a.txt', "a\n"));
    $zip = new ZipArchive;
    $zip->open($file);
    $zip->extractTo($this->directory.'/out');

    expect(tree($this->directory.'/out'))->toBe([
        'locked' => sprintf('dir %o', 0o500 & ~$this->umask | 0o700),
        'locked/a.txt' => sprintf("file %o a\n", 0o644 & ~$this->umask),
    ])->and(glob($this->directory.'/out/.safe-unzip-*'))->toBe([]);
});

it('removes the staging directory with a directory locked for its owner, where unzip fails after it wrote', function (): void {
    // unzip fails after it gave `locked` the mode 0000, so nothing opened it before the removal.
    // Root reads each directory, so there this test checks nothing.
    $file = archive(ZipFixture::new()->directory('locked/', ['mode' => 0o040000])->file('locked/a.txt', "a\n"));
    $zip = new ZipArchive(environment: afterUnzip($this->directory, 'exit 1'));
    $zip->open($file);

    expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(ToolException::class, '`unzip` stopped with exit code 1')
        ->and(glob($this->directory.'/out/.safe-unzip-*'))->toBe([]);
});

it('refuses a directory of the target into which nothing can be moved, before it moves anything', function (): void {
    mkdir($this->directory.'/out/shell', 0o755, true);
    chmod($this->directory.'/out/shell', 0o555);

    // Root moves into each directory, so the probe cannot fail there.
    if (is_writable($this->directory.'/out/shell')) {
        chmod($this->directory.'/out/shell', 0o755);
        $this->markTestSkipped('This process writes into a directory of mode 0555.');
    }

    $zip = new ZipArchive;
    $zip->open($this->file);

    try {
        expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(RefusedException::class, 'nothing can be moved into '.$this->directory.'/out/shell')
            ->and(scandir($this->directory.'/out/shell'))->toBe(['.', '..'])
            ->and(glob($this->directory.'/out/.safe-unzip-*'))->toBe([]);
    } finally {
        chmod($this->directory.'/out/shell', 0o755);
    }
});
