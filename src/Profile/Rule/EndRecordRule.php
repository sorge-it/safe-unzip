<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile\Rule;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Archive\EndRecords;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;

/**
 * S5: one end record, where `zipinfo` found it, with a comment of its stated length. A second end
 * record leaves bytes after the first or inside the archive, which S4 and S3 refuse.
 *
 * @internal
 */
final readonly class EndRecordRule implements Rule
{
    public function reason(): Reason
    {
        return Reason::EndRecord;
    }

    public function check(Archive $archive): ?string
    {
        $header = $archive->listing->header;
        $end = $archive->headers->end;

        if (! $end instanceof EndRecords) {
            return sprintf('no end record is at offset %d, where zipinfo found one.', $header->actualOffset);
        }

        if ($header->truncated || strlen($end->comment) !== $end->regular->commentLength) {
            return sprintf('the archive comment is %d bytes long by the end record, and the file ends before.', $end->regular->commentLength);
        }

        return null;
    }
}
