<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

use SorgeIt\SafeUnzip\Archive\Archive;

/**
 * One refusal of the profile "standard ZIP".
 *
 * @internal
 */
interface Rule
{
    public function reason(): Reason;

    /**
     * What breaks the rule, as the end of a sentence; `null` where the archive keeps it.
     */
    public function check(Archive $archive): ?string;
}
