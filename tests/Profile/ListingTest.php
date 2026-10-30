<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Archive\Listing;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\Tools\Tool;

/*
 * Outputs of `zipinfo -v` that no archive gives on the systems measured, as another release could
 * print them. Each case changes a real output in one place; the grammar refuses it.
 */

/**
 * The output of `zipinfo -v` for an archive, with each pattern replaced.
 *
 * @param  array<string, string>  $replace  a pattern of `preg_replace()` and its replacement
 * @param  list<int>  $success  the exit codes of zipinfo that count as success
 * @return array{string, string} the archive and the output
 */
function zipinfoOutput(ZipFixture $fixture, array $replace = [], array $success = [0]): array
{
    $file = archive($fixture);
    // `Process` leaves out `ZIPINFO` and `ZIPINFOOPT` of the environment, as `open()` does.
    $output = new Process([], 900)->run(Tool::Zipinfo, ['-v', $file], 1 << 20, $success)->output;

    return [$file, (string) preg_replace(array_keys($replace), array_values($replace), $output)];
}

function oneFile(): ZipFixture
{
    return ZipFixture::new()->file('a.txt', "a\n")->comment('hello');
}

it('parses the output of zipinfo of an archive with a comment', function (): void {
    [$file, $output] = zipinfoOutput(oneFile());

    $listing = Listing::parse($output, 0, '', $file);

    expect($listing->entries)->toHaveCount(1)
        ->and($listing->header->comment)->toBe('hello')
        ->and($listing->header->truncated)->toBeFalse();
});

it('refuses an output of zipinfo that the grammar does not know', function (array $replace, string $message): void {
    [$file, $output] = zipinfoOutput(oneFile(), $replace);

    expect(fn (): Listing => Listing::parse($output, 0, '', $file))->toThrow(InvalidArchiveException::class, $message);
})->with([
    'no line break at the end' => [['~\n+\z~' => ''], 'does not end with a line break'],
    'another title of the end record' => [['~^End-of-central-directory record:$~m' => 'End record:'], 'has `End record:` where `End-of-central-directory record:` belongs'],
    'another line for the file size' => [['~^  Zip archive file size:.*$~m' => '  Size: 1'], 'has `  Size: 1` where the file size belongs'],
    'another line for the comment' => [['~^The zipfile comment is .*$~m' => 'A comment:'], 'has `A comment:` where the archive comment belongs'],
    'a line without a key' => [['~^(  compression method:.*)$~m' => "$1\n  stored"], 'lists `  stored` for entry `a.txt`, a line it does not know'],
    'a key it does not know' => [['~^  apparent file type:~m' => '  apparent file kind:'], 'lists `apparent file kind` for entry `a.txt`, a key it does not know'],
    'a key twice' => [['~^(  compression method:.*)$~m' => "$1\n$1"], 'lists `compression method` twice for entry `a.txt`'],
    'a key missing' => [['~^  apparent file type:.*\n~m' => ''], 'lists no `apparent file type` for entry `a.txt`'],
    'two kinds of attributes' => [['~^(  Unix file attributes .*)$~m' => "$1\n  non-MSDOS external file attributes:  81A400 hex"], 'lists other than one kind of attributes for entry `a.txt`'],
    'an extra field line it does not know' => [['~^  There is no file comment\.$~m' => "  The central-directory extra field contains:\n  something\n\n  There is no file comment."], 'lists `  something` in an extra field, a line it does not know'],
    'a choice it does not know' => [['~(file security status: +)not encrypted~' => '$1unknown'], 'lists `unknown`, a value it does not know'],
    'a value it does not know' => [['~(32-bit CRC value \(hex\): +)[0-9a-f]{8}~' => '$1crc'], 'lists `crc`, a value it does not know'],
]);

it('refuses an output of zipinfo with a line after the end of an empty archive', function (): void {
    // For an empty archive, zipinfo warns with exit code 1.
    [$file, $output] = zipinfoOutput(ZipFixture::new(), ['~\z~' => "more\n"], [0, 1]);

    expect(fn (): Listing => Listing::parse($output, 0, '', $file))->toThrow(InvalidArchiveException::class, 'has `more` after its end');
});

it('refuses an archive comment that zipinfo shows other than it counts', function (array $replace): void {
    [$file, $output] = zipinfoOutput(oneFile(), $replace);

    try {
        Listing::parse($output, 0, '', $file);
        $this->fail('The listing took the comment.');
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe(Reason::ArchiveComment);
    }
})->with([
    'without its first rule' => [['~^=+ zipfile comment begins =+\n~m' => '']],
    'of another length' => [['~is 5 bytes long~' => 'is 6 bytes long']],
]);

it('parses the attributes of another host as other attributes', function (): void {
    [$file, $output] = zipinfoOutput(oneFile(), ['~^  Unix file attributes \(100644 octal\):~m' => '  VMS file attributes (100644 octal):']);

    expect(Listing::parse($output, 0, '', $file)->entries[0]->otherAttributes)->toBeTrue();
});
