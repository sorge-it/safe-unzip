<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use LogicException;
use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\CentralHeader;
use SorgeIt\SafeUnzip\Archive\DataDescriptor;
use SorgeIt\SafeUnzip\Archive\LocalHeader;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S1: the central header, the local header and the data descriptor say the same, as libzip
 * compares them with `CHECKCONS`, and the flags too, which libzip does not. The listing of
 * `zipinfo` is the central header that PHP reads, as both read the same bytes.
 *
 * @internal
 */
final readonly class HeaderMismatchRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::HeaderMismatch;
    }

    public function check(Archive $archive): ?string
    {
        foreach ($archive->listing->entries as $index => $entry) {
            // S3 refuses an entry without a local header first.
            $local = $archive->headers->local[$index] ?? throw new LogicException('S3 runs before S1.');
            $detail = $this->local($archive->headers->central[$index], $local);

            if ($detail !== null) {
                return sprintf('the archive entry `%s`: %s.', $entry->name, $detail);
            }
        }

        return null;
    }

    private function local(CentralHeader $central, LocalHeader $local): ?string
    {
        // Within one header: a stored entry has one size, `unzip` would take the one and libzip the other.
        if ($central->method === 0 && $central->compressedSize !== $central->size) {
            return sprintf('the stored entry has a size of %d and a compressed size of %d', $central->size, $central->compressedSize);
        }

        $fields = ['name' => 'name', 'needed' => 'version needed to extract', 'flags' => 'flags', 'method' => 'compression method', 'time' => 'modification time'];

        foreach ($fields as $field => $words) {
            if ($central->{$field} !== $local->{$field}) {
                return sprintf('the local header has another %s than the central header', $words);
            }
        }

        $values = ['crc' => 'CRC-32', 'compressedSize' => 'compressed size', 'size' => 'size'];

        foreach ($values as $field => $words) {
            // With a data descriptor, the local header may leave its values at 0.
            $deferred = ($central->flags & 0x8) !== 0 && $local->{$field} === 0;

            if (! $deferred && $local->{$field} !== $central->{$field}) {
                return sprintf('the local header has another %s than the central header', $words);
            }

            if ($local->descriptor instanceof DataDescriptor && $local->descriptor->{$field} !== $central->{$field}) {
                return sprintf('the data descriptor has another %s than the central header', $words);
            }
        }

        return null;
    }
}
