<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Health\EvaluationResult;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class ThresholdEvaluatorTest extends TestCase
{
    public function testNoThresholdsMeansOk(): void
    {
        $result = $this->evaluate([], [$this->fullTransport(1000)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testValueBelowEveryLevelIsOk(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 100, 'critical' => 500]]], [$this->fullTransport(99)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testValueEqualToTheWarningBreachesIt(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 100, 'critical' => null]]], [$this->fullTransport(100)]);

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertCount(1, $result->problems);
        self::assertSame('async', $result->problems[0]->transport);
        self::assertSame('pending', $result->problems[0]->metric);
        self::assertSame(100, $result->problems[0]->value);
        self::assertSame(100, $result->problems[0]->threshold);
        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
    }

    public function testValueEqualToTheCriticalBreachesIt(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => null, 'critical' => 500]]], [$this->fullTransport(500)]);

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertCount(1, $result->problems);
        self::assertSame(500, $result->problems[0]->threshold);
        self::assertSame(ProblemLevel::Critical, $result->problems[0]->level);
    }

    public function testCriticalOnlyIsNotBreachedByAWarningSizedValue(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => null, 'critical' => 500]]], [$this->fullTransport(499)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testValueBetweenBothLevelsBreachesTheWarning(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 100, 'critical' => 500]]], [$this->fullTransport(200)]);

        self::assertCount(1, $result->problems);
        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
        self::assertSame(100, $result->problems[0]->threshold);
        self::assertSame(200, $result->problems[0]->value);
    }

    public function testValueAboveBothLevelsReportsOneCriticalProblem(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 100, 'critical' => 500]]], [$this->fullTransport(900)]);

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertCount(1, $result->problems);
        self::assertSame(ProblemLevel::Critical, $result->problems[0]->level);
        self::assertSame(500, $result->problems[0]->threshold);
    }

    /**
     * @dataProvider fullMetrics
     */
    public function testEveryMetricOfAFullTransportIsEvaluated(string $metric, int $expectedValue): void
    {
        $transport = TransportStats::full('async', 'doctrine', true, [
            new QueueStats('payments', 42, 3, 1, 2, 900, [], false),
            new QueueStats('emails', 8, 4, 5, 6, 60, [], false),
        ], []);

        $result = $this->evaluate(['async' => [$metric => ['warning' => null, 'critical' => 1]]], [$transport]);

        self::assertCount(1, $result->problems);
        self::assertSame($metric, $result->problems[0]->metric);
        self::assertSame($expectedValue, $result->problems[0]->value);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function fullMetrics(): iterable
    {
        yield 'pending' => ['pending', 50];
        yield 'delayed' => ['delayed', 7];
        yield 'in_progress' => ['in_progress', 6];
        yield 'stuck' => ['stuck', 8];
        yield 'oldest_pending_age_seconds' => ['oldest_pending_age_seconds', 900];
        yield 'failed' => ['failed', 71];
        yield 'count' => ['count', 71];
    }

    public function testFailedIsSkippedOutsideTheFailureTransport(): void
    {
        $result = $this->evaluate(['async' => ['failed' => ['warning' => null, 'critical' => 0]]], [$this->fullTransport(10)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testOldestPendingAgeIsSkippedWhenUnknown(): void
    {
        $transport = TransportStats::full('async', 'doctrine', false, [new QueueStats('default', 0, 0, 0, 0, null, [], false)], []);

        $result = $this->evaluate(['async' => ['oldest_pending_age_seconds' => ['warning' => null, 'critical' => 0]]], [$transport]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testCountLevelTransportEvaluatesItsCount(): void
    {
        $result = $this->evaluate(['events' => ['count' => ['warning' => 10, 'critical' => null]]], [TransportStats::countOnly('events', 'amqp', false, 12)]);

        self::assertCount(1, $result->problems);
        self::assertSame('count', $result->problems[0]->metric);
        self::assertSame(12, $result->problems[0]->value);
        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
    }

    public function testCountLevelTransportSkipsEveryOtherMetric(): void
    {
        $result = $this->evaluate([
            'events' => [
                'pending' => ['warning' => null, 'critical' => 0],
                'stuck' => ['warning' => null, 'critical' => 0],
                'oldest_pending_age_seconds' => ['warning' => null, 'critical' => 0],
            ],
        ], [TransportStats::countOnly('events', 'amqp', false, 12)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testCountLevelTransportWithoutACountIsSkipped(): void
    {
        $result = $this->evaluate(['events' => ['count' => ['warning' => null, 'critical' => 0]]], [TransportStats::countOnly('events', 'amqp', false, null)]);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    public function testUnavailableTransportWithoutThresholdsIsAWarning(): void
    {
        $result = $this->evaluate([], [TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException')]);

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertCount(1, $result->problems);
        self::assertSame('reporting', $result->problems[0]->transport);
        self::assertSame('up', $result->problems[0]->metric);
        self::assertSame(0, $result->problems[0]->value);
        self::assertSame(1, $result->problems[0]->threshold);
        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
    }

    public function testUnavailableTransportWithThresholdsIsCriticalAndReportsNothingElse(): void
    {
        $result = $this->evaluate(
            ['reporting' => ['pending' => ['warning' => null, 'critical' => 0], 'count' => ['warning' => null, 'critical' => 0]]],
            [TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException')],
        );

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertCount(1, $result->problems);
        self::assertSame('up', $result->problems[0]->metric);
        self::assertSame(ProblemLevel::Critical, $result->problems[0]->level);
    }

    public function testUnavailableTransportIsCriticalEvenWhenItsThresholdsCarryNoLevel(): void
    {
        $result = $this->evaluate(
            ['reporting' => ['pending' => ['warning' => null, 'critical' => null]]],
            [TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException')],
        );

        self::assertSame(ProblemLevel::Critical, $result->problems[0]->level);
    }

    public function testThresholdsOfAnotherTransportDoNotMakeAnUnavailableTransportCritical(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 1, 'critical' => null]]], [TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException')]);

        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
    }

    public function testStatusIsTheWorstLevelAcrossTransports(): void
    {
        $result = $this->evaluate([
            'async' => ['pending' => ['warning' => 1, 'critical' => null]],
            'events' => ['count' => ['warning' => null, 'critical' => 1]],
        ], [$this->fullTransport(10), TransportStats::countOnly('events', 'amqp', false, 12)]);

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertCount(2, $result->problems);
        self::assertSame(ProblemLevel::Warning, $result->problems[0]->level);
        self::assertSame(ProblemLevel::Critical, $result->problems[1]->level);
    }

    public function testNoTransportsMeansOk(): void
    {
        $result = $this->evaluate(['async' => ['pending' => ['warning' => 1, 'critical' => null]]], []);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame([], $result->problems);
    }

    private function fullTransport(int $pending): TransportStats
    {
        return TransportStats::full('async', 'doctrine', false, [new QueueStats('default', $pending, 0, 0, 0, 10, [], false)], []);
    }

    /**
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     * @param list<TransportStats>                                               $transports
     */
    private function evaluate(array $thresholds, array $transports): EvaluationResult
    {
        return (new ThresholdEvaluator(new ThresholdSet($thresholds)))->evaluate($transports);
    }
}
