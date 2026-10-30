<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\ZipArchive;

require __DIR__.'/vendor/autoload.php';

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class."\n".wordwrap($exception->getMessage(), 100)."\n");
    exit(1);
});

$zip = new ZipArchive;
$opened = $zip->open($argv[1]);
echo 'open: ', var_export($opened, true), "\n";
$extracted = $zip->extractTo($argv[2]);
echo 'extractTo: ', var_export($extracted, true), "\n";
$zip->close();
