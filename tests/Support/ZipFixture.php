<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tests\Support;

/**
 * Builds a ZIP archive byte by byte, each field on its own, so that a test breaks one rule.
 *
 * @phpstan-type Options array{method?: int, compressed?: string, host?: int, mode?: int, external?: int, flags?: int, descriptor?: bool, unsignedDescriptor?: bool, zip64?: bool, extra?: string, comment?: string, gap?: string, local?: array<string, int|string>, central?: array<string, int|string>, descriptorFields?: array<string, int>}
 */
final class ZipFixture
{
    /** @var list<array{string, string, Options}> */
    private array $entries = [];

    private string $comment = '';

    private string $prefix = '';

    private bool $countPrefix = false;

    private string $suffix = '';

    private bool $zip64 = false;

    /** @var array<string, int> */
    private array $end = [];

    /** @var array<string, int> */
    private array $zip64End = [];

    /** @var array<string, int>|null */
    private ?array $locator = null;

    private string $afterCentral = '';

    public static function new(): self
    {
        return new self;
    }

    /**
     * The bytes of a stored local header with its data, to put inside the data of another entry.
     */
    public static function localHeader(string $name, string $data): string
    {
        return pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, self::date(), self::crc($data), strlen($data), strlen($data), strlen($name), 0).$name.$data;
    }

    /**
     * An extra field of one subfield.
     */
    public static function extra(int $id, string $data): string
    {
        return pack('vv', $id, strlen($data)).$data;
    }

    public static function crc(string $data): int
    {
        return (int) hexdec(hash('crc32b', $data));
    }

    /**
     * A raw deflate stream of `$mebibytes` MiB of zero bytes, built a MiB at a time.
     */
    public static function zeros(int $mebibytes): string
    {
        $context = deflate_init(ZLIB_ENCODING_RAW);
        $chunk = str_repeat("\0", 1024 * 1024);
        $stream = '';

        for ($i = 0; $i < $mebibytes; $i++) {
            $stream .= (string) deflate_add($context, $chunk, ZLIB_NO_FLUSH);
        }

        return $stream.deflate_add($context, '', ZLIB_FINISH);
    }

    /**
     * @param  Options  $options  `local`, `central` and `descriptorFields` override single fields of a header; `local` also takes `extraLength`
     */
    public function file(string $name, string $data = '', array $options = []): self
    {
        $this->entries[] = [$name, $data, $options];

        return $this;
    }

    /**
     * @param  Options  $options
     */
    public function directory(string $name, array $options = []): self
    {
        $this->entries[] = [$name, '', $options + ['mode' => 0o040755]];

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Bytes before the archive; with `$counted`, the offsets count them, as in a self-extracting archive.
     */
    public function prefix(string $bytes, bool $counted): self
    {
        $this->prefix = $bytes;
        $this->countPrefix = $counted;

        return $this;
    }

    public function suffix(string $bytes): self
    {
        $this->suffix = $bytes;

        return $this;
    }

    /**
     * Bytes between the central directory and the end record, such as a digital signature record.
     */
    public function afterCentral(string $bytes): self
    {
        $this->afterCentral = $bytes;

        return $this;
    }

    /**
     * A ZIP64 end record with its locator; the end record then holds the maxima.
     *
     * @param  array<string, int>  $fields  overrides of the ZIP64 end record: `size`, `entries`, `diskEntries`, `centralSize`, `centralOffset`, `disk`, `centralDisk`; and `extensible`, the bytes of extensible data, by default as many as `size` names beyond 44
     */
    public function zip64(array $fields = []): self
    {
        $this->zip64 = true;
        $this->zip64End = $fields;

        return $this;
    }

    /**
     * Without a ZIP64 end record, the locator stands alone before the end record.
     *
     * @param  array<string, int>  $fields  overrides of the ZIP64 locator: `disk`, `offset`, `disks`
     */
    public function locator(array $fields): self
    {
        $this->locator = $fields;

        return $this;
    }

    /**
     * @param  array<string, int>  $fields  overrides of the end record: `disk`, `centralDisk`, `diskEntries`, `entries`, `centralSize`, `centralOffset`, `commentLength`
     */
    public function end(array $fields): self
    {
        $this->end = $fields;

        return $this;
    }

    public function write(string $path): string
    {
        file_put_contents($path, $this->build());

        return $path;
    }

    public function build(): string
    {
        $bytes = $this->prefix;
        // Where offset 0 of the archive is in the file.
        $base = $this->countPrefix ? 0 : strlen($this->prefix);
        $central = '';

        foreach ($this->entries as [$name, $data, $options]) {
            $offset = strlen($bytes) - $base;
            [$local, $header] = $this->entry($name, $data, $options, $offset);
            $bytes .= $local.($options['gap'] ?? '');
            $central .= $header;
        }

        $centralOffset = strlen($bytes) - $base;
        $bytes .= $central.$this->afterCentral;
        $count = count($this->entries);
        $end = [
            'disk' => 0,
            'centralDisk' => 0,
            'diskEntries' => $count,
            'entries' => $count,
            'centralSize' => strlen($central),
            'centralOffset' => $centralOffset,
        ];

        if ($this->zip64) {
            $record = $this->zip64End + $end + ['size' => 44];
            $recordOffset = strlen($bytes) - $base;
            $bytes .= pack('VPvvVVPPPP', 0x06064B50, $record['size'], 0x032D, 45, $record['disk'], $record['centralDisk'], $record['diskEntries'], $record['entries'], $record['centralSize'], $record['centralOffset']);
            // Beyond 44 bytes, the record has extensible data.
            $bytes .= str_repeat("\0", $record['extensible'] ?? max(0, $record['size'] - 44));
            $locator = ($this->locator ?? []) + ['disk' => 0, 'offset' => $recordOffset, 'disks' => 1];
            $bytes .= pack('VVPV', 0x07064B50, $locator['disk'], $locator['offset'], $locator['disks']);
            $end = ['diskEntries' => 0xFFFF, 'entries' => 0xFFFF, 'centralSize' => 0xFFFFFFFF, 'centralOffset' => 0xFFFFFFFF] + $end;
        } elseif ($this->locator !== null) {
            $locator = $this->locator + ['disk' => 0, 'offset' => 0, 'disks' => 1];
            $bytes .= pack('VVPV', 0x07064B50, $locator['disk'], $locator['offset'], $locator['disks']);
        }

        $end = $this->end + $end + ['commentLength' => strlen($this->comment)];
        $bytes .= pack('VvvvvVVv', 0x06054B50, $end['disk'], $end['centralDisk'], $end['diskEntries'], $end['entries'], $end['centralSize'], $end['centralOffset'], $end['commentLength']);

        return $bytes.$this->comment.$this->suffix;
    }

    /**
     * 2026-01-01 as a DOS date; the time is 00:00.
     */
    private static function date(): int
    {
        return ((2026 - 1980) << 9) | (1 << 5) | 1;
    }

    /**
     * @param  Options  $options
     * @return array{string, string} the local header with data and data descriptor, and the central header
     */
    private function entry(string $name, string $data, array $options, int $offset): array
    {
        $method = $options['method'] ?? 0;
        $compressed = $options['compressed'] ?? ($method === 8 ? (string) gzdeflate($data) : $data);
        $descriptor = $options['descriptor'] ?? false;
        $zip64 = $options['zip64'] ?? false;
        $flags = ($options['flags'] ?? 0) | ($descriptor ? 0x8 : 0);
        $host = $options['host'] ?? 3;
        $mode = $options['mode'] ?? 0o100644;
        $external = $options['external'] ?? ($host === 3 ? $mode << 16 : (str_ends_with($name, '/') ? 0x10 : 0));
        $extra = $options['extra'] ?? '';
        $wide = $zip64 ? self::extra(0x0001, pack('PP', strlen($data), strlen($compressed))) : '';
        $crc = self::crc($data);
        $needed = $zip64 ? 45 : 20;

        $local = ($options['local'] ?? []) + [
            'needed' => $needed,
            'flags' => $flags,
            'method' => $method,
            'time' => 0,
            'date' => self::date(),
            'crc' => $descriptor ? 0 : $crc,
            'compressed' => $descriptor ? 0 : ($zip64 ? 0xFFFFFFFF : strlen($compressed)),
            'size' => $descriptor ? 0 : ($zip64 ? 0xFFFFFFFF : strlen($data)),
            'name' => $name,
            'extra' => $extra.$wide,
        ];

        $central = ($options['central'] ?? []) + [
            'madeBy' => ($host << 8) | ($zip64 ? 45 : 30),
            'needed' => $needed,
            'flags' => $flags,
            'method' => $method,
            'crc' => $crc,
            'compressed' => $zip64 ? 0xFFFFFFFF : strlen($compressed),
            'size' => $zip64 ? 0xFFFFFFFF : strlen($data),
            'disk' => 0,
            'external' => $external,
            'offset' => $offset,
            'name' => $name,
            'extra' => $extra.$wide,
            'comment' => $options['comment'] ?? '',
        ];

        $localBytes = pack(
            'VvvvvvVVVvv',
            0x04034B50,
            (int) $local['needed'],
            (int) $local['flags'],
            (int) $local['method'],
            (int) $local['time'],
            (int) $local['date'],
            (int) $local['crc'],
            (int) $local['compressed'],
            (int) $local['size'],
            strlen((string) $local['name']),
            (int) ($local['extraLength'] ?? strlen((string) $local['extra'])),
        ).$local['name'].$local['extra'].$compressed;

        if ($descriptor) {
            $fields = ($options['descriptorFields'] ?? []) + ['crc' => $crc, 'compressed' => strlen($compressed), 'size' => strlen($data)];
            $localBytes .= ($options['unsignedDescriptor'] ?? false) ? '' : pack('V', 0x08074B50);
            $localBytes .= $zip64 ? pack('VPP', $fields['crc'], $fields['compressed'], $fields['size']) : pack('VVV', $fields['crc'], $fields['compressed'], $fields['size']);
        }

        $centralBytes = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014B50,
            (int) $central['madeBy'],
            (int) $central['needed'],
            (int) $central['flags'],
            (int) $central['method'],
            0,
            self::date(),
            (int) $central['crc'],
            (int) $central['compressed'],
            (int) $central['size'],
            strlen((string) $central['name']),
            strlen((string) $central['extra']),
            strlen((string) $central['comment']),
            (int) $central['disk'],
            0,
            (int) $central['external'],
            (int) $central['offset'],
        ).$central['name'].$central['extra'].$central['comment'];

        return [$localBytes, $centralBytes];
    }
}
