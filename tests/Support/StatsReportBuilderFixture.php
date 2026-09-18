<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use DateTimeImmutable;
use Skukunin\MessengerStatsBundle\BundleVersion;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Report\ApplicationIdentity;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;

final class StatsReportBuilderFixture
{
    /**
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    public static function returning(TransportStats $stats, array $thresholds = []): StatsReportBuilder
    {
        return new StatsReportBuilder(
            new TransportDefinitionRegistry([
                $stats->name => [
                    'dsn' => 'doctrine://default',
                    'options' => [],
                    'kind' => $stats->kind,
                    'is_failure_transport' => $stats->isFailureTransport,
                    'serializer' => 'messenger.default_serializer',
                ],
            ]),
            new StatsCollectorResolver([new FixedStatsCollector($stats)]),
            new ThresholdEvaluator(new ThresholdSet($thresholds)),
            new FixedClock(new DateTimeImmutable(StatsReportFixture::GENERATED_AT)),
            new ApplicationIdentity(StatsReportFixture::APP, StatsReportFixture::ENV),
            new BundleVersion('acme/not-installed'),
        );
    }
}
