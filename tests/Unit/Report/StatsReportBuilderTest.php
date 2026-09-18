<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Skukunin\MessengerStatsBundle\BundleVersion;
use Skukunin\MessengerStatsBundle\Collector\StatsCollector;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Report\ApplicationIdentity;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\FixedClock;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Throwable;

final class StatsReportBuilderTest extends TestCase
{
    private const NOW = '2026-09-18T10:00:00+00:00';

    public function testEveryTransportIsCollected(): void
    {
        $report = $this->build($this->countingCollector(['async' => 4, 'events' => 9]));

        self::assertCount(2, $report->transports);
        self::assertSame(['async', 'events'], array_map(static fn (TransportStats $stats): string => $stats->name, $report->transports));
        self::assertSame([4, 9], array_map(static fn (TransportStats $stats): ?int => $stats->count, $report->transports));
    }

    public function testTheReportCarriesTheApplicationIdentityAndTheGenerationTime(): void
    {
        $report = $this->build($this->countingCollector(['async' => 0, 'events' => 0]));

        self::assertSame(self::NOW, $report->generatedAt->format(DateTimeImmutable::RFC3339));
        self::assertSame('shop', $report->app);
        self::assertSame('prod', $report->env);
        self::assertSame(BundleVersion::FALLBACK, $report->bundleVersion);
    }

    public function testAFailingCollectorOnlyMakesItsOwnTransportUnavailable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('{transport}'), self::callback(static fn (array $context): bool => 'async' === $context['transport'] && 'connection refused' === $context['message']));

        $report = $this->build($this->failingCollector('async', new RuntimeException('connection refused')), [], $logger);

        self::assertSame(DetailLevel::Unavailable, $report->transports[0]->detailLevel);
        self::assertSame(RuntimeException::class, $report->transports[0]->error);
        self::assertNull($report->transports[0]->count);
        self::assertSame(DetailLevel::Count, $report->transports[1]->detailLevel);
        self::assertSame(9, $report->transports[1]->count);
    }

    public function testAnUnavailableTransportNeverLeaksTheExceptionMessage(): void
    {
        $report = $this->build($this->failingCollector('async', new RuntimeException('pdo_mysql://root:hunter2@db.internal')));

        self::assertSame(RuntimeException::class, $report->transports[0]->error);
        self::assertSame('up', $report->problems[0]->metric);
        self::assertSame(ProblemLevel::Warning, $report->problems[0]->level);
    }

    public function testTheResolverFailureIsReportedAsUnavailableToo(): void
    {
        $report = $this->build(null);

        self::assertSame(DetailLevel::Unavailable, $report->transports[0]->detailLevel);
        self::assertSame(DetailLevel::Unavailable, $report->transports[1]->detailLevel);
        self::assertSame(HealthStatus::Warning, $report->status);
    }

    public function testThresholdsDecideTheStatusAndTheProblems(): void
    {
        $report = $this->build($this->countingCollector(['async' => 4, 'events' => 9]), ['events' => ['count' => ['warning' => 5, 'critical' => 8]]]);

        self::assertSame(HealthStatus::Critical, $report->status);
        self::assertCount(1, $report->problems);
        self::assertSame('events', $report->problems[0]->transport);
        self::assertSame('count', $report->problems[0]->metric);
        self::assertSame(9, $report->problems[0]->value);
        self::assertSame(8, $report->problems[0]->threshold);
        self::assertSame(ProblemLevel::Critical, $report->problems[0]->level);
    }

    public function testNoThresholdMeansAHealthyReport(): void
    {
        $report = $this->build($this->countingCollector(['async' => 4, 'events' => 9]));

        self::assertSame(HealthStatus::Ok, $report->status);
        self::assertSame([], $report->problems);
    }

    /**
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    private function build(?StatsCollector $collector, array $thresholds = [], ?LoggerInterface $logger = null): StatsReport
    {
        $builder = new StatsReportBuilder(
            new TransportDefinitionRegistry([
                'async' => ['dsn' => 'amqp://localhost', 'options' => [], 'kind' => 'amqp', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
                'events' => ['dsn' => 'redis://localhost', 'options' => [], 'kind' => 'redis', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
            ]),
            new StatsCollectorResolver(null === $collector ? [] : [$collector]),
            new ThresholdEvaluator(new ThresholdSet($thresholds)),
            new FixedClock(new DateTimeImmutable(self::NOW)),
            new ApplicationIdentity('shop', 'prod'),
            new BundleVersion('acme/not-installed'),
            $logger,
        );

        return $builder->build();
    }

    /**
     * @param array<string, int> $counts
     */
    private function countingCollector(array $counts): StatsCollector
    {
        $collector = $this->createMock(StatsCollector::class);
        $collector->method('supports')->willReturn(true);
        $collector->method('collect')->willReturnCallback(static fn (TransportDefinition $definition): TransportStats => TransportStats::countOnly($definition->name, $definition->kind, $definition->isFailureTransport, $counts[$definition->name] ?? null));

        return $collector;
    }

    private function failingCollector(string $failingTransport, Throwable $failure): StatsCollector
    {
        $collector = $this->createMock(StatsCollector::class);
        $collector->method('supports')->willReturn(true);
        $collector->method('collect')->willReturnCallback(static function (TransportDefinition $definition) use ($failingTransport, $failure): TransportStats {
            if ($definition->name === $failingTransport) {
                throw $failure;
            }

            return TransportStats::countOnly($definition->name, $definition->kind, $definition->isFailureTransport, 9);
        });

        return $collector;
    }
}
