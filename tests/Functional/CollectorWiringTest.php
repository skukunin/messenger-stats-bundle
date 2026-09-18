<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\DoctrineTransportStatsCollector;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;

final class CollectorWiringTest extends TestCase
{
    private const ENV_TRANSPORT_DSN_VALUE = 'doctrine://default?queue_name=envq';

    protected function setUp(): void
    {
        $_SERVER[TestKernel::ENV_TRANSPORT_DSN] = self::ENV_TRANSPORT_DSN_VALUE;
        putenv(TestKernel::ENV_TRANSPORT_DSN.'='.self::ENV_TRANSPORT_DSN_VALUE);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestKernel::ENV_TRANSPORT_DSN]);
        putenv(TestKernel::ENV_TRANSPORT_DSN);
    }

    public function testTheDoctrineCollectorIsWiredAndTagged(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        $collector = $container->get(DoctrineTransportStatsCollector::class);
        self::assertInstanceOf(DoctrineTransportStatsCollector::class, $collector);

        $registry = $container->get(TransportDefinitionRegistry::class);
        self::assertInstanceOf(TransportDefinitionRegistry::class, $registry);

        $stats = $collector->collect($registry->get('payments'));

        self::assertTrue($collector->supports($registry->get('payments')));
        self::assertSame(DetailLevel::Full, $stats->detailLevel);
        self::assertSame(0, $stats->count);

        $kernel->shutdown();
    }
}
