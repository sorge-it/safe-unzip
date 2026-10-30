<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

/**
 * The values of the allow list, in the words of `zipinfo -v` and as header codes.
 *
 * @internal
 */
final class Standard
{
    /**
     * Info-ZIP counts NTFS as host 11; APPNOTE says 10, which Info-ZIP calls TOPS-20.
     */
    public const array HOSTS = ['MS-DOS, OS/2 or NT FAT' => 0, 'Unix' => 3, 'NTFS' => 11];

    public const array METHODS = ['none (stored)' => 0, 'deflated' => 8];

    /**
     * ZIP64, NTFS times, the extended timestamp, and the three fields of Unix IDs.
     */
    public const array EXTRA_FIELDS = [0x0001, 0x000A, 0x5455, 0x5855, 0x7855, 0x7875];

    public const array UNICODE_FIELDS = [0x6375, 0x7075];

    public const int UNIX = 3;
}
