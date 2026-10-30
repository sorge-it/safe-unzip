# Contributing

Pull requests are welcome. Issues are closed: report a problem as a pull request that fixes it.
Vulnerabilities are the exception: report them privately, see [SECURITY.md](SECURITY.md).

## A problem

Open a pull request with:

- a test that fails without the change, with the archive built by `tests/Support/ZipFixture.php`
  where possible;
- the change;
- a description of what went wrong and why the change fixes it, with the output of `unzip -v` and
  `php -v`.

If you don't know how to fix it, describe the problem to a coding agent and let it draft the pull
request. Review the change and run the tests before you open it.

## A feature

First name the question in the README section "Scope" that the feature answers. A check of the
archive, the caller or the tools belongs in the package; a cause on the host does not. Features
that write archives, read single entries, or run on Windows are out of scope and will be declined.

A new refusal comes with its `Reason` case, a rule class, fixtures that break and keep the rule,
and a row in the README.

## Before you open it

```sh
composer test           # pint and rector as a dry run, PHPStan at max, type coverage 100 %, every suite
composer test:coverage  # line coverage 100 %, needs pcov
composer lint           # rector, then pint
```

The code must run on PHP 8.4, on Linux and macOS. When a message changes, record the demo again
with `make -C demo all` (needs Docker).

## Review

A pull request gets reviewed once it is ready for review and `composer test` passes in CI. Inactive
drafts may be closed. Pull requests opened in bulk are closed without review.
