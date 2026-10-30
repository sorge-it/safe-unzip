<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

use RuntimeException;

/**
 * Where `\ZipArchive` returns `false` or an error code, this package throws a subclass.
 */
abstract class UnzipException extends RuntimeException {}
