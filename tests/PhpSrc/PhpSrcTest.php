<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\ExtractionMismatchException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\ZipArchive;

/**
 * An archive that ext-zip writes, a second writer beside `ZipFixture`.
 *
 * @param  Closure(\ZipArchive): mixed  $steps
 */
function written(Closure $steps): string
{
    $file = temporaryDirectory().'/a.zip';
    $native = new \ZipArchive;
    $native->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $steps($native);
    $native->close();

    return $file;
}

/**
 * Each file and directory below a directory, with the content of each file and without modes:
 * the extension does not keep the modes of an archive.
 *
 * @return array<string, string|null>
 */
function contents(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $contents = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
        assert($info instanceof SplFileInfo);
        $contents[substr((string) $path, strlen($directory) + 1)] = $info->isDir() ? null : (string) file_get_contents((string) $path);
    }

    ksort($contents);

    return $contents;
}

it('unpacks a fixture of php-src as the extension does', function (string $name, int $entries): void {
    $native = new \ZipArchive;
    $native->open(fixture($name));
    $native->extractTo($expected = temporaryDirectory());

    $zip = new ZipArchive;

    expect($zip->open(fixture($name)))->toBeTrue()
        ->and(count($zip))->toBe($entries)
        ->and(count($zip))->toBe(count($native))
        ->and($zip->extractTo($target = temporaryDirectory()))->toBeTrue()
        ->and(contents($target))->toBe(contents($expected))
        ->and(contents($target))->not->toBe([]);
})->with([
    'test.zip' => ['php-src/test.zip', 4],
    'test_procedural.zip' => ['php-src/test_procedural.zip', 4],
    'binarynull.zip, a file with NUL bytes' => ['php-src/binarynull.zip', 1],
    'bug8009.zip' => ['php-src/bug8009.zip', 1],
    'bug7658.odt, of host FAT' => ['php-src/bug7658.odt', 15],
    'bug40228.zip, nested empty directories' => ['php-src/bug40228.zip', 2],
    'bug40228-mb, a name with UTF-8' => ['php-src/bug40228私はガラスを食べられます.zip', 2],
]);

it('keeps the time of an entry', function (): void {
    $zip = new ZipArchive;
    $zip->open(fixture('php-src/test.zip'));
    $zip->extractTo($target = temporaryDirectory());

    $time = (int) mktime(0, 0, 0, 12, 25, 2019);
    $file = written(static function (\ZipArchive $native) use ($time): void {
        $native->addFromString('foo', 'entry #1');
        $native->setMtimeName('foo', $time);
    });
    $written = new ZipArchive;
    $written->open($file);
    $written->extractTo($out = temporaryDirectory());

    expect(gmdate('Ym', (int) filemtime($target.'/entry1.txt')))->toBe('200607')
        ->and(filemtime($out.'/foo'))->toBe($time);
});

it('refuses a fixture of php-src, libzip or 7-Zip', function (string $name, Reason $reason): void {
    try {
        (new ZipArchive)->open(fixture($name));
        $this->fail($name.' was accepted.');
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe($reason, $refusedException->getMessage());
    }
})->with([
    'compression_methods.zip' => ['php-src/compression_methods.zip', Reason::Method],
    'pecl12414.zip' => ['php-src/pecl12414.zip', Reason::Layout],
    'test_with_comment.zip' => ['php-src/test_with_comment.zip', Reason::EntryComment],
    'bug70752.zip, encrypted' => ['php-src/bug70752.zip', Reason::Flags],
    'bug72258.zip' => ['php-src/bug72258.zip', Reason::Characters],
    'bug81420.zip, a path out of the target' => ['php-src/bug81420.zip', Reason::Traversal],
    'wrong-file-size.zip' => ['php-src/wrong-file-size.zip', Reason::HeaderMismatch],
    'checkcons.zip' => ['php-src/checkcons.zip', Reason::OuterBytes],
    'libzip filename_duplicate.zip' => ['libzip/filename_duplicate.zip', Reason::Duplicate],
    'libzip incons-central-crc.zip' => ['libzip/incons-central-crc.zip', Reason::HeaderMismatch],
    'libzip incons-file-count-high.zip' => ['libzip/incons-file-count-high.zip', Reason::EntryCount],
    // zipinfo lists its local extra field 0x5855 in a paragraph of two lines.
    '7-Zip deflate64' => ['built/deflate64.zip', Reason::Method],
]);

