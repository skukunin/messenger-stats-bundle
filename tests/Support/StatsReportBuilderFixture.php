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
        return self::returningAll([$stats], $thresholds);
    }

    /**
     * @param list<TransportStats>                                               $transports
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    public static function returningAll(array $transports, array $thresholds = []): StatsReportBuilder
    {
        return new StatsReportBuilder(
            new TransportDefinitionRegistry(self::registryOf($transports)),
            new StatsCollectorResolver([new FixedStatsCollector($transports)]),
            new ThresholdEvaluator(new ThresholdSet($thresholds)),
            new FixedClock(new DateTimeImmutable(StatsReportFixture::GENERATED_AT)),
            new ApplicationIdentity(StatsReportFixture::APP, StatsReportFixture::ENV),
            new BundleVersion('acme/not-installed'),
        );
    }

    /**
     * @param list<TransportStats> $transports
     *
     * @return array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool, serializer: string}>
     */
    private static function registryOf(array $transports): array
    {
        $registry = [];
        foreach ($transports as $stats) {
            $registry[$stats->name] = [
                'dsn' => 'doctrine://default',
                'options' => [],
                'kind' => $stats->kind,
                'is_failure_transport' => $stats->isFailureTransport,
                'serializer' => 'messenger.default_serializer',
            ];
        }

        return $registry;
    }
}
