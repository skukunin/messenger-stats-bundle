<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\CountOnlyStatsCollector;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Tests\Support\Transport\CountingTransport;
use Skukunin\MessengerStatsBundle\Tests\Support\Transport\UncountableTransport;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class CountOnlyStatsCollectorTest extends TestCase
{
    public function testItSupportsEveryTransportButDoctrine(): void
    {
        $collector = $this->collector([]);

        self::assertFalse($collector->supports($this->definition('async', 'doctrine')));
        self::assertTrue($collector->supports($this->definition('events', 'amqp')));
        self::assertTrue($collector->supports($this->definition('cache', 'redis')));
        self::assertTrue($collector->supports($this->definition('weird', 'unknown')));
    }

    public function testACountAwareTransportReportsItsMessageCount(): void
    {
        $stats = $this->collector(['events' => new CountingTransport(7)])->collect($this->definition('events', 'amqp'));

        self::assertSame('events', $stats->name);
        self::assertSame('amqp', $stats->kind);
        self::assertSame(DetailLevel::Count, $stats->detailLevel);
        self::assertSame(7, $stats->count);
        self::assertFalse($stats->isFailureTransport);
        self::assertSame([], $stats->queues);
        self::assertNull($stats->error);
    }

    public function testATransportThatCannotCountReportsNoCount(): void
    {
        $stats = $this->collector(['events' => new UncountableTransport()])->collect($this->definition('events', 'amqp'));

        self::assertSame(DetailLevel::Count, $stats->detailLevel);
        self::assertNull($stats->count);
    }

    public function testAnUnknownTransportServiceReportsNoCount(): void
    {
        $stats = $this->collector([])->collect($this->definition('events', 'amqp'));

        self::assertSame(DetailLevel::Count, $stats->detailLevel);
        self::assertNull($stats->count);
    }

    public function testTheFailureTransportIsFlagged(): void
    {
        $stats = $this->collector(['failed' => new CountingTransport(3)])->collect($this->definition('failed', 'redis', true));

        self::assertTrue($stats->isFailureTransport);
        self::assertSame(3, $stats->failedCount());
    }

    /**
     * @param array<string, TransportInterface> $transports
     */
    private function collector(array $transports): CountOnlyStatsCollector
    {
        $factories = [];
        foreach ($transports as $name => $transport) {
            $factories[$name] = static fn (): TransportInterface => $transport;
        }

        return new CountOnlyStatsCollector(new ServiceLocator($factories));
    }

    private function definition(string $name, string $kind, bool $isFailureTransport = false): TransportDefinition
    {
        return new TransportDefinition($name, $kind.'://localhost', $kind, [], $isFailureTransport, 'messenger.default_serializer');
    }
}
