<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The test case of every test: the properties that a `beforeEach` sets, with their types. The
 * extension in tests/PHPStan tells PHPStan that `$this` in a closure of Pest is this class.
 */
abstract class TestCase extends BaseTestCase
{
    /** A new empty directory of the test, from `temporaryDirectory()`. */
    public string $directory;

    /** The archive of the test. */
    public string $file;

    /** The umask of the process, for the expected modes. */
    public int $umask;
}
