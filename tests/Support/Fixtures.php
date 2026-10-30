<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tests\Support;

use SorgeIt\SafeUnzip\Profile\Reason;

/**
 * The catalogue of built archives: the ones the profile accepts, and for each refusal the ones that
 * break it. Suite `Profile` runs each.
 */
final class Fixtures
{
    /**
     * The entries and bytes of the `Limits` each fixture is checked with.
     */
    public const array LIMITS = [3, 4096];

    /**
     * @return array<string, ZipFixture>
     */
    public static function accepted(): array
    {
        $deflated = str_repeat("deflate me\n", 200);

        return [
            'unix file and directory' => ZipFixture::new()->directory('d/')->file('d/a.txt', "a\n")->file('run.sh', "#!/bin/sh\n", ['mode' => 0o100755]),
            'host FAT, as git archive writes it' => ZipFixture::new()->directory('d/', ['host' => 0])->file('d/a.txt', "a\n", ['host' => 0]),
            'host NTFS' => ZipFixture::new()->file('a.txt', "a\n", ['host' => 11]),
            'a directory without the MS-DOS bit, as Java writes it' => ZipFixture::new()->directory('d/', ['host' => 0, 'external' => 0]),
            'a directory with an empty deflate stream' => ZipFixture::new()->directory('d/', ['method' => 8]),
            'deflate' => ZipFixture::new()->file('a.txt', $deflated, ['method' => 8]),
            'data descriptor with signature' => ZipFixture::new()->file('a.txt', $deflated, ['method' => 8, 'descriptor' => true]),
            'data descriptor without signature' => ZipFixture::new()->file('a.txt', $deflated, ['method' => 8, 'descriptor' => true, 'unsignedDescriptor' => true]),
            'UTF-8 flag on an ASCII name' => ZipFixture::new()->file('a.txt', "a\n", ['flags' => 0x800]),
            'maximum deflate option' => ZipFixture::new()->file('a.txt', $deflated, ['method' => 8, 'flags' => 0x2]),
            'ZIP64' => ZipFixture::new()->file('a.txt', "a\n", ['zip64' => true])->zip64(),
            'extended timestamp and Unix IDs' => ZipFixture::new()->file('a.txt', "a\n", ['extra' => ZipFixture::extra(0x5455, "\1\0\0\0\0").ZipFixture::extra(0x7875, "\1\4\xE8\3\0\0\4\xE8\3\0\0")]),
            'archive comment of a git commit' => ZipFixture::new()->file('a.txt', "a\n")->comment('8fc48fe334e5e97e9aa2c62b738760b33976cf16'),
            'archive comment of the longest length, without entries' => ZipFixture::new()->comment(str_repeat('a', 65535)),
            'empty archive' => ZipFixture::new(),
            'names of each allowed character' => ZipFixture::new()->file('Az09._-/x.y_z-1', "a\n"),
            'two directories whose names start alike in another case' => ZipFixture::new()->file('M/x.txt', "x\n")->file('mo/y.txt', "y\n"),
        ];
    }

