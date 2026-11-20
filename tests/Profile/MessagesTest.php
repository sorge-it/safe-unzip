<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Tests\Support\Fixtures;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\ZipArchive;

/*
 * The message of a refusal names its cause with the numbers of the archive. One fixture per rule,
 * and one per Unix type of N5: a rule that refuses for the right reason, but names the wrong field,
 * offset, name or type, fails here.
 */

const MESSAGES = [
    'S1 another name in the local header' => 'S1 (The headers of an entry disagree): in the archive entry `a.txt`, the local header and the central header differ in the name. Nothing was extracted.',
    'S2 the end record counts one entry more' => 'S2 (The end record and the central directory count different numbers of entries): the end record counts 2 entries, `zipinfo` lists 1. Nothing was extracted.',
    'S3 an entry inside the data of another' => 'S3 (Entries overlap, or bytes lie between them): `b.txt` starts at offset 35, inside `a.txt`, which ends at 75. Nothing was extracted.',
    'S4 bytes before the archive, offsets counted' => 'S4 (Bytes lie before or after the archive): the archive starts at offset 10, after 10 bytes of something else. Nothing was extracted.',
    'S5 a comment length beyond the end of the file' => 'S5 (The end record is ambiguous): the archive comment is 10 bytes long by the end record, and the file ends before. Nothing was extracted.',
    'S6 a Unicode path field' => 'S6 (An entry has a Unicode path or comment field): the archive entry `%s` has the Unicode extra field 0x7075. Nothing was extracted.',
    'S7 encryption' => 'S7 (An entry has a flag outside the profile, or the archive is signed): the archive entry `a.txt` is encrypted. Nothing was extracted.',
    'S8 the end record on disk 1' => 'S8 (The archive spans more than one file): `zipinfo` lists the archive as one disk of a multi-part archive. Nothing was extracted.',
    'S9 a trailing slash and a file mode' => 'S9 (An entry is a directory by its name and a file by its type, or the other way round): the archive entry `d/` is a directory by its name and a file by its attributes. Nothing was extracted.',
    'S10 the ZIP64 end record counts two entries' => 'S10 (The ZIP64 end record and the end record disagree): the end record has 1 in `diskEntries`, the ZIP64 end record 2. Nothing was extracted.',
    'S11 an entry comment' => 'S11 (An entry has a comment): the archive entry `a.txt` has a comment. Nothing was extracted.',
    'S12 bzip2' => 'S12 (An entry uses a compression method other than store and deflate): the archive entry `a.txt` uses the compression method `bzipped`. Nothing was extracted.',
    'S13 an extra field of Java' => 'S13 (An entry has an extra field outside the profile): the central header of `a.txt` has the extra field 0xcafe. Nothing was extracted.',
    'S14 host 10, NTFS by APPNOTE and TOPS-20 by Info-ZIP' => 'S14 (An entry comes from a host system other than Unix, FAT and NTFS): the archive entry `a.txt` comes from the host system `TOPS-20`. Nothing was extracted.',
    'S15 a comment of two lines' => 'S15 (The archive comment is not one line of printable ASCII): the archive comment of 17 bytes is not one line of printable ASCII. Nothing was extracted.',
    'S16 an entry that needs version 4.6' => 'S16 (An entry needs a version above 4.5 to extract): the archive entry `a.txt` needs version 4.6. Nothing was extracted.',
    'N1 a part ..' => 'N1 (A name is absolute or has an empty part, `.` or `..`): the archive entry `../escaped.txt` is absolute or has an empty part, `.` or `..`. Nothing was extracted.',
    'N3 a space' => 'N3 (A name has a character outside A-Z a-z 0-9 . _ - /): the archive entry `a b.txt` has the byte 0x20. Nothing was extracted.',
    'N5 a symbolic link' => 'N5 (An entry is neither a file nor a directory): the archive entry `link` is a symbolic link. Nothing was extracted.',
    'N5 a character device' => 'N5 (An entry is neither a file nor a directory): the archive entry `tty` is a character device. Nothing was extracted.',
    'N5 a block device' => 'N5 (An entry is neither a file nor a directory): the archive entry `disk` is a block device. Nothing was extracted.',
    'N5 a socket' => 'N5 (An entry is neither a file nor a directory): the archive entry `socket` is a socket. Nothing was extracted.',
    'N6 a name twice' => 'N6 (Two entries have the same name, even when only the case differs): the archive has both `a.txt` and `a.txt`. Nothing was extracted.',
    'N7 a file and a directory below it' => 'N7 (A file has the name of a directory of another entry): the archive entry `a` is a file and the directory of `a/b.txt`. Nothing was extracted.',
    'N8 a part of 256 bytes' => 'N8 (A part of a name is longer than 255 bytes): the archive entry `baaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa…` has a part of 256 bytes. Nothing was extracted.',
    'L1 more bytes than the limit' => 'L1 (The archive is larger than the limits or the free memory allow): the archive extracts to 4097 bytes, the limit is 4096. Nothing was extracted.',
];

it('names the cause of a refusal, with the numbers of the archive', function (string $name, string $expected): void {
    $fixture = Fixtures::refused()[$name][1];

    if ($name === 'S6 a Unicode path field') {
        // zipinfo of Debian and Alpine lists the name of the Unicode path field, the one of macOS
        // the name field: the message names the entry as zipinfo lists it.
        $listed = Archive::read(archive($fixture), new Process([], 900), new Limits)->listing->entries[0]->name;
        $expected = sprintf($expected, $listed);

        expect($listed)->toBeIn(['a.txt', 'evil.txt']);
    }

    try {
        new ZipArchive(new Limits(...Fixtures::LIMITS))->open(archive($fixture));
        $this->fail($name.' was accepted.');
    } catch (RefusedException $refusedException) {
        expect($refusedException->getMessage())->toBe($expected);
    }
})->with(array_combine(array_keys(MESSAGES), array_map(null, array_keys(MESSAGES), MESSAGES)));
