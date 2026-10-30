<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * An argument of `\ZipArchive` that this package does not support: a flag of `open()` that writes,
 * a list of entries for `extractTo()`, or a path that Info-ZIP reads as a pattern.
 */
final class UnsupportedException extends UnzipException {}
