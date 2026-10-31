# Changelog

## 1.0.1 - 2026-10-31

The README states the parity with php-src `ext/zip/tests` in its own section, and a badge shows
its check: each archive there extracts as with `\ZipArchive`, or SafeUnzip writes nothing. The code
of the package does not change.

## 1.0.0 - 2026-10-30

The first public release. SafeUnzip extracts a whole standard ZIP file with Info-ZIP's `unzip`
and `zipinfo`, behind four methods of `ZipArchive`: `open()`, `extractTo()`, `close()` and
`count()`. The README states what the allow list refuses and why. The 0.x releases were private.
