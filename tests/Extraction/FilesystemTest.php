<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

/*
 * Steps of the file system that fail, or that have nothing to do.
 */

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
});

it('names an archive that it cannot read', function (): void {
    $file = archive(ZipFixture::new()->file('a.txt', "a\n"));
    chmod($file, 0o000);

    // Root reads each file, so the read cannot fail there.
    if (is_readable($file)) {
        $this->markTestSkipped('This process reads a file of mode 0000.');
    }

    expect(fn (): bool => (new ZipArchive)->open($file))->toThrow(FilesystemException::class, $file.' could not be read: hash_file(');
});

it('names a target that cannot be made, such as below a file', function (): void {
    $file = archive(ZipFixture::new()->file('a.txt', "a\n"));
    $zip = new ZipArchive;
    $zip->open($file);

    expect(fn (): bool => $zip->extractTo($file.'/out'))->toThrow(FilesystemException::class, sprintf('The directory %s/out could not be created', $file));
});

it('refuses a target in which the staging directory cannot be made (L2)', function (): void {
    mkdir($this->directory.'/out', 0o555);

    // Root writes into each directory, so the staging directory cannot fail there.
    if (is_writable($this->directory.'/out')) {
        chmod($this->directory.'/out', 0o755);
        $this->markTestSkipped('This process writes into a directory of mode 0555.');
    }

    $zip = new ZipArchive;
    $zip->open(archive(ZipFixture::new()->file('a.txt', "a\n")));

    try {
        expect(fn (): bool => $zip->extractTo($this->directory.'/out'))->toThrow(RefusedException::class, sprintf('nothing can be written into %s/out: mkdir(): Permission denied.', realpath($this->directory)))
            ->and(scandir($this->directory.'/out'))->toBe(['.', '..']);
    } finally {
        chmod($this->directory.'/out', 0o755);
    }
});

it('unpacks an archive of directories that are all in the target already', function (): void {
    $file = archive(ZipFixture::new()->directory('d/'));
    mkdir($this->directory.'/out/d', 0o755, true);
    $zip = new ZipArchive;
    $zip->open($file);

    expect($zip->extractTo($this->directory.'/out'))->toBeTrue()
        ->and(tree($this->directory.'/out'))->toBe(['d' => sprintf('dir %o', 0o755 & ~umask())]);
});
