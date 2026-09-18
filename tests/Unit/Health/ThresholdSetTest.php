<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Health\MetricName;
use Skukunin\MessengerStatsBundle\Health\Threshold;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;

final class ThresholdSetTest extends TestCase
{
    public function testItIsBuiltFromTheConfiguredArray(): void
    {
        $set = new ThresholdSet([
            'async' => [
                'pending' => ['warning' => 100, 'critical' => 500],
                'oldest_pending_age_seconds' => ['warning' => null, 'critical' => 600],
            ],
            'failed' => ['failed' => ['warning' => 1, 'critical' => null]],
        ]);

        $async = $set->thresholdsFor('async');

        self::assertCount(2, $async);
        self::assertSame(MetricName::Pending, $async[0]->metric);
        self::assertSame(100, $async[0]->warning);
        self::assertSame(500, $async[0]->critical);
        self::assertSame(MetricName::OldestPendingAgeSeconds, $async[1]->metric);
        self::assertNull($async[1]->warning);
        self::assertSame(600, $async[1]->critical);

        $failed = $set->thresholdsFor('failed');

        self::assertCount(1, $failed);
        self::assertSame(MetricName::Failed, $failed[0]->metric);
        self::assertNull($failed[0]->critical);
    }

    public function testUnknownTransportHasNoThresholds(): void
    {
        $set = new ThresholdSet(['async' => ['pending' => ['warning' => 1, 'critical' => null]]]);

        self::assertSame([], $set->thresholdsFor('events'));
        self::assertFalse($set->hasThresholdsFor('events'));
        self::assertTrue($set->hasThresholdsFor('async'));
    }

    public function testTransportWithoutAnyMetricHasNoThresholds(): void
    {
        $set = new ThresholdSet(['async' => []]);

        self::assertFalse($set->hasThresholdsFor('async'));
        self::assertSame([], $set->thresholdsFor('async'));
    }

    public function testAMetricWithoutAnyLevelIsStillConfigured(): void
    {
        $set = new ThresholdSet(['reporting' => ['pending' => ['warning' => null, 'critical' => null]]]);

        self::assertTrue($set->hasThresholdsFor('reporting'));
        self::assertCount(1, $set->thresholdsFor('reporting'));
    }

    public function testAThresholdWithoutAnyLevelNeverBreaches(): void
    {
        self::assertNull((new Threshold(MetricName::Pending, null, null))->problemFor('async', \PHP_INT_MAX));
    }

    public function testEmptyConfiguration(): void
    {
        $set = new ThresholdSet([]);

        self::assertFalse($set->hasThresholdsFor('async'));
        self::assertSame([], $set->thresholdsFor('async'));
    }

    public function testCriticalIsPreferredOverWarning(): void
    {
        $threshold = new Threshold(MetricName::Pending, 100, 500);

        $problem = $threshold->problemFor('async', 500);

        self::assertNotNull($problem);
        self::assertSame(ProblemLevel::Critical, $problem->level);
        self::assertSame(500, $problem->threshold);
        self::assertSame(500, $problem->value);
        self::assertSame('async', $problem->transport);
        self::assertSame('pending', $problem->metric);
    }

    public function testNoProblemBelowEveryLevel(): void
    {
        self::assertNull((new Threshold(MetricName::Pending, 100, 500))->problemFor('async', 99));
    }
}
