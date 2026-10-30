<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The ZIP64 end of central directory locator.
 *
 * @internal
 */
final readonly class Zip64Locator
{
    /**
     * @param  int  $disk  the disk of the ZIP64 end record
     * @param  int  $offset  the offset of the ZIP64 end record
     * @param  int  $disks  the count of disks
     */
    public function __construct(public int $disk, public int $offset, public int $disks) {}
}
