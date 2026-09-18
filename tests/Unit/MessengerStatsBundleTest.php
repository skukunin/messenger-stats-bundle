<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\DependencyInjection\Compiler\TransportDiscoveryPass;
use Skukunin\MessengerStatsBundle\DependencyInjection\MessengerStatsExtension;
use Skukunin\MessengerStatsBundle\MessengerStatsBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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

    public function testItRegistersTheTransportDiscoveryPass(): void
    {
        $container = new ContainerBuilder();

        (new MessengerStatsBundle())->build($container);

        $passes = $container->getCompiler()->getPassConfig()->getBeforeOptimizationPasses();

        self::assertNotEmpty(array_filter($passes, static fn (CompilerPassInterface $pass): bool => $pass instanceof TransportDiscoveryPass));
    }

    public function testContainerExtension(): void
    {
        $extension = (new MessengerStatsBundle())->getContainerExtension();

        self::assertInstanceOf(MessengerStatsExtension::class, $extension);
        self::assertSame('messenger_stats', $extension->getAlias());
    }
}
