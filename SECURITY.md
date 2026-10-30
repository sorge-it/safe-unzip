# Security policy

## Supported versions

The latest minor release of 1.x. Fixes are released there only.

A fix that refuses a new pattern of an attack comes as a patch release, though it can refuse an
archive that an earlier release extracted.

## Reporting a vulnerability

Do not report a vulnerability in a public issue or pull request. Report it privately through GitHub:
[Report a vulnerability](https://github.com/sorge-it/safe-unzip/security/advisories/new).

Include an archive or a call that shows the problem, and the output of `unzip -v` and `php -v`. If
you have a fix, say so in the report; we will then open a private fork in the advisory for it.
There is no fixed response time.

The package reads archives that may come from an attacker. The README states what it checks and
what it does not.
