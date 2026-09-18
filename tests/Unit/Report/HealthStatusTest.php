<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;

final class HealthStatusTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('ok', HealthStatus::Ok->value);
        self::assertSame('warning', HealthStatus::Warning->value);
        self::assertSame('critical', HealthStatus::Critical->value);
    }

    public function testSeverity(): void
    {
        self::assertSame(0, HealthStatus::Ok->severity());
        self::assertSame(1, HealthStatus::Warning->severity());
        self::assertSame(2, HealthStatus::Critical->severity());
    }

    /**
     * @dataProvider worstPairs
     */
    public function testWorst(HealthStatus $status, HealthStatus $other, HealthStatus $expected): void
    {
        self::assertSame($expected, $status->worst($other));
    }

    /**
     * @return iterable<string, array{HealthStatus, HealthStatus, HealthStatus}>
     */
    public static function worstPairs(): iterable
    {
        yield 'ok and ok' => [HealthStatus::Ok, HealthStatus::Ok, HealthStatus::Ok];
        yield 'ok and warning' => [HealthStatus::Ok, HealthStatus::Warning, HealthStatus::Warning];
        yield 'warning and ok' => [HealthStatus::Warning, HealthStatus::Ok, HealthStatus::Warning];
        yield 'warning and critical' => [HealthStatus::Warning, HealthStatus::Critical, HealthStatus::Critical];
        yield 'critical and warning' => [HealthStatus::Critical, HealthStatus::Warning, HealthStatus::Critical];
        yield 'critical and ok' => [HealthStatus::Critical, HealthStatus::Ok, HealthStatus::Critical];
        yield 'critical and critical' => [HealthStatus::Critical, HealthStatus::Critical, HealthStatus::Critical];
    }

    public function testProblemLevels(): void
    {
        self::assertSame('warning', ProblemLevel::Warning->value);
        self::assertSame('critical', ProblemLevel::Critical->value);
        self::assertSame(HealthStatus::Warning, ProblemLevel::Warning->toHealthStatus());
        self::assertSame(HealthStatus::Critical, ProblemLevel::Critical->toHealthStatus());
    }

    public function testDetailLevels(): void
    {
        self::assertSame('full', DetailLevel::Full->value);
        self::assertSame('count', DetailLevel::Count->value);
        self::assertSame('unavailable', DetailLevel::Unavailable->value);
    }
}
