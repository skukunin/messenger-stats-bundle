<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\StatsCollector;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\Exception\NoCollectorForTransportException;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

final class StatsCollectorResolverTest extends TestCase
{
    public function testTheFirstSupportingCollectorWins(): void
    {
        $doctrine = $this->collector(true);
        $fallback = $this->collector(true);

        self::assertSame($doctrine, (new StatsCollectorResolver([$doctrine, $fallback]))->resolve($this->definition()));
    }

    public function testCollectorsThatDoNotSupportTheTransportAreSkipped(): void
    {
        $fallback = $this->collector(true);

        self::assertSame($fallback, (new StatsCollectorResolver([$this->collector(false), $fallback]))->resolve($this->definition()));
    }

    public function testATransportWithoutAnyCollectorIsRejected(): void
    {
        $this->expectException(NoCollectorForTransportException::class);
        $this->expectExceptionMessage('No stats collector supports transport "events" of kind "amqp".');

        (new StatsCollectorResolver([$this->collector(false)]))->resolve($this->definition());
    }

    public function testAnEmptyCollectorListIsRejected(): void
    {
        $this->expectException(NoCollectorForTransportException::class);

        (new StatsCollectorResolver([]))->resolve($this->definition());
    }

    private function collector(bool $supports): StatsCollector
    {
        $collector = $this->createMock(StatsCollector::class);
        $collector->method('supports')->willReturn($supports);
        $collector->method('collect')->willReturn(TransportStats::countOnly('events', 'amqp', false, null));

        return $collector;
    }

    private function definition(): TransportDefinition
    {
        return new TransportDefinition('events', 'amqp://localhost', 'amqp', [], false, 'messenger.default_serializer');
    }
}
