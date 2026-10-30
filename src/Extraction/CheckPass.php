<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Extraction;

use HashContext;
use SorgeIt\SafeUnzip\Archive\Entry;
use SorgeIt\SafeUnzip\Exception\ExtractionMismatchException;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Tools\Process;
use SorgeIt\SafeUnzip\Tools\Tool;

/**
 * Before anything is written: `unzip -p` decompresses every file into a pipe. The stream
 * is split at the listed sizes, each part must have its CRC-32, and the first byte beyond the sum
 * stops the tool. So the disk never holds more than the entries claim.
 *
 * @internal
 */
final class CheckPass
{
    /**
     * The exit codes of `unzip` for an error in the data of the archive, such as a deflate stream
     * that does not inflate: 2 and 3 for the format, 51 for an early end.
     */
    private const array FORMAT = [2, 3, 51];

    private int $index = 0;

    private int $size = 0;

    private HashContext $crc;

    /**
     * @param  list<Entry>  $files  no directories, in the order of the central directory
     */
    private function __construct(private readonly array $files)
    {
        $this->crc = hash_init('crc32b');
        $this->settle();
    }

    /**
     * @param  list<Entry>  $entries
     */
    public static function run(Process $process, string $file, array $entries): void
    {
        $files = array_values(array_filter($entries, static fn (Entry $entry): bool => ! $entry->isDirectory()));

        if ($files === []) {
            return;
        }

        $pass = new self($files);
        [$exit, $errors] = $process->stream(Tool::Unzip, ['-p', $file], $pass->feed(...), [0, ...self::FORMAT]);

        if ($exit !== 0) {
            throw new InvalidArchiveException(sprintf('unzip cannot decompress the archive, exit code %d: %s Nothing was unpacked.', $exit, trim($errors)));
        }

        $pass->end();
    }

    private function feed(string $chunk): void
    {
        while ($chunk !== '') {
            $entry = $this->files[$this->index] ?? throw new ExtractionMismatchException('unzip returned more bytes than the listing says. Nothing was unpacked.');
            $part = substr($chunk, 0, $entry->size - $this->size);
            $chunk = substr($chunk, strlen($part));
            $this->size += strlen($part);
            hash_update($this->crc, $part);
            $this->settle();
        }
    }

    private function end(): void
    {
        if (isset($this->files[$this->index])) {
            throw new ExtractionMismatchException(sprintf('unzip returned fewer bytes of `%s` than the listing says. Nothing was unpacked.', $this->files[$this->index]->name));
        }
    }

    /**
     * Closes each file whose bytes are complete, an empty file at once.
     */
    private function settle(): void
    {
        while (isset($this->files[$this->index]) && $this->files[$this->index]->size === $this->size) {
            $entry = $this->files[$this->index];

            if ((int) hexdec(hash_final($this->crc)) !== $entry->crc) {
                throw new ExtractionMismatchException(sprintf('unzip returned `%s` with another CRC-32 than the listing says. Nothing was unpacked.', $entry->name));
            }

            $this->index++;
            $this->size = 0;
            $this->crc = hash_init('crc32b');
        }
    }
}
