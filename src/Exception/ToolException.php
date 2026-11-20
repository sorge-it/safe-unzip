<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * `unzip` or `zipinfo` is missing, is a different release or cannot start, stopped with an exit
 * code or a signal, printed more than its limit, or ran into the timeout; or `stream_select()`
 * failed.
 */
final class ToolException extends UnzipException {}
