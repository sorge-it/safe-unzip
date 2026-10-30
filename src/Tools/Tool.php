<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tools;

/**
 * The two programs of Info-ZIP UnZip 6.0 that this package starts.
 *
 * @internal
 */
enum Tool: string
{
    case Unzip = 'unzip';
    case Zipinfo = 'zipinfo';
}
