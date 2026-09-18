<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\DependencyInjection\MessengerStatsExtension;
use Skukunin\MessengerStatsBundle\MessengerStatsBundle;

final class MessengerStatsBundleTest extends TestCase
{
    public function testPathIsThePackageRoot(): void
    {
        self::assertSame(\dirname(__DIR__, 2), (new MessengerStatsBundle())->getPath());
    }

    public function testRoutesFileIsReachableThroughThePath(): void
    {
        self::assertFileExists((new MessengerStatsBundle())->getPath().'/config/routes.php');
    }

    public function testContainerExtension(): void
    {
        $extension = (new MessengerStatsBundle())->getContainerExtension();

        self::assertInstanceOf(MessengerStatsExtension::class, $extension);
        self::assertSame('messenger_stats', $extension->getAlias());
    }
}
