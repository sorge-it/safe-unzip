# Changelog

## [1.0.2] - Unreleased

### Changed

- Messages changed. They say "extract" instead of "unpack", such as "Nothing was extracted.", put
  the title of a rule in parentheses after its number, such as `N3 (A name has a character …):`,
  put `unzip` and `zipinfo` in backticks, and are reworded in places. `Reason::title()` changed for
  S2 and N9 (reworded), N6 ("even when"), L1 (it names the free memory too) and L2 (shorter). Code
  that compares messages as strings must be updated; compare the exception class and `$reason`
  instead.

### Fixed

- The tools run without `UNZIP_DISABLE_ZIPBOMB_DETECTION`. The variable comes from Fedora's
  [`unzip-zipbomb-switch.patch`](https://src.fedoraproject.org/rpms/unzip/blob/rawhide/f/unzip-zipbomb-switch.patch)
  and turns off the overlap check of `unzip` itself, not S3; Alpine's `unzip` reads it too
  (measured 2026-11-17).
- `open()` with a flag other than `RDONLY` and `CHECKCONS` now closes the archive that was open
  before, as the extension does.
- A test no longer leaves a directory tree beyond `PATH_MAX` in `.tmp/tests`, which stopped the
  next `composer test` in Pint.

### Added

- `open()`, `extractTo()`, `close()` and `count()` name their exceptions in `@throws`.
- Tests for devices and sockets (N5), the modes 04755, 02755, 0666 and 01777, `count()` without an
  open archive, and the `Readme` suite, which runs each PHP example of the README.

### Documentation

- The demo's zip bomb holds its data once: 11 KiB instead of 201 KiB extract to 200 MiB. In 1.0.1,
  all 20 central headers already pointed to the first local header, and the 19 other copies of the
  data went unused. The social preview says "The executable bit kept." instead of "Unix modes
  kept.", and "11 KiB" instead of "202 KB".
- The README is restructured: a table of contents, the installation, the quick start, examples
  for an upload and a GitHub zipball, a section "Rules" with the limits and the memory check, the
  reference with a section on `Reason`, and a section on support.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md` move to `.github/`, with a pull request
  template. `CONTRIBUTING.md` holds the setup, the tests, the scope of a check and the rules for a
  pull request.
- `serialize()` and `unserialize()` still throw. The docblock no longer says that the extension
  does the same: it serializes its properties.
- The examples use the alias `SafeZipArchive`, tell the target (L2) apart from the archive, and do
  not pass `CHECKCONS`. The upload example sets a timeout.
- The scope says that an upload is an archive like a download, for which the caller sets the
  timeout and the limits, and that question 3 includes the variables that the patches of the
  tested systems add to Info-ZIP.
- Corrected facts: `extractTo('')` and `serialize()` in the extension, the macOS CVEs, hard links,
  the order of the rules and of L2 and N9, `CHECKCONS` up to libzip 1.12.1, libzip 1.11, Go's
  `ErrInsecurePath`, exit codes 11 and 81, Composer on Alpine, the Debian versions, which messages
  name an entry, and which permission bits an entry keeps. The entry for 1.0.1 says what its badge
  shows: whether the lock of the php-src fixtures still matches php-src.
- `composer.json` describes the package in the words of the README.

## [1.0.1] - 2026-10-31

The README states the parity with php-src `ext/zip/tests` in its own section, and a badge shows
whether the lock of the copied php-src fixtures still matches php-src. The code of the package does
not change.

## [1.0.0] - 2026-10-30

The first public release. SafeUnzip extracts a whole standard ZIP file with Info-ZIP's `unzip`
and `zipinfo`, behind four methods of `ZipArchive`: `open()`, `extractTo()`, `close()` and
`count()`. The README states what the allow list refuses and why. The 0.x releases were private.

[1.0.2]: https://github.com/sorge-it/safe-unzip/compare/v1.0.1...main
[1.0.1]: https://github.com/sorge-it/safe-unzip/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sorge-it/safe-unzip/releases/tag/v1.0.0
