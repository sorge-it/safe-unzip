<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * `unzip` or `zipinfo` is missing or cannot start, stopped with an exit code, printed more than
 * its limit, or ran into the timeout.
 */
final class ToolException extends UnzipException {}
