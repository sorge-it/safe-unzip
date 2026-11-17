<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * An argument of `\ZipArchive` that this package does not support: an `open()` flag other than
 * `RDONLY` and `CHECKCONS`, a list of entries for `extractTo()`, an archive path that Info-ZIP
 * reads as a pattern, or a target with a scheme.
 */
final class UnsupportedException extends UnzipException {}
