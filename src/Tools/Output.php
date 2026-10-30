<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tools;

/**
 * What a tool printed, and how it ended.
 *
 * @internal
 */
final readonly class Output
{
    public function __construct(public string $output, public int $exit, public string $errors) {}
}
