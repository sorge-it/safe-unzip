<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * The bytes of the archive: the file is no ZIP archive, `zipinfo` describes it in a way the
 * grammar does not know, or `unzip` cannot decompress an entry.
 */
final class InvalidArchiveException extends UnzipException {}
