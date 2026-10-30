<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Exception;

/**
 * The file system, not the bytes of the archive: the archive is missing, no file or changed since
 * it was hashed, or a directory could not be created, or a file not moved, read or given its mode.
 */
final class FilesystemException extends UnzipException {}
