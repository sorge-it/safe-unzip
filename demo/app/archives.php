<?php

declare(strict_types=1);

// Builds the two archives of the demo with the fixture builder of the tests.

use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;

require __DIR__.'/../../tests/Support/ZipFixture.php';

// A program with its executable bit, as in the ZIP of Chrome for Testing.
$tool = ZipFixture::new();
$tool->file('bin/tool', "#!/bin/sh\necho ok\n", ['mode' => 0o100755])->write(__DIR__.'/tool.zip');

// An overlap bomb: 20 central headers point to the same 10 MiB of zeros, which deflate to 10 KiB.
$size = 10 * 1024 * 1024;
$crc = ZipFixture::crc(str_repeat("\0", $size));
$entry = ['method' => 8, 'compressed' => ZipFixture::zeros(10), 'local' => ['size' => $size, 'crc' => $crc], 'central' => ['size' => $size, 'crc' => $crc]];
$bomb = ZipFixture::new();
$bomb->file('f00.bin', '', $entry);

for ($i = 1; $i < 20; $i++) {
    $entry['central']['offset'] = 0;
    $bomb->file(sprintf('f%02d.bin', $i), '', $entry);
}

$bomb->write(__DIR__.'/overlap.zip');
