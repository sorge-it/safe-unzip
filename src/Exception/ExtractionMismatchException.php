<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * `unzip` wrote or returned something other than what the listing says: another file, another
 * size, another checksum, a link.
 */
final class ExtractionMismatchException extends UnzipException {}
