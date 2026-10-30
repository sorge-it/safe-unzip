<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

/**
 * Why an archive is outside the profile "standard ZIP": one case per refusal, the value is its
 * number in the README. S is the structure, N the names and types, L the limits and the target.
 */
enum Reason: string
{
    case HeaderMismatch = 'S1';
    case EntryCount = 'S2';
    case Layout = 'S3';
    case OuterBytes = 'S4';
    case EndRecord = 'S5';
    case UnicodeField = 'S6';
    case Flags = 'S7';
    case MultiDisk = 'S8';
    case TypeMismatch = 'S9';
    case Zip64Mismatch = 'S10';
    case EntryComment = 'S11';
    case Method = 'S12';
    case ExtraField = 'S13';
    case Host = 'S14';
    case ArchiveComment = 'S15';
    case Version = 'S16';
    case Traversal = 'N1';
    case Characters = 'N3';
    case EntryType = 'N5';
    case Duplicate = 'N6';
    case FileAndDirectory = 'N7';
    case PartLength = 'N8';
    case PathLength = 'N9';
    case Limit = 'L1';
    case Target = 'L2';

    public function title(): string
    {
        return match ($this) {
            self::HeaderMismatch => 'The headers of an entry disagree',
            self::EntryCount => 'The end record counts other entries than the central directory holds',
            self::Layout => 'Entries overlap, or bytes lie between them',
            self::OuterBytes => 'Bytes lie before or after the archive',
            self::EndRecord => 'The end record is ambiguous',
            self::UnicodeField => 'An entry has a Unicode path or comment field',
            self::Flags => 'An entry has a flag outside the profile, or the archive is signed',
            self::MultiDisk => 'The archive spans more than one file',
            self::TypeMismatch => 'An entry is a directory by its name and a file by its type, or the other way round',
            self::Zip64Mismatch => 'The ZIP64 end record and the end record disagree',
            self::EntryComment => 'An entry has a comment',
            self::Method => 'An entry uses a compression method other than store and deflate',
            self::ExtraField => 'An entry has an extra field outside the profile',
            self::Host => 'An entry comes from a host system other than Unix, FAT and NTFS',
            self::ArchiveComment => 'The archive comment is not one line of printable ASCII',
            self::Version => 'An entry needs a version above 4.5 to extract',
            self::Traversal => 'A name is absolute or has an empty part, `.` or `..`',
            self::Characters => 'A name has a character outside A-Z a-z 0-9 . _ - /',
            self::EntryType => 'An entry is neither a file nor a directory',
            self::Duplicate => 'Two entries have the same name, also when only the case differs',
            self::FileAndDirectory => 'A file has the name of a directory of another entry',
            self::PartLength => 'A part of a name is longer than 255 bytes',
            self::PathLength => 'A name is longer than the file system takes below the target',
            self::Limit => 'The archive has more entries or bytes than the limits allow',
            self::Target => 'The target does not take the archive: a link, a file or a directory in the way, a mount, or no room or access',
        };
    }
}
