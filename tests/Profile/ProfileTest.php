<?php

declare(strict_types=1);

use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Profile\Profile;
use SorgeIt\SafeUnzip\Profile\Reason;
use SorgeIt\SafeUnzip\Profile\Rule;
use SorgeIt\SafeUnzip\Tests\Support\Fixtures;
use SorgeIt\SafeUnzip\Tests\Support\ZipFixture;
use SorgeIt\SafeUnzip\ZipArchive;

it('opens and unpacks an archive of the profile', function (ZipFixture $fixture): void {
    $file = archive($fixture);
    $target = temporaryDirectory();
    $zip = zip();

    expect($zip->open($file))->toBeTrue()
        ->and($zip->extractTo($target))->toBeTrue()
        ->and($zip->close())->toBeTrue();
})->with(Fixtures::accepted());

// open() takes no target, so nothing can be written before extractTo().
it('refuses an archive that breaks a rule in open()', function (Reason $reason, ZipFixture $fixture): void {
    try {
        zip()->open(archive($fixture));
        $this->fail('open() accepted an archive that breaks '.$reason->value);
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe($reason, $refusedException->getMessage());
    }
})->with(Fixtures::refused());

it('refuses an archive whose ZIP64 end record zipinfo does not take, under a limit of 65535 entries', function (Reason $reason, ZipFixture $fixture): void {
    try {
        new ZipArchive(new Limits(0xFFFF, Fixtures::LIMITS[1]))->open(archive($fixture));
        $this->fail('open() accepted an archive that breaks '.$reason->value);
    } catch (RefusedException $refusedException) {
        expect($refusedException->reason)->toBe($reason, $refusedException->getMessage());
    }
})->with(Fixtures::refusedBeyondZip64());

it('refuses a ZIP64 locator without its ZIP64 end record', function (): void {
    // zipinfo finds no end record behind such a locator, before Zip64Rule could refuse it.
    expect(fn (): bool => zip()->open(archive(ZipFixture::new()->file('a.txt', "aaaa\n")->locator([]))))->toThrow(InvalidArchiveException::class, 'zipinfo finds no end record');
});

it('refuses an archive for which zipinfo stops with exit code 51, before a rule can read it', function (): void {
    // The name length of the first central header, at 0xEF: the name runs beyond the file.
    $bytes = ZipFixture::new()->file('a.txt', "a\n")->file('b.txt', "b\n")->build();
    $bytes[(int) strpos($bytes, "PK\1\2") + 28] = "\xEF";
    $file = temporaryDirectory().'/name.zip';
    file_put_contents($file, $bytes);

    expect(fn (): bool => zip()->open($file))->toThrow(InvalidArchiveException::class, 'zipinfo stopped with exit code 51:');
});

it('refuses an archive for which zipinfo warns, where no rule explains the warning', function (): void {
    $environment = fakeTool(temporaryDirectory(), 'zipinfo', '[ "$1" = -v ] || exec /usr/bin/zipinfo "$@"; /usr/bin/zipinfo "$@"; echo "warning: a new warning" >&2; exit 1');

    expect(fn (): bool => zip($environment)->open(archive(ZipFixture::new()->file('a.txt', "a\n"))))->toThrow(InvalidArchiveException::class, 'zipinfo stopped with exit code 1: warning: a new warning Nothing was unpacked.');
});

it('keeps the order of the rules', function (): void {
    // A rule that runs earlier hides a later one, and the fixtures of each depend on that: a new
    // order is a decision, not a side effect.
    expect(array_map(static fn (Rule $rule): string => $rule->reason()->value, new Profile(new Limits)->rules()))
        ->toBe(['S8', 'S10', 'S4', 'S5', 'S2', 'S7', 'S3', 'S6', 'N1', 'N3', 'N8', 'S1', 'S12', 'S16', 'S14', 'S13', 'N5', 'S9', 'N6', 'N7', 'L1']);
});

it('has a fixture for each refusal of an archive', function (): void {
    $covered = array_unique(array_map(static fn (array $case): string => $case[0]->value, Fixtures::refused()));
    // N9 and L2 need the target: suite Extraction covers them.
    $expected = array_diff(array_map(static fn (Reason $reason): string => $reason->value, Reason::cases()), [Reason::PathLength->value, Reason::Target->value]);

    expect(array_values(array_diff($expected, $covered)))->toBe([]);
});

it('has one rule class for each refusal the listing does not make itself', function (): void {
    $reasons = array_map(static fn (Rule $rule): string => $rule->reason()->value, new Profile(new Limits)->rules());
    // The listing refuses these while it reads; the staging refuses N9, the placement L2.
    $elsewhere = [Reason::EntryComment->value, Reason::ArchiveComment->value, Reason::PathLength->value, Reason::Target->value];

    expect($reasons)->toHaveCount(count(array_unique($reasons)))
        ->and(array_values(array_diff(array_map(static fn (Reason $reason): string => $reason->value, Reason::cases()), $reasons, $elsewhere)))->toBe([]);
});
