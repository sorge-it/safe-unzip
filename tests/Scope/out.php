<?php

declare(strict_types=1);

/*
 * A guard for each row out of Scope in the README, in table order. `row` starts the row.
 * `absent` maps a pattern that `src` must not match to a line it finds. `none` says why a row has
 * no check.
 */
return [
    [
        'row' => 'Reading or extracting a single entry',
        'absent' => [
            '/function (getFromName|getFromIndex|getStream|getStreamName|getStreamIndex|statName|statIndex|locateName|getNameIndex|getComment(Name|Index)|getExternalAttributes(Name|Index))\b/i' => 'public function getFromName(string $name, int $len = 0, int $flags = 0): string|false',
            '/\(array\)\s*\$files\b|foreach\s*\(\s*\$files\b|\[\s*\$files\s*\]|\.\.\.\s*\$files\b|array_merge\s*\([^)]*\$files\b/' => '$names = is_string($files) ? [$files] : $files;',
        ],
    ],
    [
        'row' => 'Writing or changing an archive',
        'absent' => [
            '/function (add(File|FromString|EmptyDir|Glob|Pattern)|delete(Name|Index)|rename(Name|Index)|replaceFile|set(ArchiveComment|ArchiveFlag|Comment\w+|Compression\w+|Encryption\w+|ExternalAttributes\w+|Mtime\w+|Password))\b/i' => 'public function addFromString(string $name, string $content, int $flags = 8): bool',
            '/function (unchange(All|Archive|Index|Name)|register(Progress|Cancel)Callback)\b/i' => 'public function unchangeAll(): bool',
            '/const (int )?(CREATE|EXCL|OVERWRITE|TRUNCATE)\b/' => 'public const int CREATE = 1;',
            '/[\'"]-P[\'"]/' => "\$command = [\$unzip, '-P', \$password, '-q', \$archive];",
        ],
    ],
    [
        'row' => 'Extracting what the profile refuses',
        'none' => 'Info-ZIP writes every entry, so no pattern in `src` finds a link or a device. The `Profile` suite refuses each kind with a fixture, and the tree comparison refuses a link or a node that `unzip` wrote.',
    ],
    [
        'row' => 'Windows as the host system',
        'absent' => [
            '/\bPHP_OS(_FAMILY)?\b/' => "if (PHP_OS_FAMILY === 'Windows') {",
            '/\bDIRECTORY_SEPARATOR\b/' => '$path = $target.DIRECTORY_SEPARATOR.$name;',
        ],
    ],
    [
        'row' => 'Running with an `unzip` or `zipinfo` other than',
        'none' => '`Versions` refuses each first line that does not start with that of UnZip 6.00 or ZipInfo 3.00. The test "refuses another release of Info-ZIP" of `Tools` checks three of them.',
    ],
    [
        'row' => 'A ZIP parser in PHP',
        'absent' => [
            '/\b(strpos|strrpos|stripos|strstr|strrchr|preg_match(_all)?)\s*\([^;]*PK\\\\/' => '$end = strrpos($bytes, "PK\5\6");',
        ],
    ],
    [
        'row' => 'A check of the host in advance',
        'absent' => [
            '/\bdisk_(free|total)_space\s*\(/' => 'if (disk_free_space($pathto) < $bytes) {',
            '/\/proc\/meminfo|hw\.memsize/' => "\$free = file_get_contents('/proc/meminfo');",
            '/disable_functions|open_basedir/' => "if (str_contains((string) ini_get('disable_functions'), 'proc_open')) {",
            '/\b(function_exists|is_callable)\s*\(\s*[\'"]proc_open/' => "if (! function_exists('proc_open')) {",
        ],
    ],
    [
        'row' => 'A defense against a deliberate change',
        'absent' => [
            '/\bflock\s*\(/' => 'flock($handle, LOCK_EX);',
            '/\bfileinode\s*\(|\/dev\/fd\//' => "\$command = [\$unzip, '-p', '/dev/fd/3'];",
            '/\[\s*[\'"]ino[\'"]\s*\]/' => "\$inode = stat(\$file)['ino'];",
            '/\bcopy\s*\(/' => "copy(\$file, \$staging.'/archive.zip');",
        ],
    ],
    [
        'row' => 'A cause in the archive that Info-ZIP does not report',
        'absent' => [
            '/\b(gzinflate|gzuncompress|gzdecode|zlib_decode|inflate_init|inflate_add)\s*\(/' => '$data = gzinflate($compressed);',
            '/[\'"]zlib\./' => "stream_filter_append(\$handle, 'zlib.inflate');",
        ],
    ],
];
