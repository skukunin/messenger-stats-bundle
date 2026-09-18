<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\DoctrineTransportStatsCollector;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\RebuildIndex;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

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

    public function testEveryTransportIsDecodedWithItsOwnSerializer(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        $registry = $container->get(TransportDefinitionRegistry::class);
        self::assertInstanceOf(TransportDefinitionRegistry::class, $registry);
        self::assertSame('messenger.default_serializer', $registry->get('async')->serializerServiceId);
        self::assertSame(TestKernel::JSON_SERIALIZER, $registry->get('payments')->serializerServiceId);

        $this->send($container, 'async', new SendInvoice());
        $this->send($container, 'payments', new RebuildIndex());

        $collector = $container->get(DoctrineTransportStatsCollector::class);
        self::assertInstanceOf(DoctrineTransportStatsCollector::class, $collector);
        self::assertSame([SendInvoice::class => 1], $this->onlyQueue($collector->collect($registry->get('async')))->classBreakdown);
        self::assertSame([RebuildIndex::class => 1], $this->onlyQueue($collector->collect($registry->get('payments')))->classBreakdown);

        $kernel->shutdown();
    }

    private function send(ContainerInterface $container, string $transportName, object $message): void
    {
        $transport = $container->get('messenger.transport.'.$transportName);
        self::assertInstanceOf(TransportInterface::class, $transport);

        $transport->send(new Envelope($message));
    }

    private function onlyQueue(TransportStats $stats): QueueStats
    {
        self::assertCount(1, $stats->queues);

        return $stats->queues[0];
    }
}