    /**
     * @return array<string, array{Reason, ZipFixture}>
     */
    public static function refused(): array
    {
        $a = static fn (array $options = []): ZipFixture => ZipFixture::new()->file('a.txt', "aaaa\n", $options);
        $nested = ZipFixture::localHeader('b.txt', "bbbb\n");
        $unicodePath = ZipFixture::extra(0x7075, "\1".pack('V', ZipFixture::crc('a.txt')).'evil.txt');

        return [
            'S1 another name in the local header' => [Reason::HeaderMismatch, $a(['local' => ['name' => 'c.txt']])],
            'S1 another method in the central header' => [Reason::HeaderMismatch, $a(['central' => ['method' => 8]])],
            'S1 another CRC-32 in the local header' => [Reason::HeaderMismatch, $a(['local' => ['crc' => 1]])],
            'S1 another size in the local header' => [Reason::HeaderMismatch, $a(['local' => ['size' => 4]])],
            'S1 the UTF-8 flag in the local header only' => [Reason::HeaderMismatch, $a(['local' => ['flags' => 0x800]])],
            'S1 another version needed in the local header' => [Reason::HeaderMismatch, $a(['local' => ['needed' => 10]])],
            'S1 another time in the local header' => [Reason::HeaderMismatch, $a(['local' => ['time' => 1]])],
            'S1 another date in the local header' => [Reason::HeaderMismatch, $a(['local' => ['date' => 0x5C22]])],
            'S1 another size in the data descriptor' => [Reason::HeaderMismatch, $a(['method' => 8, 'descriptor' => true, 'descriptorFields' => ['size' => 4]])],
            'S1 a stored entry with two sizes' => [Reason::HeaderMismatch, $a(['local' => ['size' => 255], 'central' => ['size' => 255]])],
            'S2 the end record counts one entry more' => [Reason::EntryCount, $a()->end(['entries' => 2, 'diskEntries' => 2])],
            'S2 the end record counts one entry less' => [Reason::EntryCount, $a()->file('b.txt', "bbbb\n")->end(['entries' => 1, 'diskEntries' => 1])],
            'S3 an entry inside the data of another' => [Reason::Layout, ZipFixture::new()->file('a.txt', $nested)->file('b.txt', "bbbb\n", ['central' => ['offset' => 35]])],
            'S3 two entries at one offset' => [Reason::Layout, $a()->file('a2.txt', "aaaa\n", ['local' => ['name' => 'a.txt'], 'central' => ['offset' => 0]])],
            'S3 bytes between two entries' => [Reason::Layout, $a(['gap' => 'GAPGAP'])->file('b.txt', "bbbb\n")],
            'S3 bytes between the last entry and the central directory' => [Reason::Layout, $a(['gap' => 'GAPGAP'])],
            'S3 bytes between the central directory and the end record' => [Reason::Layout, $a()->afterCentral('GAPGAP')],
            // The central directory at 40 is placed at 45: the end record comes before its place.
            'S3 no central header where the end record places the central directory' => [Reason::Layout, $a()->end(['centralOffset' => 45])],
            'S3 no local header at the offset of an entry' => [Reason::Layout, $a()->file('b.txt', "bbbb\n", ['central' => ['offset' => 10]])],
            'S3 a local header whose extra field runs beyond the end of the file' => [Reason::Layout, $a(['local' => ['extraLength' => 0xFFFF]])],
            'S3 a data descriptor beyond the end of the file' => [Reason::Layout, $a(['method' => 8, 'descriptor' => true, 'central' => ['compressed' => 100000]])],
            'S3 a central directory that ends after its central headers' => [Reason::Layout, $a()->afterCentral('GAPGAP')->end(['centralSize' => 57])],
            'S4 bytes before the archive, offsets counted' => [Reason::OuterBytes, $a()->prefix('MZ-PROGRAM', true)],
            'S4 bytes before the archive, offsets not counted' => [Reason::OuterBytes, $a()->prefix('MZ-PROGRAM', false)],
            // PHP reads the central directory 10 bytes early, at `PK\1\2` in the data; the extra field
            // of that header has the length of the compressed size of the real one, beyond the file.
            'S4 bytes before the archive, and a central header there that runs beyond the end of the file' => [Reason::OuterBytes, ZipFixture::new()->file('a.txt', str_repeat('x', 59990)."PK\1\2xxxxxx")->prefix('MZ-PROGRAM', false)],
            'S4 bytes after the end record' => [Reason::OuterBytes, $a()->suffix('TRAILING')],
            'S5 a comment length beyond the end of the file' => [Reason::EndRecord, $a()->end(['commentLength' => 10])],
            // The locator and the end record then lie where PHP reads none.
            'S5 a ZIP64 end record whose size names bytes it does not have' => [Reason::EndRecord, $a()->zip64(['size' => 100, 'extensible' => 0])],
            // PHP reads a locator after the end record, and no end record after that locator.
            'S5 a ZIP64 end record whose size names a locator after the end record' => [Reason::EndRecord, $a()->zip64(['size' => 86, 'extensible' => 0])->suffix("PK\6\7".str_repeat("\0", 16))],
            'S6 a Unicode path field' => [Reason::UnicodeField, $a(['extra' => $unicodePath])],
            'S6 a Unicode path field in the local header only' => [Reason::UnicodeField, $a(['local' => ['extra' => $unicodePath]])],
            'S6 a Unicode comment field' => [Reason::UnicodeField, $a(['extra' => ZipFixture::extra(0x6375, "\1".pack('V', 0).'hello')])],
            'S7 encryption' => [Reason::Flags, $a(['flags' => 0x1])],
            'S7 encryption in the local header only' => [Reason::Flags, $a(['local' => ['flags' => 0x1]])],
            'S7 strong encryption' => [Reason::Flags, $a(['flags' => 0x41])],
            'S7 patch data' => [Reason::Flags, $a(['flags' => 0x20])],
            'S7 a masked local header' => [Reason::Flags, $a(['flags' => 0x2000])],
            'S7 a stored entry with a data descriptor' => [Reason::Flags, $a(['descriptor' => true])],
            'S7 a digital signature record' => [Reason::Flags, $a()->afterCentral(pack('Vv', 0x05054B50, 0))],
            'S8 the end record on disk 1' => [Reason::MultiDisk, $a()->end(['disk' => 1, 'centralDisk' => 1])],
            'S8 an entry that starts on another disk' => [Reason::MultiDisk, $a(['central' => ['disk' => 1]])],
            // zipinfo stops with exit code 11.
            'S8 the end record on another disk than the central directory' => [Reason::MultiDisk, $a()->end(['disk' => 241])],
            'S8 the ZIP64 end record counts the entries of the disk in another way' => [Reason::MultiDisk, $a()->zip64(['diskEntries' => 0])],
            'S9 a trailing slash and a file mode' => [Reason::TypeMismatch, ZipFixture::new()->file('d/', '', ['mode' => 0o100644])],
            'S9 a directory mode without a trailing slash' => [Reason::TypeMismatch, ZipFixture::new()->file('d', '', ['mode' => 0o040755])],
            'S9 the MS-DOS directory bit without a trailing slash' => [Reason::TypeMismatch, ZipFixture::new()->file('d', '', ['host' => 0, 'external' => 0x10])],
            'S9 a directory with bytes' => [Reason::TypeMismatch, ZipFixture::new()->file('d/', 'data', ['mode' => 0o040755])],
            'S9 a directory with a CRC-32' => [Reason::TypeMismatch, ZipFixture::new()->directory('d/', ['local' => ['crc' => 1], 'central' => ['crc' => 1]])],
            'S9 a directory with compressed bytes' => [Reason::TypeMismatch, ZipFixture::new()->directory('d/', ['method' => 8, 'compressed' => (string) gzdeflate(str_repeat('hidden ', 20))])],
            'S10 the ZIP64 end record counts two entries' => [Reason::Zip64Mismatch, $a()->zip64(['entries' => 2, 'diskEntries' => 2])->end(['entries' => 1, 'diskEntries' => 1])],
            'S10 the end record counts two entries, the ZIP64 end record one' => [Reason::Zip64Mismatch, $a()->zip64()->end(['entries' => 2, 'diskEntries' => 2])],
            'S10 a ZIP64 end record with extensible data' => [Reason::Zip64Mismatch, $a()->zip64(['size' => 48])],
            'S10 a size at its maximum without a ZIP64 field' => [Reason::Zip64Mismatch, $a(['central' => ['size' => 0xFFFFFFFF]])],
            'S10 a maximum without a ZIP64 end record' => [Reason::Zip64Mismatch, $a()->end(['centralOffset' => 0xFFFFFFFF])],
            'S10 a ZIP64 locator that names another offset' => [Reason::Zip64Mismatch, $a()->zip64()->locator(['offset' => 1])],
            'S11 an entry comment' => [Reason::EntryComment, $a(['comment' => "hello\nCentral directory entry #9:"])],
            'S12 bzip2' => [Reason::Method, $a(['method' => 12])],
            'S12 LZMA' => [Reason::Method, $a(['method' => 14])],
            'S12 deflate64' => [Reason::Method, $a(['method' => 9])],
            'S13 an extra field of Java' => [Reason::ExtraField, $a(['extra' => ZipFixture::extra(0xCAFE, '')])],
            'S13 an extra field whose length runs over' => [Reason::ExtraField, $a(['extra' => pack('vv', 0x5455, 9)."\1\0\0\0\0"])],
            'S13 an extra field in the local header only' => [Reason::ExtraField, $a(['local' => ['extra' => ZipFixture::extra(0x9901, str_repeat("\0", 7))]])],
            'S13 an extended timestamp twice' => [Reason::ExtraField, $a(['extra' => str_repeat(ZipFixture::extra(0x5455, "\1".pack('V', 0)), 2)])],
            'S13 an extended timestamp twice in the local header only' => [Reason::ExtraField, $a(['local' => ['extra' => str_repeat(ZipFixture::extra(0x5455, "\1".pack('V', 0)), 2)]])],
            'S13 two ZIP64 fields of other sizes' => [Reason::ExtraField, $a(['extra' => ZipFixture::extra(0x0001, pack('PP', 5, 5)).ZipFixture::extra(0x0001, pack('PP', 9, 9))])],
            'S14 host 10, NTFS by APPNOTE and TOPS-20 by Info-ZIP' => [Reason::Host, $a(['host' => 10])],
            'S14 host Mac OS X' => [Reason::Host, $a(['host' => 19])],
            'S14 host FAT with Unix bits of a link' => [Reason::Host, $a(['host' => 0, 'external' => 0o120777 << 16])],
            'S15 a comment of two lines' => [Reason::ArchiveComment, $a()->comment("line one\nline two")],
            'S15 a comment with a control character' => [Reason::ArchiveComment, $a()->comment("bell\x07")],
            'S15 a comment with a byte above 0x7F' => [Reason::ArchiveComment, $a()->comment("caf\xC3\xA9")],
            'N1 a part ..' => [Reason::Traversal, ZipFixture::new()->file('../escaped.txt', "x\n")],
            'N1 an absolute name' => [Reason::Traversal, ZipFixture::new()->file('/etc/passwd', "x\n")],
            'N1 an empty part' => [Reason::Traversal, ZipFixture::new()->file('a//b.txt', "x\n")],
            'N1 a part .' => [Reason::Traversal, ZipFixture::new()->file('./a.txt', "x\n")],
            'N1 a part .. in the local header only' => [Reason::Traversal, ZipFixture::new()->file('aa/b.txt', "x\n", ['local' => ['name' => '../b.txt']])],
            // unzip reads a backslash of host FAT as `/`, so this name would leave the target.
            'N3 a backslash of host FAT' => [Reason::Characters, ZipFixture::new()->file('..\\escaped.txt', "x\n", ['host' => 0])],
            'N3 a wildcard of unzip' => [Reason::Characters, ZipFixture::new()->file('a*.txt', "x\n")],
            'N3 a space' => [Reason::Characters, ZipFixture::new()->file('a b.txt', "x\n")],
            'N3 UTF-8' => [Reason::Characters, ZipFixture::new()->file("caf\xC3\xA9.txt", "x\n", ['flags' => 0x800])],
            'N3 a control character' => [Reason::Characters, ZipFixture::new()->file("a\x1Bb.txt", "x\n")],
            'N5 a symbolic link' => [Reason::EntryType, ZipFixture::new()->file('link', '/etc/passwd', ['mode' => 0o120777])],
            'N5 a FIFO' => [Reason::EntryType, ZipFixture::new()->file('fifo', '', ['mode' => 0o010644])],
            'N5 a Unix type that has no name' => [Reason::EntryType, ZipFixture::new()->file('other', '', ['mode' => 0o170644])],
            'N5 a volume label' => [Reason::EntryType, ZipFixture::new()->file('LABEL', '', ['host' => 0, 'external' => 0x08])],
            'N6 a name twice' => [Reason::Duplicate, $a()->file('a.txt', "bbbb\n")],
            'N6 a name twice in another case' => [Reason::Duplicate, $a()->file('A.TXT', "bbbb\n")],
            'N6 a directory in two spellings' => [Reason::Duplicate, ZipFixture::new()->directory('M/')->file('m/new.txt', "x\n")],
            'N6 a directory in two spellings, of two files' => [Reason::Duplicate, ZipFixture::new()->file('M/a.txt', "a\n")->file('m/b.txt', "b\n")],
            'N7 a file and a directory below it' => [Reason::FileAndDirectory, ZipFixture::new()->file('a', "x\n")->file('a/b.txt', "x\n")],
            'N7 a file and a directory of its name' => [Reason::FileAndDirectory, ZipFixture::new()->file('x', "x\n")->directory('X/')],
            'N7 a directory and then a file of its name' => [Reason::FileAndDirectory, ZipFixture::new()->directory('X/')->file('x', "x\n")],
            'S16 an entry that needs version 4.6' => [Reason::Version, ZipFixture::new()->file('a.txt', "a\n", ['local' => ['needed' => 46], 'central' => ['needed' => 46]])],
            'N8 a part of 256 bytes' => [Reason::PartLength, ZipFixture::new()->file('b'.str_repeat('a', 255), "x\n")],
            'L1 more bytes than the limit' => [Reason::Limit, ZipFixture::new()->file('a.txt', str_repeat('a', self::LIMITS[1] + 1))],
            'L1 more entries than the limit' => [Reason::Limit, ZipFixture::new()->file('a.txt')->file('b.txt')->file('c.txt')->file('d.txt')],
        ];
    }

    /**
     * Archives whose ZIP64 end record zipinfo does not take: it counts the 65535 entries of the end
     * record then, so they reach the profile only under a limit of that many.
     *
     * @return array<string, array{Reason, ZipFixture}>
     */
    public static function refusedBeyondZip64(): array
    {
        $a = static fn (): ZipFixture => ZipFixture::new()->file('a.txt', "aaaa\n");

        return [
            'S8 the ZIP64 end record on disk 1' => [Reason::MultiDisk, $a()->zip64(['disk' => 1, 'centralDisk' => 1])],
            'S8 a ZIP64 locator that counts two disks' => [Reason::MultiDisk, $a()->zip64()->locator(['disks' => 2])],
            'S8 a ZIP64 locator that names disk 1' => [Reason::MultiDisk, $a()->zip64()->locator(['disk' => 1])],
        ];
    }
}
