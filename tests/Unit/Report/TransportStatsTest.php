<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class TransportStatsTest extends TestCase
{
    public function testFullDescribesTheTransport(): void
    {
        $transport = TransportStats::full('async', 'doctrine', [$this->queue('default', 42, 3, 1, 0, 900)]);

        self::assertSame('async', $transport->name);
        self::assertSame('doctrine', $transport->kind);
        self::assertSame(DetailLevel::Full, $transport->detailLevel);
        self::assertFalse($transport->isFailureTransport);
        self::assertCount(1, $transport->queues);
        self::assertSame([], $transport->failures);
        self::assertNull($transport->error);
    }

    public function testFullAggregatesAcrossQueues(): void
    {
        $transport = TransportStats::full('async', 'doctrine', [
            $this->queue('payments', 42, 3, 1, 0, 900),
            $this->queue('emails', 8, 2, 4, 5, 60),
        ]);

        self::assertSame(50, $transport->totalPending());
        self::assertSame(5, $transport->totalDelayed());
        self::assertSame(5, $transport->totalInProgress());
        self::assertSame(5, $transport->totalStuck());
        self::assertSame(900, $transport->maxOldestPendingAgeSeconds());
        self::assertSame(65, $transport->count);
    }

    public function testOldestPendingAgeIgnoresQueuesWithoutPendingMessages(): void
    {
        $transport = TransportStats::full('async', 'doctrine', [
            $this->queue('payments', 0, 0, 0, 0, null),
            $this->queue('emails', 8, 0, 0, 0, 60),
        ]);

        self::assertSame(60, $transport->maxOldestPendingAgeSeconds());
    }

    public function testOldestPendingAgeIsNullWithoutPendingMessages(): void
    {
        $transport = TransportStats::full('async', 'doctrine', [$this->queue('payments', 0, 0, 0, 0, null)]);

        self::assertNull($transport->maxOldestPendingAgeSeconds());
    }

    public function testFullWithoutQueuesIsEmpty(): void
    {
        $transport = TransportStats::full('async', 'doctrine', []);

        self::assertSame(0, $transport->totalPending());
        self::assertSame(0, $transport->count);
        self::assertNull($transport->maxOldestPendingAgeSeconds());
    }

    public function testFailedCountIsNullOutsideTheFailureTransport(): void
    {
        self::assertNull(TransportStats::full('async', 'doctrine', [$this->queue('default', 7, 0, 0, 0, 10)])->failedCount());
    }

    public function testAFullTransportIsNeverTheFailureTransport(): void
    {
        $transport = TransportStats::full('async', 'doctrine', [$this->queue('default', 7, 0, 0, 0, 10)]);

        self::assertFalse($transport->isFailureTransport);
        self::assertSame([], $transport->failures);
        self::assertNull($transport->classBreakdown);
    }

    public function testTheFailureTransportCarriesItsCountClassBreakdownAndFailures(): void
    {
        $failure = new FailedMessage('App\Message\SendEmail', 'RuntimeException', 'Connection refused', new DateTimeImmutable('2026-09-17T10:00:00+00:00'), 3, 'async');
        $classBreakdown = new ClassBreakdown(['App\Message\SendEmail' => 7], true);
        $transport = TransportStats::failure('failed', 'doctrine', 7, $classBreakdown, [$failure]);

        self::assertSame('failed', $transport->name);
        self::assertSame('doctrine', $transport->kind);
        self::assertSame(DetailLevel::Full, $transport->detailLevel);
        self::assertTrue($transport->isFailureTransport);
        self::assertSame(7, $transport->count);
        self::assertSame(7, $transport->failedCount());
        self::assertSame($classBreakdown, $transport->classBreakdown);
        self::assertSame([$failure], $transport->failures);
        self::assertSame([], $transport->queues);
        self::assertNull($transport->error);
        self::assertNull($transport->maxOldestPendingAgeSeconds());
    }

    public function testClassBreakdownKeepsItsCountsAndSampling(): void
    {
        $classBreakdown = new ClassBreakdown(['App\Message\SendEmail' => 7], true);

        self::assertSame(['App\Message\SendEmail' => 7], $classBreakdown->counts);
        self::assertTrue($classBreakdown->sampled);
    }

    public function testCountOnly(): void
    {
        $transport = TransportStats::countOnly('events', 'amqp', false, 12);

        self::assertSame(DetailLevel::Count, $transport->detailLevel);
        self::assertSame(12, $transport->count);
        self::assertSame([], $transport->queues);
        self::assertSame([], $transport->failures);
        self::assertNull($transport->error);
        self::assertNull($transport->maxOldestPendingAgeSeconds());
        self::assertSame(0, $transport->totalPending());
    }

    public function testCountOnlyWithoutACount(): void
    {
        self::assertNull(TransportStats::countOnly('events', 'amqp', false, null)->count);
    }

    public function testUnavailable(): void
    {
        $transport = TransportStats::unavailable('reporting', 'doctrine', false, 'Doctrine\DBAL\Exception\ConnectionException');

        self::assertSame(DetailLevel::Unavailable, $transport->detailLevel);
        self::assertNull($transport->count);
        self::assertSame('Doctrine\DBAL\Exception\ConnectionException', $transport->error);
        self::assertSame([], $transport->queues);
        self::assertNull($transport->failedCount());
    }

    public function testQueueStatsKeepsItsClassBreakdown(): void
    {
        $queue = new QueueStats('payments', 42, 3, 1, 0, 900, ['App\Message\Pay' => 42], true);

        self::assertSame('payments', $queue->name);
        self::assertSame(['App\Message\Pay' => 42], $queue->classBreakdown);
        self::assertTrue($queue->classBreakdownSampled);
    }

    public function testFailedMessageKeepsItsFields(): void
    {
        $failedAt = new DateTimeImmutable('2026-09-17T10:00:00+00:00');
        $failure = new FailedMessage('App\Message\SendEmail', 'RuntimeException', null, $failedAt, 3, 'async');

        self::assertSame('App\Message\SendEmail', $failure->messageClass);
        self::assertSame('RuntimeException', $failure->exceptionClass);
        self::assertNull($failure->exceptionMessage);
        self::assertSame($failedAt, $failure->failedAt);
        self::assertSame(3, $failure->retryCount);
        self::assertSame('async', $failure->originalTransport);
    }

    private function queue(string $name, int $pending, int $delayed, int $inProgress, int $stuck, ?int $oldestPendingAgeSeconds): QueueStats
    {
        return new QueueStats($name, $pending, $delayed, $inProgress, $stuck, $oldestPendingAgeSeconds, [], false);
    }
}
