<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class StatsReportTest extends TestCase
{
    public function testItKeepsWhatItWasBuiltWith(): void
    {
        $generatedAt = new DateTimeImmutable('2026-09-18T10:00:00+00:00');
        $problem = new Problem('async', 'pending', 500, 100, ProblemLevel::Critical);
        $transport = TransportStats::countOnly('async', 'amqp', false, 500);

        $report = new StatsReport($generatedAt, 'shop', 'prod', '0.1.0', HealthStatus::Critical, [$problem], [$transport]);

        self::assertSame($generatedAt, $report->generatedAt);
        self::assertSame('shop', $report->app);
        self::assertSame('prod', $report->env);
        self::assertSame('0.1.0', $report->bundleVersion);
        self::assertSame(HealthStatus::Critical, $report->status);
        self::assertSame([$problem], $report->problems);
        self::assertSame([$transport], $report->transports);
    }
}
