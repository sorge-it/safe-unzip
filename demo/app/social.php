<?php

declare(strict_types=1);

// The screen of the social preview: the name, then the overlap bomb through both classes.

// Columns of the terminal in social.tape, less the indent: the wrapped lines keep it.
const WIDTH = 72;

exec('php ext-zip.php overlap.zip out-social-1 > /dev/null && du -sh --apparent-size out-social-1', $written);
exec('php safe-unzip.php overlap.zip out-social-2 2>&1', $refused);
exec('rm -rf out-social-*');
$written = str_replace('out-social-1', 'out', $written);
// The class, then the message: safe-unzip.php wraps it for the GIF, here it wraps at WIDTH.
$refused = [array_shift($refused), ...explode("\n", wordwrap(implode(' ', $refused), WIDTH, "\n", true))];

// No cursor: the screenshot shows the output only.
echo "\033[?25l\033[H\033[2J";
echo "  \033[1;35msorge-it/safe-unzip\033[0m\n";
echo "  Safe ZIP extraction for PHP code that AI coding agents write.\n\n";
printf("  \033[2m# %d KiB, 20 entries on the same 10 MiB of zeros\033[0m\n", round(filesize('overlap.zip') / 1024));
echo "  $ php ext-zip.php overlap.zip out && du -sh --apparent-size out\n";
echo '  '.implode("\n  ", $written)."\n\n";
echo "  $ php safe-unzip.php overlap.zip out\n";
echo "  \033[31m".implode("\n  ", $refused)."\033[0m\n\n";
echo "  An allow list of what a ZIP may be. Exceptions instead of false.\n";
echo "  The executable bit kept. No ext-zip.\n";
