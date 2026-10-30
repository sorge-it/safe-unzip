<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;

/*
 * The unzip binary unpacks; PHP never does. PHP reads bytes of the archive in one class only, for
 * the fallback reads that the README names.
 */

/**
 * Each function that each source file under `src/` calls, and each string it holds, by file.
 *
 * @return array<string, array{calls: list<string>, strings: list<string>}>
 */
function sources(): array
{
    $root = dirname(__DIR__, 2).'/src/';
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $path) {
        $file = substr((string) $path, strlen($root));
        $tokens = PhpToken::tokenize((string) file_get_contents((string) $path));
        $calls = [];
        $strings = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $strings[] = $token->text;
            }

            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            // The next token that is no whitespace: a call has `(`, also a first-class callable.
            $next = $index + 1;

            while (isset($tokens[$next]) && $tokens[$next]->is(T_WHITESPACE)) {
                $next++;
            }

            if (($tokens[$next]->text ?? '') === '(') {
                $calls[] = strtolower(ltrim($token->text, '\\'));
            }
        }

        $sources[$file] = ['calls' => $calls, 'strings' => $strings];
    }

    ksort($sources);

    return $sources;
}

/**
 * The files that call one of the functions.
 *
 * @param  list<string>  $functions
 * @return list<string>
 */
function calling(array $functions): array
{
    return array_keys(array_filter(sources(), static fn (array $source): bool => array_intersect($source['calls'], $functions) !== []));
}

it('decompresses nothing in PHP', function (): void {
    $strings = array_merge(...array_column(sources(), 'strings'));

    // The classes of ext-zip and ext-phar unpack as well.
    expect(calling(['gzinflate', 'gzuncompress', 'gzdecode', 'zlib_decode', 'inflate_init', 'inflate_add', 'gzopen', 'gzread', 'gzfile', 'readgzfile', 'bzdecompress', 'bzopen', 'stream_filter_append', 'stream_filter_prepend', 'phardata', 'phar', 'ziparchive']))->toBe([])
        // The callables that take a function name as a string.
        ->and(calling(['call_user_func', 'call_user_func_array']))->toBe([])
        ->and(array_values(preg_grep('~zlib\.|compress\.zlib|compress\.bzip2|zip://|phar://|php://~i', $strings) ?: []))->toBe([]);
});

it('unpacks where the functions and stream wrappers of PHP that decompress are disabled', function (): void {
    $file = archive(ZipFixture::new()->file('a.txt', str_repeat("deflated\n", 100), ['method' => 8]));
    $target = temporaryDirectory();
    $disabled = ['gzinflate', 'gzuncompress', 'gzdecode', 'zlib_decode', 'inflate_init', 'inflate_add', 'gzopen', 'gzfile', 'readgzfile', 'bzdecompress', 'bzopen', 'stream_filter_append', 'stream_filter_prepend'];
    // `php` carries `php://filter/read=zlib.inflate`.
    $code = sprintf('foreach (["compress.zlib", "compress.bzip2", "zip", "phar", "php"] as $wrapper) { in_array($wrapper, stream_get_wrappers(), true) && stream_wrapper_unregister($wrapper); } $zip = new SorgeIt\SafeUnzip\ZipArchive; $zip->open(%s); $zip->extractTo(%s); echo "unpacked";', var_export($file, true), var_export($target, true));

    expect(php($code, ['disable_functions='.implode(',', $disabled)]))->toBe('unpacked')
        ->and(file_get_contents($target.'/a.txt'))->toBe(str_repeat("deflated\n", 100));
});

it('reads the archive in one class only', function (): void {
    expect(calling(['fopen', 'fseek', 'hash_file']))->toBe(['Archive/Headers.php'])
        ->and(calling(['md5_file', 'sha1_file', 'hash_update_file', 'hash_update_stream', 'stream_copy_to_stream']))->toBe([])
        // Process reads the pipes of the tools.
        ->and(calling(['fread']))->toBe(['Archive/Headers.php', 'Tools/Process.php'])
        ->and(calling(['file_get_contents', 'file', 'readfile', 'fgets', 'fgetc', 'fpassthru', 'stream_get_contents', 'fscanf', 'splfileobject']))->toBe([]);
});

it('leaves only the steps out of the coverage that another process or the system decides, each with its reason', function (): void {
    $root = dirname(__DIR__, 2).'/src/';
    $ignored = [];
    $markers = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $path) {
        $source = (string) file_get_contents((string) $path);
        $markers += substr_count($source, '@codeCoverageIgnore');
        // A reason of one or more comment lines, then the range; its code lines, without comments.
        preg_match_all('~^ *// Untested: [^\n]+\n(?: *// [^\n]+\n)*? *// @codeCoverageIgnoreStart\n((?:(?! *// @codeCoverageIgnore)[^\n]*\n)+) *// @codeCoverageIgnoreEnd$~m', $source, $ranges);

        foreach ($ranges[1] as $range) {
            $code = array_filter(array_map(trim(...), explode("\n", $range)), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '//'));
            $ignored[] = substr((string) $path, strlen($root)).': '.implode(' ', $code);
        }
    }

    $phpunit = (string) file_get_contents(dirname(__DIR__, 2).'/phpunit.xml');

    // A new range here is a decision: a test reaches each other step.
    expect($ignored)->toBe(['Extraction/Filesystem.php: throw new FilesystemException($message);'])
        // Each marker is in one of these ranges, and `phpunit.xml` leaves no file out.
        ->and($markers)->toBe(2 * count($ignored))
        ->and($phpunit)->not->toContain('<exclude>');
});

it('writes no unpacked entry in PHP', function (): void {
    expect(calling(['fwrite', 'fputs', 'file_put_contents', 'copy', 'tempnam', 'tmpfile', 'touch', 'link', 'symlink']))->toBe([]);
});
