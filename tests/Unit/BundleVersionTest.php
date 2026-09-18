<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\BundleVersion;

final class BundleVersionTest extends TestCase
{
    public function testItReadsThePrettyVersionOfAnInstalledPackage(): void
    {
        self::assertSame(InstalledVersions::getPrettyVersion('psr/log'), (new BundleVersion('psr/log'))->version());
    }

    public function testItFallsBackWhenThePackageIsNotInstalled(): void
    {
        self::assertSame(BundleVersion::FALLBACK, (new BundleVersion('acme/not-installed'))->version());
    }

    public function testItDescribesItsOwnPackageByDefault(): void
    {
        self::assertSame(InstalledVersions::getPrettyVersion(BundleVersion::PACKAGE), (new BundleVersion())->version());
    }
}