it('refuses an archive that ext-zip writes outside the profile', function (Closure $steps, Reason $reason): void {
    try {
        (new ZipArchive)->open(written($steps));
        $this->fail('The archive was accepted.');
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe($reason, $refusedException->getMessage());
    }
})->with([
    'names in CP437 and UTF-8' => [static function (\ZipArchive $native): void {
        $native->addFromString(chr(0x91), 'cp437', \ZipArchive::FL_ENC_CP437);
        $native->addFromString('€', 'euro', \ZipArchive::FL_ENC_UTF_8);
    }, Reason::Characters],
    'a colon in a name' => [static fn (\ZipArchive $native): bool => $native->addFromString('dir/test:/filename.txt', 'contents'), Reason::Characters],
    'AES-256' => [static function (\ZipArchive $native): void {
        $native->addFromString('foo.txt', 'foo');
        $native->setEncryptionName('foo.txt', \ZipArchive::EM_AES_256, 'secret');
    }, Reason::Flags],
    'a Unix type that is no file' => [static function (\ZipArchive $native): void {
        $native->addFromString('foo.txt', 'foo');
        $native->setExternalAttributesName('foo.txt', \ZipArchive::OPSYS_UNIX, 123);
    }, Reason::EntryType],
    'host VFAT' => [static function (\ZipArchive $native): void {
        $native->addFromString('foo.txt', 'foo');
        $native->setExternalAttributesName('foo.txt', \ZipArchive::OPSYS_VFAT, 234);
    }, Reason::Host],
    'an entry comment' => [static function (\ZipArchive $native): void {
        $native->addFromString('entry1.txt', 'entry #1');
        $native->setCommentName('entry1.txt', 'entry1.txt');
    }, Reason::EntryComment],
    'a directory out of the target' => [static fn (\ZipArchive $native): bool => $native->addEmptyDir('../down2/'), Reason::Traversal],
    'an empty name' => [static fn (\ZipArchive $native): bool => $native->addFromString('', 'yada yada'), Reason::Traversal],
]);

it('refuses a central header with another size than the local one', function (): void {
    $file = written(static function (\ZipArchive $native): void {
        $native->addFromString('a.txt', str_repeat('A', 10));
        $native->setCompressionName('a.txt', \ZipArchive::CM_STORE);
    });
    $raw = (string) file_get_contents($file);
    file_put_contents($file, substr_replace($raw, pack('V', 20), (int) strpos($raw, "PK\x01\x02") + 24, 4));

    try {
        (new ZipArchive)->open($file);
        $this->fail('The archive was accepted.');
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe(Reason::HeaderMismatch, $refusedException->getMessage());
    }
});

it('writes nothing for an entry whose data do not match its CRC-32', function (string $file, string $name): void {
    $zip = new ZipArchive;
    $target = temporaryDirectory().'/out';

    expect($zip->open($file, ZipArchive::CHECKCONS))->toBeTrue()
        ->and(fn (): bool => $zip->extractTo($target))->toThrow(ExtractionMismatchException::class, sprintf('unzip returned `%s` with another CRC-32', $name))
        ->and($target)->not->toBeDirectory();
})->with([
    'bug49072.zip' => [fn (): string => fixture('php-src/bug49072.zip'), 'file1'],
    'libzip incons-data.zip' => [fn (): string => fixture('libzip/incons-data.zip'), 'a'],
    'a stored file with one byte changed' => [function (): string {
        $file = written(static function (\ZipArchive $native): void {
            $native->addFromString('a.txt', str_repeat('A', 64).'PAYLOAD-END');
            $native->setCompressionName('a.txt', \ZipArchive::CM_STORE);
        });
        $raw = (string) file_get_contents($file);
        $raw[(int) strpos($raw, 'AAAA') + 2] = 'Z';
        file_put_contents($file, $raw);

        return $file;
    }, 'a.txt'],
    'an empty file with a CRC-32 that is not 0' => [function (): string {
        $file = written(static function (\ZipArchive $native): void {
            $native->addFromString('empty.txt', '');
            $native->setCompressionName('empty.txt', \ZipArchive::CM_STORE);
        });
        $raw = (string) file_get_contents($file);
        $raw[(int) strpos($raw, "PK\x03\x04") + 14] = "\x01";
        $raw[(int) strpos($raw, "PK\x01\x02") + 16] = "\x01";
        file_put_contents($file, $raw);

        return $file;
    }, 'empty.txt'],
]);
