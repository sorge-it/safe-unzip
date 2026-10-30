<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Archive;

/**
 * The subfields of an extra field: each an ID, a length and its data.
 *
 * @internal
 */
final readonly class ExtraFields
{
    public const int ZIP64 = 0x0001;

    /**
     * @param  list<int>  $ids
     * @param  bool  $wellFormed  whether the lengths of the subfields add up to the length of the field
     * @param  string  $zip64  the data of the ZIP64 subfield, `''` without one
     */
    private function __construct(public array $ids, public bool $wellFormed, public string $zip64) {}

    public static function parse(string $bytes): self
    {
        $ids = [];
        $zip64 = '';
        $offset = 0;

        while ($offset + 4 <= strlen($bytes)) {
            /** @var array{id: int, size: int} $field */
            $field = unpack('vid/vsize', $bytes, $offset);
            $offset += 4;

            if ($offset + $field['size'] > strlen($bytes)) {
                return new self([...$ids, $field['id']], false, $zip64);
            }

            $ids[] = $field['id'];

            if ($field['id'] === self::ZIP64 && $zip64 === '') {
                $zip64 = substr($bytes, $offset, $field['size']);
            }

            $offset += $field['size'];
        }

        return new self($ids, $offset === strlen($bytes), $zip64);
    }

    /**
     * The 64-bit values of the ZIP64 subfield that stand for the fields at their maximum, in the
     * order of APPNOTE 4.5.3: size, compressed size, offset of the local header. A value that the
     * subfield lacks stays `null`.
     *
     * @param  list<bool>  $maximum  for each of the three, whether its 32-bit field is `0xFFFFFFFF`
     * @return list<int|null>
     */
    public function zip64(array $maximum): array
    {
        $values = [];
        $offset = 0;

        foreach ($maximum as $at) {
            if (! $at) {
                $values[] = null;

                continue;
            }

            /** @var array{1: int}|false $value */
            $value = strlen($this->zip64) >= $offset + 8 ? unpack('P', $this->zip64, $offset) : false;
            $values[] = $value === false ? null : $value[1];
            $offset += 8;
        }

        return $values;
    }
}
