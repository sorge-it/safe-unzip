<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\UnsupportedException;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

/**
 * The methods of the extension that this package has.
 */
const SUPPORTED = ['open', 'extractTo', 'close', 'count'];

/**
 * How a parameter looks from outside: name, type, reference, variadic and default.
 *
 * @return array<string, mixed>
 */
function signature(ReflectionParameter $parameter): array
{
    return [
        'name' => $parameter->getName(),
        'type' => (string) $parameter->getType(),
        'reference' => $parameter->isPassedByReference(),
        'variadic' => $parameter->isVariadic(),
        'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : 'none',
    ];
}

/**
 * The names of a type, such as `['bool', 'int']` of `int|bool`; the tentative type of the extension
 * where it declares none.
 *
 * @return list<string>
 */
function types(ReflectionMethod $method): array
{
    $type = $method->getReturnType() ?? $method->getTentativeReturnType();
    $names = $type instanceof ReflectionUnionType ? array_map(strval(...), $type->getTypes()) : [(string) $type];
    sort($names);

    return $names;
}

it('has the four methods of the extension with its parameters, and no other public method', function (): void {
    $ours = array_map(static fn (ReflectionMethod $method): string => $method->getName(), new ReflectionClass(ZipArchive::class)->getMethods(ReflectionMethod::IS_PUBLIC));

    expect(array_values(array_filter($ours, static fn (string $method): bool => ! str_starts_with($method, '__'))))->toBe(SUPPORTED);

    foreach (SUPPORTED as $name) {
        $method = new ReflectionMethod(\ZipArchive::class, $name);
        $mine = new ReflectionMethod(ZipArchive::class, $name);

        // `bool` of `open()` is one of the extension's `int|bool`: a caller that takes the wider type takes it.
        expect(array_map(signature(...), $mine->getParameters()))->toBe(array_map(signature(...), $method->getParameters()), $name)
            ->and(array_diff(types($mine), types($method)))->toBe([], $name);
    }
});

it('has the two flags of open() with the values of the extension', function (): void {
    expect(new ReflectionClass(ZipArchive::class)->getConstants())->toBe(['CHECKCONS' => \ZipArchive::CHECKCONS, 'RDONLY' => \ZipArchive::RDONLY]);
});

it('has no other method of the extension', function (): void {
    expect(fn (): mixed => (new ZipArchive)->getFromName('a.txt'))->toThrow(Error::class, 'Call to undefined method '.ZipArchive::class.'::getFromName()');
});

it('refuses a list of entries in extractTo() before it starts a tool', function (array|string $files): void {
    $directory = temporaryDirectory();
    $marker = $directory.'/started';
    // open() starts `unzip -v` for the release.
    $environment = fakeTool($directory, 'unzip', '[ "$1" = -v ] || touch '.escapeshellarg($marker).'; exec /usr/bin/unzip "$@"');
    $zip = zip($environment);
    $zip->open(archive(ZipFixture::new()->file('a.txt', "a\n")));

    $before = file_exists($marker);

    expect(fn (): bool => $zip->extractTo($directory.'/target', $files))->toThrow(UnsupportedException::class, 'with a list of entries')
        ->and($before)->toBeFalse()
        ->and(file_exists($marker))->toBeFalse()
        ->and(file_exists($directory.'/target'))->toBeFalse();
})->with([
    'a name' => ['a.txt'],
    'a list' => [['a.txt']],
    'an empty list' => [[]],
]);

it('refuses each flag of open() that creates or changes an archive', function (int $flag): void {
    expect(fn (): bool => (new ZipArchive)->open(archive(ZipFixture::new()->file('a.txt')), $flag))->toThrow(UnsupportedException::class, 'RDONLY and CHECKCONS only');
})->with([
    'CREATE' => [\ZipArchive::CREATE],
    'EXCL' => [\ZipArchive::EXCL],
    'OVERWRITE' => [\ZipArchive::OVERWRITE],
    'CREATE with RDONLY' => [\ZipArchive::CREATE | \ZipArchive::RDONLY],
]);

it('opens with RDONLY and CHECKCONS, as the installer calls it', function (): void {
    $zip = new ZipArchive;

    expect($zip->open(archive(ZipFixture::new()->file('a.txt', "a\n")), ZipArchive::RDONLY | ZipArchive::CHECKCONS))->toBeTrue()
        ->and($zip->numFiles)->toBe(1);
});

it('shows three properties of the extension', function (): void {
    $file = archive(ZipFixture::new()->file('a.txt', "a\n")->file('b.txt', "b\n")->comment('a comment'));
    $zip = new ZipArchive;
    $zip->open($file);

    expect([$zip->numFiles, $zip->filename, $zip->comment])->toBe([2, realpath($file), 'a comment']);

    $zip->close();

    expect([$zip->numFiles, $zip->filename, $zip->comment])->toBe([0, '', '']);
});

it('lets no caller write a property', function (string $property, int|string $value): void {
    $zip = new ZipArchive;

    expect(function () use ($zip, $property, $value): void {
        $zip->{$property} = $value;
    })->toThrow(Error::class, 'Cannot modify private(set) property');
})->with([
    'numFiles' => ['numFiles', 5],
    'filename' => ['filename', '/etc/passwd'],
    'comment' => ['comment', 'x'],
]);

it('cannot be cloned or serialized, as the extension', function (): void {
    expect(fn (): ZipArchive => clone new ZipArchive)->toThrow(Error::class, 'Trying to clone an uncloneable object')
        ->and(fn (): string => serialize(new ZipArchive))->toThrow(Exception::class, 'is not allowed')
        ->and(fn (): mixed => unserialize(sprintf('O:%d:"%s":0:{}', strlen(ZipArchive::class), ZipArchive::class)))->toThrow(Exception::class, 'is not allowed');
});

it('refuses an empty path, as the extension', function (): void {
    expect(fn (): bool => (new ZipArchive)->open(''))->toThrow(ValueError::class, 'ZipArchive::open(): Argument #1 ($filename) must not be empty');
});

it('needs an open archive for extractTo() and close(), as the extension', function (string $method): void {
    expect(fn () => (new ZipArchive)->{$method}(temporaryDirectory()))->toThrow(ValueError::class, 'Invalid or uninitialized Zip object');
})->with(['extractTo', 'close']);

it('refuses a path with a NUL byte, as the extension', function (string $method, string $argument): void {
    expect(fn () => (new ZipArchive)->{$method}("a\0b"))->toThrow(ValueError::class, sprintf('ZipArchive::%s(): Argument #1 (%s) must not contain any null bytes', $method, $argument));
})->with([['open', '$filename'], ['extractTo', '$pathto']]);
