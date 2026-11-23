# Contributing

Pull requests are welcome. The issue tracker is turned off, so that every report comes with an
archive that reproduces it. Found a bug? A pull request with a failing test is the fastest way to
a fix; a fix is welcome but not required. Vulnerabilities are the exception: report them
privately, see [SECURITY.md](SECURITY.md).

## Setup

`composer install` needs `ext-zip`, unlike the package itself: the `Interface`, `PhpSrc` and
`Acceptance` suites compare with `\ZipArchive`. In the official PHP images on Debian, add it with
`apt-get update && apt-get install -y git libzip-dev unzip` and `docker-php-ext-install zip`. Line
coverage needs pcov: `pecl install pcov` and `docker-php-ext-enable pcov`. The code must run on
PHP 8.4, on Linux and macOS.

```sh
git clone https://github.com/sorge-it/safe-unzip.git
cd safe-unzip
composer install
```

## Tests

```sh
composer test           # Pint and Rector as a dry run, PHPStan at level max, type coverage 100%,
                        # every suite; each tool checks tests/ as well
composer test:coverage  # line coverage 100%, with pcov
composer lint           # Rector, then Pint, both changing files
```

Run the tests as a user other than root. Root reads a file of mode 0000 and writes into a
directory of mode 0555, so three tests skip themselves; line coverage then reaches 99.6%, and
`composer test:coverage` fails.

Line coverage skips one range, in `Filesystem::done()`: the exception for a file system operation
that only another process or the file system itself can make fail, such as a `rename()` after its
checks passed. No test reaches that line. A test in `Extraction` checks that it is the only range,
that its reason stands on the lines before it, and that `phpunit.xml` excludes no file.

