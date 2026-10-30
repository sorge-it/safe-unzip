<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

/*
 * The two kinds of archive this package is for: a zipball of GitHub, as Composer downloads it,
 * and a ZIP of Chrome for Testing, as sorge-it/cdp-browser-installer downloads it.
 */

const GITHUB_FORMAT = __DIR__.'/../Fixtures/github-format.zip';

beforeEach(function (): void {
    $this->umask = umask();
});

it('unpacks the zipball of GitHub to exactly its listing', function (): void {
    // https://api.github.com/repos/sorge-it/phpunit-pest-html-assertions/zipball/v1.4.2, downloaded 2026-10-27.
    expect(hash_file('sha256', GITHUB_FORMAT))->toBe('2c718dbd4f7c035999817d852357e200b4aec3869fdeab1e213ffdc37587c27b');

    $original = new \ZipArchive;
    $original->open(GITHUB_FORMAT, \ZipArchive::RDONLY);

    $expected = [];

    for ($index = 0; $index < $original->numFiles; $index++) {
        $name = (string) $original->getNameIndex($index);
        $expected[rtrim($name, '/')] = str_ends_with($name, '/')
            ? sprintf('dir %o', 0o755 & ~$this->umask | 0o700)
            : sprintf('file %o %s', 0o644 & ~$this->umask, (string) $original->getFromIndex($index));
    }

    ksort($expected);
    $target = temporaryDirectory();
    $zip = new ZipArchive;
    $zip->open(GITHUB_FORMAT, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

    expect($zip->numFiles)->toBe(62)
        ->and($zip->comment)->toBe('8fc48fe334e5e97e9aa2c62b738760b33976cf16')
        ->and($zip->extractTo($target))->toBeTrue()
        ->and($zip->close())->toBeTrue()
        ->and(tree($target))->toBe($expected);
});

it('unpacks a ZIP with the structure of Chrome for Testing and keeps each executable mode', function (): void {
    // Host Unix with the extended timestamp and the Unix IDs of zip(1): the local UT field holds
    // two times, the central one the modification time only.
    $ux = ZipFixture::extra(0x7875, "\1\4\xE8\3\0\0\4\xE8\3\0\0");
    $extra = ['local' => ['extra' => ZipFixture::extra(0x5455, "\3\0\0\0\0\0\0\0\0").$ux], 'central' => ['extra' => ZipFixture::extra(0x5455, "\3\0\0\0\0").$ux]];
    $fixture = ZipFixture::new()
        ->directory('chrome-linux64/', $extra)
        ->file('chrome-linux64/chrome', str_repeat("\x7FELF binary\n", 500), ['mode' => 0o100755, 'method' => 8] + $extra)
        ->file('chrome-linux64/chrome_crashpad_handler', str_repeat("\x7FELF handler\n", 100), ['mode' => 0o100755, 'method' => 8] + $extra)
        ->directory('chrome-linux64/locales/', $extra)
        ->file('chrome-linux64/locales/en-US.pak', str_repeat('pak', 300), ['method' => 8] + $extra)
        ->file('chrome-linux64/resources.pak', str_repeat('resources', 300), ['method' => 8] + $extra);
    $target = temporaryDirectory();
    $zip = new ZipArchive;
    $zip->open(archive($fixture), ZipArchive::RDONLY | ZipArchive::CHECKCONS);
    $zip->extractTo($target);
    $zip->close();

    expect(fileperms($target.'/chrome-linux64/chrome') & 0o777)->toBe(0o755 & ~$this->umask)
        ->and(fileperms($target.'/chrome-linux64/chrome_crashpad_handler') & 0o777)->toBe(0o755 & ~$this->umask)
        ->and(fileperms($target.'/chrome-linux64/resources.pak') & 0o777)->toBe(0o644 & ~$this->umask)
        ->and(is_executable($target.'/chrome-linux64/chrome'))->toBeTrue()
        ->and(array_keys(tree($target)))->toBe([
            'chrome-linux64',
            'chrome-linux64/chrome',
            'chrome-linux64/chrome_crashpad_handler',
            'chrome-linux64/locales',
            'chrome-linux64/locales/en-US.pak',
            'chrome-linux64/resources.pak',
        ]);
});
