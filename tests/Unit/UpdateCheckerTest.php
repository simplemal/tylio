<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tylio\Services\UpdateChecker;

/**
 * UpdateChecker — covers the pure parts (semver compare). The HTTP +
 * cache paths talk to GitHub + the DB and are integration-tested
 * elsewhere; here we lock down the comparison rules that drive the
 * "Aggiornamento disponibile" badge.
 */
final class UpdateCheckerTest extends TestCase
{
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        // compareVersions() is pure, doesn't need DB/Config.
        $this->checker = (new ReflectionClass(UpdateChecker::class))->newInstanceWithoutConstructor();
    }

    public function test_equal_versions(): void
    {
        $this->assertSame(0, $this->checker->compareVersions('v0.1.0', 'v0.1.0'));
        $this->assertSame(0, $this->checker->compareVersions('0.1.0', 'v0.1.0'));
    }

    public function test_strict_ordering(): void
    {
        $this->assertSame(-1, $this->checker->compareVersions('v0.1.0', 'v0.2.0'));
        $this->assertSame(1, $this->checker->compareVersions('v0.2.0', 'v0.1.0'));
        $this->assertSame(-1, $this->checker->compareVersions('v0.1.9', 'v0.2.0'));
        $this->assertSame(-1, $this->checker->compareVersions('v0.1.0', 'v1.0.0'));
    }

    public function test_pre_release_is_older_than_release(): void
    {
        // `git describe`-style suffix between two tags.
        $this->assertSame(-1, $this->checker->compareVersions('v0.1.0-15-gabc1234', 'v0.1.0'));
        // Conventional pre-release marker.
        $this->assertSame(-1, $this->checker->compareVersions('v0.2.0-rc.1', 'v0.2.0'));
    }

    public function test_unparseable_local_is_always_outdated(): void
    {
        // `dev`, `build-*`, raw SHAs — any non-semver string is treated
        // as "older than" a real release so the user sees the prompt to
        // upgrade.
        $this->assertSame(-1, $this->checker->compareVersions('dev', 'v0.1.0'));
        $this->assertSame(-1, $this->checker->compareVersions('build-2026-05-14-160000', 'v0.1.0'));
        $this->assertSame(-1, $this->checker->compareVersions('abc1234', 'v0.1.0'));
    }

    public function test_unparseable_remote_is_treated_as_known_good(): void
    {
        // If GitHub ever returned a non-semver tag for the latest
        // release we treat the local install as newer (= NOT outdated)
        // rather than wrongly prompting users to upgrade.
        $this->assertSame(1, $this->checker->compareVersions('v0.1.0', 'wat'));
    }
}
