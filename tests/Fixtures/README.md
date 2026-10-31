# Fixtures

Byte-for-byte copies; each file keeps the license of its origin. The archives for the rules of the
profile are not here: `tests/Support/ZipFixture.php` builds them at test time.

| File or directory | Origin | License |
|---|---|---|
| `github-format.zip` | the zipball of [sorge-it/phpunit-pest-html-assertions](https://github.com/sorge-it/phpunit-pest-html-assertions) at tag `v1.4.2` (tag object `4d97334`, commit `8fc48fe334e5e97e9aa2c62b738760b33976cf16`), from `GET /repos/sorge-it/phpunit-pest-html-assertions/zipball/v1.4.2`, downloaded 2026-10-27; SHA-256 `2c718dbd4f7c035999817d852357e200b4aec3869fdeab1e213ffdc37587c27b` | MIT, see `LICENSE.md` in the archive |
| `php-src/` | the archives of [php/php-src](https://github.com/php/php-src) `ext/zip/tests`, at commit `eefa52a5` (2026-10-26); [`php-src.lock`](php-src.lock) lists the git blob of every file in `ext/zip/tests`, also of those not copied | BSD-3-Clause, see `php-src/LICENSE` |
| `built/` | this package: the script beside each file builds it, where no PHP code writes such an archive | MIT, as the package |
| `libzip/` | [nih-at/libzip](https://github.com/nih-at/libzip) `regress/data`, the same blobs as at `371981bec38c7fd0617b93f8a4021e140b9ebd95` (2026-10-25) | BSD-3-Clause, see `libzip/LICENSE` |

GitHub makes a zipball with `git archive` and does not promise stable bytes: a new download can
differ. So the acceptance test compares the unpacked tree, and the SHA-256 pins this copy.
