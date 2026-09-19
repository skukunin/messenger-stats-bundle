<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Transport;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Transport\StorageTimezoneResolver;

final class StorageTimezoneResolverTest extends TestCase
{
    /**
     * @dataProvider installedVersions
     */
    public function testAutoFollowsTheTimezoneTheInstalledDoctrineMessengerWritesIn(?string $version, string $expected): void
    {
        self::assertSame($expected, (new StorageTimezoneResolver($version, 'Europe/Berlin'))->resolve(StorageTimezoneResolver::AUTO));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function installedVersions(): iterable
    {
        yield '5.4 writes local time' => ['5.4.45.0', 'Europe/Berlin'];
        yield '6.2 writes local time' => ['6.2.14.0', 'Europe/Berlin'];
        yield '6.3 writes UTC' => ['6.3.0.0', 'UTC'];
        yield '6.4 writes UTC' => ['6.4.13.0', 'UTC'];
        yield '7.4 writes UTC' => ['7.4.19.0', 'UTC'];
        yield 'a branch alias of 6.4 writes UTC' => ['6.4.9999999.9999999-dev', 'UTC'];
        yield 'an unknown version falls back to local time' => [null, 'Europe/Berlin'];
        yield 'a branch without a version falls back to local time' => ['dev-main', 'Europe/Berlin'];
        yield 'an empty version falls back to local time' => ['', 'Europe/Berlin'];
    }

    public function testAConfiguredTimezoneWinsOverAuto(): void
    {
        self::assertSame('America/New_York', (new StorageTimezoneResolver('7.4.19.0', 'Europe/Berlin'))->resolve('America/New_York'));
        self::assertSame('UTC', (new StorageTimezoneResolver('5.4.45.0', 'Europe/Berlin'))->resolve('UTC'));
    }

    public function testTheEnvironmentResolverReadsTheInstalledPackageAndTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $resolved = StorageTimezoneResolver::fromEnvironment()->resolve(StorageTimezoneResolver::AUTO);
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertSame($this->storesUtc() ? 'UTC' : 'Asia/Tokyo', $resolved);
    }

    private function storesUtc(): bool
    {
        return version_compare((string) InstalledVersions::getVersion('symfony/doctrine-messenger'), '6.3', '>=');
    }
}
