# Safe ZIP extraction for PHP

[![Tests](https://github.com/sorge-it/safe-unzip/actions/workflows/tests.yml/badge.svg)](https://github.com/sorge-it/safe-unzip/actions/workflows/tests.yml)
[![php-src parity](https://github.com/sorge-it/safe-unzip/actions/workflows/php-src.yml/badge.svg)](https://github.com/sorge-it/safe-unzip/actions/workflows/php-src.yml)
[![Latest Version](https://img.shields.io/packagist/v/sorge-it/safe-unzip)](https://packagist.org/packages/sorge-it/safe-unzip)
[![Total Downloads](https://img.shields.io/packagist/dt/sorge-it/safe-unzip)](https://packagist.org/packages/sorge-it/safe-unzip)
[![License](https://img.shields.io/packagist/l/sorge-it/safe-unzip)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/sorge-it/safe-unzip)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)

**`\ZipArchive` drops the executable bit and turns 202 KB into 200 MB.** SafeUnzip extracts with
Info-ZIP's `unzip` and `zipinfo`, behind the interface of PHP's `ZipArchive`. An allow list defines
what a ZIP file may contain. Anything else throws an exception before a single byte is written.
Made for code that AI coding agents write and continuous deployment ships, where no human looks at
the archive.

```php
use SorgeIt\SafeUnzip\ZipArchive; // instead of \ZipArchive, for these calls

$zip = new ZipArchive;
$zip->open('/path/to/archive.zip', ZipArchive::RDONLY | ZipArchive::CHECKCONS);
$zip->extractTo('/path/to/target');
$zip->close();
```

SafeUnzip does one thing: it extracts a whole archive, safely. It is built for archives that a
program downloads, such as a GitHub zipball for Composer or chrome-headless-shell from Chrome for
Testing. It implements four methods of `ZipArchive`: `open()`, `extractTo()`, `close()` and
`count()`. No other method exists, so PHPStan reports any call to one and PHP throws an `Error`.
It is not a drop-in replacement for `\ZipArchive`; see [what breaks](#the-interface).

**What it refuses by design.** The allow list is strict, so test it against your archives first:

- **Entry names** with characters outside `A-Z a-z 0-9 . _ - /`: no spaces, no umlauts, no
  backslashes. An entry named `docs/My Report.pdf` is refused.
- **Symbolic links**, hard links and devices. This includes a GitHub zipball of a repository that
  contains a symbolic link.
- **Encryption**, compression methods other than store and deflate, self-extracting archives, and
  entry comments.

Each refusal throws a `RefusedException` that names the rule. The full list is under
[Not supported](#not-supported).

![The same script with one other use line: ZipArchive loses the executable bit and writes 200 MB from a 202 KB overlap bomb; SafeUnzip keeps the bit and refuses the bomb before it writes](https://raw.githubusercontent.com/sorge-it/safe-unzip/main/demo/output/demo.gif)

The two scripts differ in a single `use` line. `\ZipArchive` extracts a program without its
executable bit, and writes 200 MB from a 202 KB archive whose 20 entries share the same data.
SafeUnzip keeps the bit and refuses the second archive in `open()`. The demo lives in
[`demo/`](demo) and runs in Docker on Debian 13, with PHP 8.4 and the system's libzip.

## Installation

```sh
composer require sorge-it/safe-unzip
```

## Requirements

- PHP 8.4 or later, with `proc_open()`. No `ext-zip`, no other package.
- Info-ZIP UnZip 6.00 and ZipInfo 3.00: the `unzip` and `zipinfo` programs from the system's
  `unzip` package (`apt install unzip`, `apk add unzip`; macOS ships them). The first `open()` on
  each object checks the first line of `unzip -v` and `zipinfo` and throws a `ToolException` for
  any other release, such as BusyBox's `unzip`.
- Linux or macOS. Windows is not supported.

## Why this exists

I don't trust `\ZipArchive` with a ZIP file I didn't create myself. ZIP is a complex format. Each
entry has a local and a central header, an archive has two end records, and a size can appear in
three places. Wherever two fields describe the same thing, two programs can read two different
things. And every program that reads these fields has had security bugs of its own. As of
2026-10-29:

- **PHP's `\ZipArchive`** (ext-zip): a directory traversal in `extractTo()`
  ([CVE-2014-9767](https://nvd.nist.gov/vuln/detail/CVE-2014-9767), fixed in PHP 5.6.13 on
  2015-09-03, and [CVE-2021-21706](https://nvd.nist.gov/vuln/detail/CVE-2021-21706) on Windows,
  fixed in PHP 8.0.11 on 2021-09-23), integer overflows
  ([CVE-2016-3078](https://nvd.nist.gov/vuln/detail/CVE-2016-3078), PHP 7.0.6 on 2016-04-28) and a
  use-after-free ([CVE-2016-5773](https://nvd.nist.gov/vuln/detail/CVE-2016-5773), PHP 7.0.8 on
  2016-06-23). Each fix shipped with a new PHP release, and nowhere else.
- **libzip**, the library behind ext-zip and a system package since PHP 7.4: heap and integer
  overflows, a double free and a use-after-free when reading the central directory
  ([CVE-2012-1162](https://nvd.nist.gov/vuln/detail/CVE-2012-1162),
  [CVE-2015-2331](https://nvd.nist.gov/vuln/detail/CVE-2015-2331),
  [CVE-2017-12858](https://nvd.nist.gov/vuln/detail/CVE-2017-12858),
  [CVE-2019-17582](https://nvd.nist.gov/vuln/detail/CVE-2019-17582)). After the
  [ZipDiff paper](#what-a-safe-zip-is), libzip 1.11 (2024-09-19) changed how it selects the
  central directory. Debian 12 still ships libzip 1.7.3 from 2020-07-15, without that change.
- **Info-ZIP UnZip 6.0** had its last release in 2009. Distributions patch it:
  [Debian](https://security-tracker.debian.org/tracker/source-package/unzip) lists 23 fixed CVEs,
  including the overlap zip bomb
  [CVE-2019-13232](https://security-tracker.debian.org/tracker/CVE-2019-13232). That CVE has been
  public since 2019-07-04. More than seven years later, macOS's `unzip` still has no fix: it writes
  the demo's bomb, all 200 MB of it.
- **macOS** fixed three ZIP archives that bypass Gatekeeper between 2026-05-11 and 2026-10-26
  ([CVE-2026-28914](https://nvd.nist.gov/vuln/detail/CVE-2026-28914),
  [CVE-2026-84610](https://nvd.nist.gov/vuln/detail/CVE-2026-84610) and
  [CVE-2026-112672](https://nvd.nist.gov/vuln/detail/CVE-2026-112672); Apple's notes
  [127115](https://support.apple.com/en-us/127115) and [149522](https://support.apple.com/en-us/149522)).

This matters even more with AI coding agents. An agent writes the code that downloads a release, a
browser for tests or a data set, and continuous deployment ships it minutes later. Often no human
ever looks at the archive. So the code has to refuse a bad archive on its own, and say why: an
agent skips past a `false`, but it acts on an exception that names the entry and the rule. A fix
also has to reach production as fast as the code does. Renovate or Dependabot merges a
`composer update` in the same pipeline; a new PHP release or system package usually needs a new
base image first.

So SafeUnzip is a safety net, the way [Safe PHP](https://github.com/thecodingmachine/safe) is one
for PHP's functions, which throw an exception there instead of returning `false`. It lives in
userland, as PHP code that Composer installs: small, quick to build, and with no new engine.

- **An allow list.** The ["standard ZIP" profile](#the-profile-standard-zip) defines what a ZIP
  file may be. SafeUnzip refuses everything else, including any archive whose redundant fields
  disagree, before a byte is written. So `unzip` writes exactly what `zipinfo` listed and PHP
  checked.
- **Unix modes.** `\ZipArchive::extractTo()` writes each file with the mode the umask gives it, so
  a binary loses its executable bit. That is why
  [Composer](https://github.com/composer/composer/blob/main/src/Composer/Downloader/ZipDownloader.php)
  tries the `unzip` binary first, and warns when it is missing: "any UNIX permissions (e.g.
  executable) defined in the archives will be lost".
- **A limit.** `\ZipArchive` has no limit on entries or bytes. A small archive can fill the disk,
  and entries that share their data multiply it.
- **Exceptions.** One class per cause, each with a message that names the entry. No `false`, no
  error codes.
- **A new rule ships with `composer update`.** When a new attack on the ZIP structure becomes
  known, such as a new zip bomb, a new profile rule refuses it in your next deploy. A fix in ext-zip
  waits for a PHP release, a fix in libzip for a system package. A rule cannot fix a memory bug in
  Info-ZIP itself: Info-ZIP reads the archive before any rule runs, so that fix comes with the
  system's `unzip` package (see [Limits of the model](#limits-of-the-model)).
- **System updates still count.** SafeUnzip replaces none of them. It runs the system's `unzip`
  and `zipinfo`, so an Info-ZIP fix applies here as soon as it is installed. PHP and libzip updates
  apply to the rest of your code as before.

I built this so that a download extracts to exactly what I checked, even when an agent wrote the
code and nobody looks at the archive. I hope it does the same for you.
— [Stefan Sorge](#about-the-author)

## Comparison

| | `\ZipArchive` (ext-zip) | `unzip` in a shell | SafeUnzip |
|---|---|---|---|
| Needs | `ext-zip` | Info-ZIP | Info-ZIP, PHP 8.4 |
| Unix modes | lost | kept | kept |
| The demo's overlap bomb, default flags | 200 MB written | macOS: 200 MB written, exit 1; Debian 13: stops with exit 12 after the first 10 MiB | refused in `open()` (S3), nothing written |
| Limit on entries and bytes | none | none | `Limits`, 10,000 entries and 2 GiB by default |
| What it accepts | whatever libzip reads | whatever Info-ZIP reads | the ["standard ZIP"](#the-profile-standard-zip) allow list |
| The target before the checks pass | written | written | untouched: a check pass into a pipe, then a staging directory |
| Errors | `false` or an error code | an exit code and text | one exception class per cause, with a `Reason` |

With `CHECKCONS`, libzip refuses the demo's overlap bomb too, as well as an archive whose local and
central headers name different compression methods. It does not keep Unix modes, enforce a limit,
or check names against an allow list.

## Parity with php-src

Every archive in php-src [`ext/zip/tests`](https://github.com/php/php-src/tree/master/ext/zip/tests)
is a fixture of the `PhpSrc` suite. Where SafeUnzip extracts an archive, its test compares the
names and contents with those that `\ZipArchive::extractTo()` extracts. For each other archive,
SafeUnzip writes nothing. `open()` refuses it with the `Reason` of the rule it breaks, or
`extractTo()` throws an `ExtractionMismatchException` for data that does not match its CRC-32.

The php-src fixtures are copies from commit `eefa52a5` (2026-10-26).
[`tests/Fixtures/php-src.lock`](tests/Fixtures/php-src.lock) lists the git blob of every file in
`ext/zip/tests` at that commit: the `.phpt` files, the archives and the helper files. A test in
the suite checks that each fixture matches its blob and has its own test.

The [`php-src parity`](.github/workflows/php-src.yml) workflow compares the lock with that commit
and with php-src `master`, every Monday, on each push to `main` and on each pull request. A red
badge means that the lock does not match php-src, mostly because php-src changed `ext/zip/tests`.
It does not mean that a test of this package fails. The maintainer then updates the lock, the
fixtures and their tests. GitHub disables the workflow after 60 days
without activity in the repository, until someone enables it again.

---

The rest of this file is reference: [The interface](#the-interface) ·
[Exceptions](#exceptions) · [The profile](#the-profile-standard-zip) · [Refusals](#refusals) ·
[Not supported](#not-supported) · [How it extracts](#how-it-extracts) ·
[Reads by PHP](#reads-by-php) · [Scope](#scope) · [What a safe ZIP is](#what-a-safe-zip-is) ·
[Measurements](#measurements) · [Tests](#tests)

## The interface

| Part | SafeUnzip |
|---|---|
| `new ZipArchive(Limits $limits = new Limits, array $environment = [], int $timeout = 900)` | the limits, environment variables for the tools (such as `PATH`), and the timeout in seconds for each tool run |
| `open($filename, $flags)` | lists and checks the archive; nothing is written. `RDONLY` and `CHECKCONS` are allowed; the checks always run |
| `extractTo($pathto)` | extracts the whole archive. As in the extension, a file with the same name in the target is replaced silently, and everything else in the target stays |
| `extractTo($pathto, $files)` | throws `UnsupportedException` before any tool starts |
| `close()` | closes the archive |
| `count()`, `count($zip)` | the number of entries, like `$numFiles`; without an open archive, `ValueError`, as in the extension |
| `RDONLY`, `CHECKCONS` | the extension's values; no other constants |
| `$numFiles`, `$filename`, `$comment` | readable; writing to them from outside throws an `Error` |

The four methods take the extension's parameters. No other method, property or constant of
`\ZipArchive` exists.

`Limits` has two fields: `entries` (10,000) and `bytes` (2 GiB, the total size of all entries). A
negative value throws `ValueError`.

What breaks when you switch from `\ZipArchive`, within these calls:

- The class is not a subtype of `\ZipArchive`: a parameter typed `\ZipArchive` won't accept it.
- `open()`, `extractTo()` and `close()` return `true` or throw; `open()` is declared `bool`, not
  `bool|int`.
- Errors throw an exception from this package; `open()` never returns an error code.
- The archive path must not contain `*`, `?`, `[`, `]`, `\` or a line break: `unzip` treats its
  archive argument as a pattern, and `zipinfo` prints a line break as two lines. `open()` throws
  `UnsupportedException` for such a path.

## Exceptions

Every exception in this package extends `SorgeIt\SafeUnzip\Exception\UnzipException`, a
`RuntimeException`. At the time of the call, the class names the cause: the archive, the caller,
the tools, or the host. A `RefusedException` with `Reason::Target` names the target. A fixed
exception or a change during the run can throw a different class (see [Scope](#scope)).

| Exception | Cause | When |
|---|---|---|
| `RefusedException` | the archive (question 1); the target for L2 (question 2) | the archive or the target is outside the profile; `$reason` is the `Reason` of the rule |
| `InvalidArchiveException` | the archive | the file is not a ZIP archive, `zipinfo` prints something the grammar does not know, or `zipinfo` or `unzip` exits with a code for a data error in the archive that no rule covers |
| `ExtractionMismatchException` | the archive | `unzip` wrote or returned something other than what the listing says |
| `UnsupportedException` | the caller, or outside the package's goals | a flag for `open()` that writes, a list of entries for `extractTo()`, an archive path that `unzip` would read as a pattern, or a target with a scheme |
| `ToolException` | the host, or a tool of another release (question 3) | a tool is missing, is another release or cannot start; it hit the timeout, exited with a code that names no archive error, was killed by a signal, or printed more than its limit outside the listing; or `stream_select()` failed |
| `FilesystemException` | the host | the archive is missing, not a file, unreadable, cannot be resolved to an absolute path, or changed since it was hashed; a file system operation failed for a reason on the host |
| `ErrorException`, not from this package | the host | a PHP warning in a step after `open()` resolved the archive and `extractTo()` the target, such as a path below the target that `open_basedir` hides, when a handler turns warnings into exceptions, as Laravel does |

Unless something changed during the run or Info-ZIP itself was modified, `InvalidArchiveException`,
`ExtractionMismatchException` and `RefusedException` with any `$reason` other than
`Reason::Target` mean: do not accept this archive. Two of them mean less: L1 against free memory
means "not with this much memory"; N9 means "not into this target". If a step in `extractTo()`
throws and another process then makes removing the staging directory fail, the
`FilesystemException` from the removal is thrown, with the first exception as `getPrevious()`.

An argument with an invalid value, as listed below, or a call without an open archive throws
`ValueError`, not an exception from this package. As in the extension:

- `open()` with an empty path, or a path that contains a NUL byte.
- `extractTo()` with an empty path, or a path that contains a NUL byte.
- `extractTo()`, `close()` and `count()` without an open archive.

Beyond the extension, which has no such arguments:

- `new ZipArchive()` with a negative `$timeout`, or with a name in `$environment` that is empty, a
  number, or contains `=` or a NUL byte.
- `new Limits()` with a negative value.

## The profile "standard ZIP"

| Feature | Allowed |
|---|---|
| Compression methods | store (0), deflate (8) |
| Entry types | file, directory |
| Hosts | Unix (3), FAT (0), NTFS (11, as Info-ZIP counts it) |
| Names | ASCII `A-Z a-z 0-9 . _ - /`, each part up to 255 bytes, the path in the staging directory below `PATH_MAX` |
| Version needed to extract | up to 4.5 (ZIP64) |
| General purpose flags | bits 1 and 2 (deflate options), 3 (data descriptor, with deflate only), 11 (UTF-8) |
| Extra fields | ZIP64 (0x0001), NTFS times (0x000a), extended timestamp (0x5455), Unix IDs (0x5855, 0x7855, 0x7875) |
| Size | ZIP64 allowed |
| Archive comment | a single line of printable ASCII, such as the commit hash in a GitHub zipball |
| Archive | a single file, starting at offset 0 and ending with its end record, no password |

Everything outside this table is refused.

## Refusals

When an archive breaks several rules, the first rule in `Profile` order throws, not the one with the
lowest number: S11 and S15 while the listing is read, then S8, S10, S4, S5, S2, S7, S3, S6, N1, N3,
N8, S1, S12, S16, S14, S13, N5, S9, N6, N7, L1. So an LZMA entry throws S12, and `..\x.txt` throws
N3. An entry count above `Limits` throws L1 before the listing; in `extractTo()`, L2 comes before
N9. Each refusal has a case in `SorgeIt\SafeUnzip\Profile\Reason` whose value is the number shown
here. The ZipDiff types are those of the paper under [What a safe ZIP is](#what-a-safe-zip-is).

### Structure

| # | Refused | ZipDiff | Detected by |
|---|---|---|---|
| S1 | the central header, the local header and the data descriptor disagree on name, version needed, flags, method, time, date, CRC-32 or sizes; a stored entry with two different sizes | A1, A2, A5 | PHP |
| S2 | the end record counts a different number of entries than the central directory holds | C3 | `zipinfo` |
| S3 | entries overlap, or there are bytes between entries, the central directory and the end record | C1 | PHP |
| S4 | bytes before the archive (self-extracting) or after the end record | C2, C4 | `zipinfo`, PHP |
| S5 | no end record where `zipinfo` found it; a comment length beyond the end of the file | C2 | `zipinfo`, PHP |
| S6 | a Unicode path extra field (0x7075) or Unicode comment extra field (0x6375), in either header | A3 | `zipinfo`, PHP |
| S7 | flags outside the profile (encryption, patch data, a masked header); a stored entry with a data descriptor; a digital signature record | A5, C1, ISO/IEC 21320-1 | `zipinfo` (bit 0), PHP |
| S8 | a disk number other than 0 in an end record, a ZIP64 record or a header | ISO/IEC 21320-1 | `zipinfo`, PHP |
| S9 | a trailing `/` that disagrees with the entry type; a directory with content, a CRC-32 or more than 2 compressed bytes | A4 | `zipinfo` |
| S10 | the ZIP64 end record and the end record disagree; a ZIP64 record with extensible data; a field at its maximum without a ZIP64 value | C5 | PHP |
| S11 | an entry comment | — | `zipinfo` |
| S12 | a compression method other than store and deflate | — | `zipinfo` |
| S13 | an extra field outside the profile, the same one twice in a header, or one whose lengths don't add up | — | `zipinfo`, PHP |
| S14 | a host other than Unix, FAT and NTFS; Unix permission bits on a FAT or NTFS entry | — | `zipinfo` |
| S15 | an archive comment that is not a single line of printable ASCII | — | `zipinfo` |
| S16 | an entry that needs a version above 4.5 to extract, which `unzip` skips with exit code 81 | — | PHP |

### Names and types

| # | Refused | Why |
|---|---|---|
| N1 | an absolute name, an empty part, a `.` or `..` part | path traversal; ZipDiff B3; Go `ErrInsecurePath` |
| N3 | any character outside `A-Z a-z 0-9 . _ - /`, including a backslash, which Info-ZIP reads as `/` for host FAT | ZipDiff B2; Go `ErrInsecurePath` |
| N5 | a link, a device, a FIFO, a socket, a volume label | it writes outside the target, or has no content |
| N6 | the same name twice, even if only the case differs; a directory spelled two ways | ZipDiff B1, B4 |
| N7 | a file and a directory with the same name, such as `a` and `a/b` | ZipDiff B1 |
| N8 | a part longer than 255 bytes | the name comes from the archive; otherwise `unzip` fails in the staging directory |
| N9 | a name whose path in the staging directory reaches `PATH_MAX` (1024 bytes on macOS, 4096 on Linux) | as N8; `extractTo()` checks it because it needs the target |

N1, N3 and N8 check the names in both the central and the local header, as PHP reads them. N6 and
N7 compare the names in the listing: S1 runs before them and refuses an entry whose local name
differs from its central one.

### Limits and target

| # | Refused |
|---|---|
| L1 | more entries or bytes than `Limits` allows; a `zipinfo` listing larger than the entry count and `memory_limit` allow |
| L2 | a link in the target, a file where a directory goes, a directory where a file goes, a mount point; a target, or a directory in it, that cannot be written to or moved into; a target path too long for the staging directory |

The mount point check has no test: it would need a second file system below the target.

## Not supported

Each item is refused on purpose and has a fixture in the `Profile` suite. Support for any of them
is a feature request.

- **Self-extracting archives and bytes after the archive** (S4)
- **Unicode path and comment extra fields** (S6)
- **Encryption, patch data, masked headers, signed archives, and stored entries with a data descriptor** (S7)
- **Split and spanned archives** (S8)
- **Entry comments** (S11)
- **Compression methods other than store and deflate** (S12)
- **Extra fields other than ZIP64, NTFS times, extended timestamp and Unix IDs** (S13)
- **Host systems other than Unix, FAT and NTFS** (S14)
- **Archive comments with a line break or a byte outside printable ASCII** (S15)
- **Entries that need a version above 4.5 to extract**, such as LZMA or strong encryption (S16)
- **Names outside A-Z a-z 0-9 . _ - /** (N3)
- **Symbolic links, hard links, devices, FIFOs and sockets** (N5)

A zipball of a repository that contains a symbolic link is refused (N5), because `git archive`
writes the link as an entry. Stored entries with a data descriptor come from writers that stream
without compression (measured 2026-10-27): Python's `zipfile` on a stream with `ZIP_STORED`, Go's
`archive/zip` with `Store`, `bsdtar` with `compression=store`, `zip -0` into a pipe, and
`git archive -0` for a file above `core.bigFileThreshold`. With deflate, each of these produces an
archive that passes. Out of scope altogether: writing archives, reading single entries, and Windows.

## How it extracts

1. **Listing.** `zipinfo -h` checks the entry count against `Limits`, then `zipinfo -v` lists the
   archive. The output is parsed once, against a closed grammar, into value objects.
2. **Header reads.** PHP reads fixed header fields at the offsets from that listing, for what
   Info-ZIP does not report (see [Reads by PHP](#reads-by-php)).
3. **Profile.** Each rule is one class; the first rule that breaks throws `RefusedException`.
4. **Check pass.** `unzip -p` decompresses every file into a pipe. PHP splits the stream at the
   listed sizes and checks each CRC-32; the first byte beyond the total stops `unzip`. Nothing has
   been written yet, so a zip bomb never reaches the disk.
5. **Staging.** `unzip -q` writes into a new directory inside the target, on the same file system.
   The archive's SHA-256 must then still match the one from `open()`.
6. **Tree comparison.** The staged tree must hold exactly the listing, each file with its size, and
   no links. Each node gets its mode.
7. **Placement.** Every path in the target is checked for links first; then each entry moves into
   place with `rename()`. No entry reaches the target before step 6 passes. A target directory that
   the call created stays after an error, empty: the package removes nothing but its own staging
   directory.

The `unzip` binary comes first and is the source of truth: it lists, decompresses and writes. PHP
never decompresses an entry and never writes an extracted file. Two tests enforce this: one finds no
call in the sources to a function or class that decompresses or writes, and one extracts a deflate
entry in a PHP process where the zlib and bzip2 functions, the stream filters and the
`compress.zlib`, `compress.bzip2`, `zip`, `phar` and `php` stream wrappers are all disabled.

### Limits of the model

- **Info-ZIP reads the archive first.** `zipinfo` and `unzip` parse the untrusted bytes before any
  rule of this package runs, so a memory error in them happens before a refusal can. The release
  check sees UnZip 6.00, not the patches a system applied to it. Keep your system's `unzip` package
  up to date: of the systems measured, Debian and Alpine carry the patch for CVE-2019-13232, macOS
  does not (see [What a safe ZIP is](#what-a-safe-zip-is)).
- **The target is the caller's responsibility.** The checks in step 7 and each `rename()` work by
  path. A process that can write into the target, or into one of its parents, can plant a link
  between the check and the move, and an entry then goes through it. Such a process can also put a
  link where the staging directory is: removing it then follows the link and deletes whatever is
  behind it. Extract into a directory that only your process can write to. The same applies to the
  archive: see [Reads by PHP](#reads-by-php).
- **The move is not atomic.** Each new top-level directory moves in a single `rename()`; each file
  that goes into an existing directory moves on its own. When a `rename()` fails, for example on an
  immutable file, the entries already moved stay in the target, and the rest stays in the staging
  directory, which is removed. Extract into a new, empty directory when a partial tree must not
  happen.
- **The PHP process is the host's responsibility.** The package does not check `disable_functions`,
  `open_basedir` or `FD_SETSIZE` in advance. PHP throws `Error` for a function listed in
  `disable_functions`. A path hidden by `open_basedir` throws `FilesystemException` with PHP's
  warning for the archive and the target; a later step yields PHP's warning itself. `PATH_MAX`
  counts only against an archive name (N9) and the target (L2).
- **Changes during the run.** Another process that swaps the archive, plants a link in the target,
  or writes into the staging directory can make a check fail with any class: a changed archive can
  fail the CRC-32 of the check pass with `ExtractionMismatchException` before the SHA-256 is
  compared. Keep both the archive and the target in directories that only your process can write
  to.
- **Free memory.** L1 compares the listing with the memory free under `memory_limit` when `open()`
  runs, so the same archive can pass in a fresh process and be refused in one that already holds a
  lot.
- **A modified Info-ZIP.** The release check reads the first line of `unzip -v` and `zipinfo`, not
  anything a patch changes beyond it (see [Scope](#scope)).
- **Memory.** The output of `zipinfo -v` may be up to 65,536 + 65,535 bytes plus 16,384 bytes per
  entry, and at most a sixth of the memory free under `memory_limit`; beyond that, `open()` throws
  `RefusedException` (L1). Parsing takes up to 4.6 times the output (measured with 5,000 entries on
  macOS, about 1.3 KB of output per entry). Short lines cost more: before parsing, 6 times the
  output plus 32 bytes per line must fit, otherwise `open()` throws `RefusedException` (L1).
  Without this check, a 9 MB archive with entry comments made of line breaks kills PHP with a fatal
  error under a `memory_limit` of 128 MB.
- **Cost.** For an archive with entries, PHP reads the whole file twice to hash it: once in
  `open()` and once in `extractTo()`; for an empty archive, once. `unzip -p` and `unzip -q` each
  decompress every entry. PHP computes the CRC-32 of each file once, from the `unzip -p` stream.
  `\ZipArchive::extractTo()` decompresses each entry once. The two `zipinfo` runs read the end
  record and the central directory, not the entry data.
- **Time and disk.** `Limits` counts the extracted bytes, not the compressed ones, so a large file
  takes its time, up to the timeout. A full disk stops `unzip` in the staging directory with
  `ToolException`, and no entry reaches the target.
- **The end of a deflate stream (ZipDiff C1).** A deflate stream can end before its entry's
  compressed size, with other bytes in the gap, such as a local header. `unzip` reads the stream to
  its end and skips the rest, and so does this package; neither `unzip -t` nor `zipinfo` reports it
  (measured 2026-10-27 on macOS). For an entry with a data descriptor, a streaming parser such as
  Java's `ZipInputStream` looks for the descriptor right after the stream and reads those bytes.
  PHP would have to decompress to find the end, so this package does not check it. Read each
  archive with one parser only: this one.

### Modes

An entry from a Unix host keeps its permission bits, minus write access for group and others; the
set-user-id, set-group-id and sticky bits are dropped. An entry without Unix bits, such as one from
a FAT host, gets 0644, or 0755 for a directory. A directory is always accessible to its owner. The
umask applies.

## Reads by PHP

PHP reads bytes of the archive in a single class, `Archive\Headers`, and only fixed fields at
offsets that `zipinfo -v` reports. It never searches for a signature. So PHP has no parser of its
own, and `zipinfo` and PHP cannot see two different archives in one file. Where parsers could read
one file in two ways, a rule refuses it: S1, S3, S4, S5 and S10, each with a fixture. Each read
covers something Info-ZIP does not, as measured on 2026-10-27:

| Read | Fields | Why `zipinfo` is not enough |
|---|---|---|
| each central header, from the central directory offset | version made by, version needed, flags, method, CRC-32, sizes, disk, external attributes, offset, name, extra field | `zipinfo -v` shows only flag bit 0 (S7); the local header is compared against it (S1), and its disk against the listing (S8) |
| the 4 bytes after the last central header | signature | a digital signature record (S7) |
| each local header at its offset | version needed, flags, method, time, date, CRC-32, sizes, name, extra field | `zipinfo` shows only the central header, while `unzip` uses the local one (S1, A1, A5) |
| the data descriptor after the data, where flag bit 3 is set | CRC-32, sizes | a streaming reader uses them (S1, C1) |
| the end records at the offset `zipinfo` reports, the ZIP64 locator before the end record, and the ZIP64 end record at the locator's offset | counts, sizes, offsets, disks, comment | `zipinfo` silently picks one of two disagreeing records (S10) |
| the whole file, in `open()` before the listing, and in `extractTo()` after `unzip -q` | SHA-256 | a safety net: a file rewritten in between, for example by mistake, is refused |

Walking the local headers also yields the exact layout (S3). `zipinfo -v` does report bytes before
an entry, but it estimates the length of the local header from the central extra field: with an
extended timestamp holding two times in the central header, it reports a 4-byte overlap that isn't
there.

The SHA-256 is a safety net, not a defence against an attacker. It refuses a file that was
rewritten by mistake between the reads, such as a download that writes the same name twice. Each
tool opens the archive by path, so a process that swaps the file on purpose can swap it back before
a comparison. Like the target, the archive is the caller's responsibility: keep it in a directory
that only your process can write to.

## Scope

This section is for contributors: it decides where a check belongs. The four fixed exceptions apply
first, then three questions in order. The first "yes" decides; a cause with no "yes" belongs to the
host.

Three terms:

- **Cause:** what produces the error, not the step that notices it. An exit code, a failed file
  system operation or a failed comparison can have its cause in the archive.
- **At the call:** the archive as it is when `open()` starts, and the target as it is when
  `extractTo()` starts, not counting the package's staging directory.
- **During the run:** any change by another process after that, until `extractTo()` returns. It
  belongs to the host and can throw any class.

| Question | Answer | Duty |
|---|---|---|
| **1. Archive.** Is the cause in the bytes of the archive, alone or against a limit? A limit is `Limits`, `memory_limit`, 255 bytes per part, `PATH_MAX` or the length of the target path; the timeout is not. | **in**; **out** where PHP cannot see the cause without decompressing | In: a rule with a fixture; `RefusedException` where a rule names the cause, otherwise `InvalidArchiveException` or `ExtractionMismatchException`. Out: listed under [Limits of the model](#limits-of-the-model). |
| **2. Caller.** Is the cause an argument's value, or the target? | **in** | A value: checked at the start of the call, before any tool runs, with `ValueError` or `UnsupportedException`. The target: checked before an entry moves into it, with `RefusedException` and `Reason::Target` (L2). |
| **3. Tools.** Is the cause which binary runs, or a variable that Info-ZIP reads, no matter who sets it? | **in** | The package pins the binary and each such variable, as listed under [In scope](#in-scope). Another release throws `ToolException`. |
| **Otherwise: host.** | **out** | No check in advance. A failed step throws `ToolException`, `FilesystemException`, or PHP's own `Error` or warning. Outside the fixed exceptions, a host cause at the call never throws an archive exception. |

How to apply them:

- A path argument is a value with its links resolved, as the tools receive it. A path that cannot
  be resolved belongs to the host, after the scheme check and before the pattern check:
  `FilesystemException`.
- What a path points to in the file system belongs to the host; only the state of the target
  belongs to the caller.
- A question-3 variable in `$environment` is not an argument error. A name that is empty, a number,
  or contains `=` or a NUL byte is.
- Where several rules break, the first in `Profile` order throws (see [Refusals](#refusals)).

The four fixed exceptions:

1. **An Info-ZIP modified by the host,** through a patch or a preloaded library: a tool of the
   right release that prints something the grammar does not know. The output looks like an unknown
   archive: `InvalidArchiveException`.
2. **A safety net:** if no check failed earlier, `extractTo()` compares the SHA-256 from `open()`
   after `unzip -q`. It refuses a file rewritten during the run with `FilesystemException`. It does
   not protect against a deliberate swap.
3. **The extension's behaviour:** `clone` throws `Error`, `serialize()` and `unserialize()` throw
   `Exception`, and a call without an open archive throws `ValueError`.
4. **Outside the package's goals:** an `open()` flag other than `RDONLY` and `CHECKCONS`, and a list
   for `extractTo()`: `UnsupportedException` at the start of the call. Windows as the host system:
   no check, no duty; an archive written on Windows is within the profile.

A change that adds or removes a check names its question or exception. A case that none of them
answers changes this section first.

### In scope

- **Question 1:**
  - S1 to S16; N1 and N3, which covers backslashes; N5 to N7.
  - N8: a part above 255 bytes. N9: a name whose path in the staging directory reaches the
    system's `PATH_MAX`.
  - L1: the entries, the bytes, and the listing against the memory free under `memory_limit`.
  - Exit codes for a data error: 2, 3, 11 and 51 from `zipinfo`; 2, 3 and 51 from `unzip -p`. Exit
    11 means an end record on another disk: S8. Exit 51 means a name beyond the end of the file,
    before any rule can read it: `InvalidArchiveException` (measured 2026-10-29).
  - The sizes and CRC-32 values of the check pass; the tree comparison.
- **Question 2, a value:**
  - `ValueError`: an empty path or one with a NUL byte, an empty target, a negative timeout or
    limit, a name in `$environment` as described above.
  - `UnsupportedException`: an archive path that contains a pattern once resolved; a target with a
    scheme, such as `file://`, `phar://` or `data:`.
- **Question 2, the target (L2):** a link, file or directory in the way of an entry; a mount point;
  a target, or a directory in it, that cannot be written to or moved into; a target whose path,
  with only the package's own names added, reaches `PATH_MAX`. With a name from the archive, N9
  applies.
- **Question 3:**
  - The binary: its release is checked on the first `open()` of each object, and that same binary
    runs every time.
  - Set: `LC_ALL=C` for both tools, which overrides `LANG` and `LC_*`; `TZ=UTC` for `zipinfo`.
  - Kept: the process's `TZ` for `unzip`, for the DOS times.
  - Removed: `UNZIP`, `UNZIPOPT`, `ZIPINFO`, `ZIPINFOOPT`, and every variable whose name `getenv()`
    returns as a number.

### Out of scope

- **Question 1, not visible:** the part of ZipDiff C1 that no rule covers, a deflate stream that
  ends before its entry's compressed size; an Info-ZIP crash on crafted bytes (`ToolException`).
- **Host, at the call:** `disable_functions`, `open_basedir`, `FD_SETSIZE`, the working directory,
  every variable that question 3 does not name, such as `LD_PRELOAD`; free disk space; system
  memory, and so a tool killed by a signal; the timeout, even for a large valid archive; an archive
  path that is missing or not a file.
- **Host, during the run:** a swapped archive (TOCTOU); an archive deleted after its path was
  resolved; a link in the target after `extractTo()` started; a link in place of the staging
  directory; another process writing into the staging directory.

## What a safe ZIP is

No standard called "safe ZIP" turned up (research on 2026-10-27, web pages and the paper only).
Three sources together draw the line for this package:

1. **ISO/IEC 21320-1:2015, Document Container File — Core**: a subset of ZIP (APPNOTE 6.3.3). Store
   or deflate only; no encryption, no digital signatures, no patch data; a single volume, not
   segmented; names should be UTF-8. Source: Library of Congress, fdd000361, read 2026-10-27.
2. **Go's `archive/zip`, `ErrInsecurePath`** (Go 1.20, issue 55356, read 2026-10-27): a name is
   insecure if it is absolute, contains a `..` part or a backslash.
3. **"My ZIP isn't your ZIP: Identifying and Exploiting Semantic Gaps Between ZIP Parsers"** by
   Yufan You, Jianjun Chen, Qi Wang and Haixin Duan, USENIX Security 2025, read 2026-10-27. Their
   differential fuzzer ZipDiff found ambiguities in 1,221 of 1,225 pairs among 50 parsers. It names
   14 types:
   - A, redundant metadata: A1 compression method, A2 file size, A3 file name, A4 fake directory,
     A5 fake encryption;
   - B, path processing: B1 duplicate files, B2 invalid characters, B3 path canonicalization, B4 case;
   - C, structure: C1 streaming parsing, C2 end record selection, C3 central directory count, C4
     offsets, C5 ZIP64 end record.

   The paper names seven mitigations: use the same parser; on-access scanning; normalize the ZIP;
   identify ambiguous patterns; combine several parsers; fix unique parser behaviour; a better
   format. This package takes "identify ambiguous patterns": a rule refuses each type except C1
   with deflate (see [Limits of the model](#limits-of-the-model)). Each type has a fixture in the
   `Profile` suite. The paper's Table 4, read from the image, counts 6 types on which Info-ZIP 6.0
   and libzip 1.10.1 disagree; a caller who checks with `ZipArchive` and extracts with this package
   meets exactly those. Results of the paper: CVE-2024-24789 (Go), CVE-2024-7788 (LibreOffice),
   CVE-2024-38807 (Spring Boot), and stricter checks in libzip.

Further sources:

- **SEI CERT IDS04-J**, "Safely extract files from ZipInputStream": check each name against the
  target, and do not decompress beyond a size limit (N1, L1).
- **CVE-2019-13232**, David Fifield, "A better zip bomb" (USENIX WOOT 2019): UnZip 6.0 mishandles
  overlapping entries. Debian (bullseye 6.0-26+deb11u1, bookworm 6.0-28, trixie 6.0-29) and Alpine
  (6.0-r16) carry a patch; macOS's `unzip` does not, as measured on 2026-10-27. So S3 does not rely
  on the system.
- **libzip's `CHECKCONS`** (`lib/zip_open.c`, read 2026-10-27): it compares the local and central
  headers on version, method, time, date, name, CRC-32 and sizes, but not on flags (that comparison
  is behind `#if 0`), and it accepts overlapping entries and a self-extracting prefix. This package
  always checks what `CHECKCONS` checks, and adds flags, overlaps and prefixes.
- **`bsdtar`** (libarchive 3.7.4 on macOS, measured 2026-10-27), rejected as the engine: it strips a
  leading `/` and writes the file anyway, leaves partial output behind after an error, and reads a
  tar file whose first name starts with `PK\x03\x04` as tar.
- Not read: Gynvael Coldwind's page lists no pitfalls, only slides and a video; David Fifield's page
  on Mozilla bug 1534483 refused the request with HTTP 403 (both tried on 2026-10-27).

## Measurements

On macOS, `UnZip 6.00 of 20 April 2009, by Info-ZIP, with modifications by Apple Inc.`, 2026-10-27:

- `git archive --format=zip` and a GitHub zipball write host FAT (`-rw----  0.0 fat`), the extended
  timestamp 0x5455, no data descriptor, and the commit as the archive comment; the top directory
  is named after the tag object. A symbolic link becomes an entry with host Unix.
- `zipinfo -v` shows only the central header: a method or flag that differs in the local header
  passes `unzip -t` without a message (S1). An entry inside the data of another passes `unzip -t`
  with "No errors detected" (S3).
- Info-ZIP counts NTFS as host 11, and calls host 10 (NTFS according to APPNOTE) TOPS-20.
- For host FAT, `zipinfo -v` shows the upper 24 bits of the external attributes as
  "non-MSDOS external file attributes".
- `zipinfo` prints warnings and errors to stderr; after an error it stops without its final blank
  line.
- In the fifth field of `zipinfo`, `x` means an extra field and no data descriptor, `l` a data
  descriptor and no extra field, `X` both (man page). So the `tx` from `git archive` means no data
  descriptor.
- `zipinfo` and `unzip -t` exit with 3 for an end record that counts more entries than exist (S2),
  with 1 for bytes before the archive when the offsets were not adjusted (S4), and with 0 for a
  local header that differs only in method or flags (S1). For two disagreeing end records they exit
  with 2 or 3 (S10).
- With a ZIP64 end record, `zipinfo -v`'s "Actual end-cent-dir record offset" is the offset of the
  ZIP64 record. So the archive ends at that offset + 56 + 20 + 22 + the comment length (S4).

On Debian (PHP 8.4, `UnZip 6.00 … by Debian`) and Alpine (PHP 8.5, the `unzip` package), in Docker,
on 2026-10-27, the whole suite passes. One difference from macOS: their `zipinfo` lists the name
from a Unicode path field (0x7075) instead of the name field, so S6 runs before every rule on names.

## Tests

```sh
composer test           # pint, rector, PHPStan level max, type coverage 100 %, every suite
composer test:coverage  # line coverage 100 %, with pcov; a CI job
```

Line coverage skips one range, marked `@codeCoverageIgnoreStart` with its reason on the lines
before it: the exception in `Filesystem::done()`, for a file system operation that only another
process or the file system itself can make fail, such as a `rename()` after its checks passed. A
test in `Extraction` exercises the code in that range, and `phpunit.xml` excludes no file.

The CI runs the suite on Ubuntu and macOS, and in Docker on Debian (PHP 8.4, not as root) and
Alpine (PHP 8.5, x64 and arm64). Every action is pinned to a commit and every image to a digest.

| Suite | Checks |
|---|---|
| `Interface` | the four methods with `\ZipArchive`'s parameters and a compatible return type, the two flags, the properties, and the extension's errors |
| `PhpSrc` | the php-src and libzip fixtures, a 7-Zip archive, and archives written by ext-zip: extracted as by the extension, or refused with their `Reason`; the php-src fixtures, compared with `php-src.lock` |
| `Profile` | each rule: archives that break it, refused with their `Reason` and message, and archives within the profile; the `zipinfo -v` grammar |
| `Extraction` | attacks on the target, links, staging, the target left as before a failed check, a zip bomb, and the sources |
| `Tools` | missing tools, timeouts, output limits, the environment |
| `Acceptance` | a GitHub zipball and a ZIP with the structure of Chrome for Testing, extracted and compared |

The archives for `Profile` are built byte by byte at test time by `tests/Support/ZipFixture.php`;
none of them is in the repository.

## Development

Problems come as a pull request that fixes them, not as an issue. How to run the package's checks:
[CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report vulnerabilities privately through GitHub. See [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE). The fixtures keep the licenses of their origin, see
[tests/Fixtures/README.md](tests/Fixtures/README.md).

## About the author

**Stefan Sorge** · PHP expert, builder, agentic engineer.
25 years of PHP and tech for 40+ startups. I build tools that humans and AI agents
read and act on the same way.

Also by me: [phpunit-pest-html-assertions](https://github.com/sorge-it/phpunit-pest-html-assertions),
HTML assertions for PHPUnit and Pest that keep AI coding agents from checking markup as a string.

[GitHub](https://github.com/sorge-it) · [LinkedIn](https://www.linkedin.com/in/stefansorge/)
