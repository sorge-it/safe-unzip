<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * One entry as `zipinfo -v` lists it: the central header, read by Info-ZIP.
 *
 * @internal
 */
final readonly class Entry
{
    /**
     * @param  int  $index  the position in the central directory, from 0
     * @param  string  $host  the host system, in the words of Info-ZIP, such as `Unix`
     * @param  string  $method  the compression method, in the words of Info-ZIP, such as `deflated`
     * @param  int|null  $unixMode  the Unix type and permission bits, where `zipinfo` shows them
     * @param  int|null  $externalHigh  the upper 24 bits of the external attributes, where `zipinfo` shows them as hex, such as for host FAT
     * @param  bool  $otherAttributes  whether `zipinfo` shows attributes of another system, such as of VMS
     * @param  list<int>  $extraFields  the IDs of the subfields of the central extra field
     */
    public function __construct(
        public int $index,
        public string $name,
        public int $offset,
        public string $host,
        public string $needed,
        public string $method,
        public bool $encrypted,
        public bool $descriptor,
        public int $crc,
        public int $compressedSize,
        public int $size,
        public int $nameLength,
        public int $extraLength,
        public int $disk,
        public ?int $unixMode,
        public ?int $externalHigh,
        public int $dosAttributes,
        public bool $otherAttributes,
        public array $extraFields,
    ) {}

    public function isDirectory(): bool
    {
        return str_ends_with($this->name, '/');
    }

    /**
     * The permission bits the entry gets: its Unix bits without write access for group and
     * others, else 0644 for a file and 0755 for a directory. A directory always opens for its owner.
     */
    public function mode(): int
    {
        $permissions = ($this->unixMode ?? 0) & 0o777 & ~0o022;

        if ($permissions === 0) {
            $permissions = $this->isDirectory() ? 0o755 : 0o644;
        }

        return $this->isDirectory() ? $permissions | 0o700 : $permissions;
    }
}