| Suite | Checks |
|---|---|
| `Interface` | the four methods with `\ZipArchive`'s parameters and a compatible return type, the two flags, the properties, and the extension's errors |
| `PhpSrc` | the php-src and libzip fixtures, a 7-Zip archive, and archives written by ext-zip: extracted as by the extension, or refused with their `Reason`; the php-src fixtures, compared with `php-src.lock` |
| `Profile` | each rule: archives that break it, refused with their `Reason` and message, and archives within the profile; the `zipinfo -v` grammar |
| `Extraction` | attacks on the target, links, staging, the target left as before a failed check, a zip bomb, and the sources |
| `Tools` | missing tools, timeouts, output limits, the environment |
| `Acceptance` | a GitHub zipball and a ZIP with the structure of `chrome-linux64` from Chrome for Testing, extracted and compared |
| `Readme` | each PHP example of the README, run as the whole file it shows, including the upload and the zipball; the `Reason` case of each rule in [Refusals](https://github.com/sorge-it/safe-unzip#refusals) |
| `Scope` | a guard for each out-of-scope row of [Scope](https://github.com/sorge-it/safe-unzip#scope), in the order of the table |

`tests/Support/ZipFixture.php` builds the archives for `Profile` byte by byte at test time; none
of them is in the repository. Each field can be set on its own, so a test can break one rule:
`ZipFixture::new()->file('a b.txt', "a\n")->write($path)` writes an archive that N3 refuses.

## Scope

Read [When to use it and when not to](https://github.com/sorge-it/safe-unzip#when-to-use-it-and-when-not-to)
and [Scope](https://github.com/sorge-it/safe-unzip#scope) in the README first. A feature outside
the scope, such as writing archives or running on Windows, will not be merged. Each out-of-scope
row of Scope has its guard in
[`tests/Scope/out.php`](https://github.com/sorge-it/safe-unzip/blob/main/tests/Scope/out.php): one
or more patterns that `src` must not match, or the reason the row has none. A new out-of-scope row
comes with its guard.

The README's Scope decides whether a behavior is in scope: its table for each behavior it names,
its question for any other. For a behavior in scope, the questions below decide where its cause
belongs, whether the package checks it in advance, and the exception it throws. Their **in** and
**out** mean a check in advance or none, not the scope of the README. The four special cases apply
first, then three questions in order. The first "yes" decides; a cause with no "yes" belongs to the
host. An upload is an archive like a download: the same questions apply, and the caller sets the
timeout and the limits.

Three terms:

- **Cause:** what produces the error, not the step that notices it. An exit code, a failed file
  system operation or a failed comparison can have its cause in the archive.
- **At the call:** the archive as it is when `open()` starts, and the target as it is when
  `extractTo()` starts, not counting the package's staging directory.
- **During the run:** any change by another process after that, until `extractTo()` returns. It
  belongs to the host and can throw any class.

| Question | Answer | Duty |
|---|---|---|
| **1. Archive.** Is the cause in the bytes of the archive, alone or against a limit? A limit is `Limits`, `memory_limit`, 255 bytes per part, `PATH_MAX` or the length of the target path; the timeout is not. | **in**; **out** where Info-ZIP does not report the cause and PHP cannot see it without decompressing an entry itself | In: a rule with a fixture; `RefusedException` where a rule names the cause, otherwise `InvalidArchiveException` or `ExtractionMismatchException`. Out: listed under [What it does not protect against](https://github.com/sorge-it/safe-unzip#what-it-does-not-protect-against). |
| **2. Caller.** Is the cause an argument's value, or the target? | **in** | A value: checked at the start of the call, before any tool runs, with `ValueError` or `UnsupportedException`. The target: checked before an entry moves into it, with `RefusedException` and `Reason::Target` (L2). |
| **3. Tools.** Is the cause which binary runs, or a variable that Info-ZIP reads, no matter who sets it? | **in** | The package pins the binary and each such variable, as listed under [Checked in advance](#checked-in-advance). A different release throws `ToolException`. |
| **Otherwise: host.** | **out** | No duty to check in advance. A failed step throws `ToolException`, `FilesystemException`, or PHP's own `Error` or warning. Outside the special cases, a host cause at the call never throws `RefusedException`, `InvalidArchiveException` or `ExtractionMismatchException`. |

How to apply them:

- A path argument is a value with its links resolved, as the tools receive it. A path that cannot
  be resolved belongs to the host, after the scheme check and before the pattern check:
  `FilesystemException`.
- What a path points to in the file system belongs to the host; only the state of the target
  belongs to the caller.
- A question-3 variable in `$environment` is not an argument error. A name that is empty, a number,
  or contains `=` or a NUL byte is.
- Where several rules break, the first one checked throws (see
  [Refusals](https://github.com/sorge-it/safe-unzip#refusals)).

The four special cases:

1. **An Info-ZIP modified by the host,** through a patch or a preloaded library: a tool of the
   right release that prints something the grammar does not know. The output looks like an unknown
   archive: `InvalidArchiveException`.
2. **The SHA-256 check:** if no check failed earlier, `extractTo()` compares the SHA-256 from
   `open()` after `unzip -q`. It refuses a file rewritten during the run with `FilesystemException`.
   It does not protect against a deliberate swap.
3. **The object's state:** as in the extension, `clone` throws `Error`, and a call without an open
   archive throws `ValueError`. `serialize()` and `unserialize()` throw `Exception`, beyond the
   extension: an object without the checks of its `open()` must not be restored.
4. **Outside the package's goals:** an `open()` flag other than `RDONLY` and `CHECKCONS`, and a list
   for `extractTo()`: `UnsupportedException` at the start of the call. Windows as the host system:
   no check, no duty; an archive written on Windows is judged by the profile like any other.

A change that adds or removes a check names its question or special case. A case that none of them
answers changes this section first.

### Checked in advance

- **Question 1:**
  - S1 to S16; N1 and N3, which covers backslashes; N5 to N7.
  - N8: a part above 255 bytes. N9: a name whose path in the staging directory reaches the
    system's `PATH_MAX`.
  - L1: the entries, the bytes, and the listing against the memory free under `memory_limit`.
  - Exit codes for a data error, read after the run: 2, 3, 9, 11 and 51 from `zipinfo`; 2, 3 and
    51 from `unzip -p`. Exit 11 with "multi-disk archive" in the output means an end record on
    another disk: S8; without it, `InvalidArchiveException`. Exit 51 means a name beyond the end of
    the file, before any rule can read it: `InvalidArchiveException` (measured 2026-10-29).
  - The sizes and CRC-32 values of the check pass; the tree comparison.
- **Question 2, a value:**
  - `ValueError`: an empty path or one with a NUL byte, an empty target, a negative timeout or
    limit, a name in `$environment` as described above.
  - `UnsupportedException`: an archive path that contains a pattern once resolved; a target with a
    scheme, such as `file://`, `phar://` or `data:`.
- **Question 2, the target (L2):** a link, file or directory in the way of an entry; a mount point;
  a target directory, or a directory in it, that cannot be written to or moved into; a target
  whose path, with only the package's own names added, reaches `PATH_MAX`. With a name from the
  archive, N9 applies. A target that is a file, or is missing and cannot be created, belongs to
  the host.
- **Question 3,** which includes the variables that the patches of the systems under
  [Compatibility](https://github.com/sorge-it/safe-unzip#compatibility) add to Info-ZIP:
  - The binary: its release is checked on the first `open()` of each object, and that same binary
    runs every time.
  - Set: `LC_ALL=C` for both tools, which overrides `LANG` and `LC_*`; `TZ=UTC` for `zipinfo`.
  - Kept: the process's `TZ` for `unzip`, for the DOS times.
  - Removed: `UNZIP`, `UNZIPOPT`, `ZIPINFO`, `ZIPINFOOPT`, `UNZIP_DISABLE_ZIPBOMB_DETECTION`, and
    every variable whose name `getenv()` returns as a number. `UNZIP_DISABLE_ZIPBOMB_DETECTION`
    turns off the overlap check of `unzip` itself, not S3. It comes from Fedora's
    [`unzip-zipbomb-switch.patch`](https://src.fedoraproject.org/rpms/unzip/blob/rawhide/f/unzip-zipbomb-switch.patch)
    (Jakub Martisko, 2021-11-30). Alpine's `unzip` reads it too (measured 2026-11-17); Debian's
    does not.
- **Host, at the call, so that the exception class is right:** an archive path that cannot be
  resolved, does not resolve to an absolute path, or is not a file; a target that cannot be made or
  does not resolve to an absolute path: `FilesystemException` before any tool runs on it. Without
  the check, `zipinfo` on a directory exits with 9, which reads as a data error.

### Not checked in advance

The cause cannot be seen or belongs to the host. Each item below, or the table above, names what a
failed step throws; during the run, a step can throw any class.

- **Question 1, not visible:** the part of ZipDiff C1 that no rule covers, a deflate stream that
  ends before its entry's compressed size; a memory error in Info-ZIP on crafted bytes that no rule
  refuses first, such as one in `zipinfo`, which reads the archive before any rule runs
  (a crash: `ToolException`).
- **Host, at the call:** `disable_functions`, `open_basedir`, `FD_SETSIZE`, every variable that
  question 3 does not name, such as `LD_PRELOAD`; free disk space; system memory, and so a tool
  killed by a signal; a run that hits the timeout, even for a large valid archive.
- **Host, during the run:** a swapped archive (TOCTOU); an archive deleted after its path was
  resolved; a link in the target after `extractTo()` started; a link in place of the staging
  directory; another process writing into the staging directory.

## Pull requests

A pull request for a bug has a test that fails, with the archive built by
`tests/Support/ZipFixture.php` where possible, and a description of what went wrong, with the
output of `unzip -v` and `php -v`. A fix is optional; if you include one, say why it works. If you
do not know how to fix it, describe the problem to a coding agent and let it draft the pull
request. Review the change and run the tests before you open it.

A pull request for a feature or a check first shows that the README's
[Scope](https://github.com/sorge-it/safe-unzip#scope) puts it in scope: its row in the table, or
else its question. A pull request for a check also names the question (1, 2 or 3) or the special
case under [Scope](#scope) above that the check answers. A check for a cause in the archive, the
caller or the tools belongs in the package; a check for a cause on the host does not. A new
refusal comes with its `Reason` case, a rule class, fixtures that break the rule and fixtures that
pass it, and a row in [Refusals](https://github.com/sorge-it/safe-unzip#refusals).

A pull request gets reviewed once it is ready for review and `composer test` passes in CI; in a bug
report without a fix, only its new test may fail. Inactive
drafts may be closed. Pull requests opened in bulk are closed without review.
