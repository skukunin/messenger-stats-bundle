<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\BundleVersion;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\Transport\CountingTransportFactory;
use Symfony\Component\Filesystem\Filesystem;

final class StatsReportWiringTest extends TestCase
{
    private const ENV_TRANSPORT_DSN_VALUE = 'doctrine://default?queue_name=envq';

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $_SERVER[TestKernel::ENV_TRANSPORT_DSN] = self::ENV_TRANSPORT_DSN_VALUE;
        putenv(TestKernel::ENV_TRANSPORT_DSN.'='.self::ENV_TRANSPORT_DSN_VALUE);
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($cacheDir);
            $this->kernel = null;
        }

        unset($_SERVER[TestKernel::ENV_TRANSPORT_DSN]);
        putenv(TestKernel::ENV_TRANSPORT_DSN);
    }

    public function testEveryDiscoveredTransportIsReportedAtItsOwnDetailLevel(): void
    {
        $transports = $this->transportsOf($this->build());

        self::assertSame(['async', 'payments', 'failed', 'retry', 'env_dsn', 'fake', 'broken'], array_keys($transports));
        self::assertSame(DetailLevel::Full, $transports['async']->detailLevel);
        self::assertSame(DetailLevel::Full, $transports['failed']->detailLevel);
        self::assertSame(DetailLevel::Count, $transports['fake']->detailLevel);
        self::assertSame('doctrine', $transports['async']->kind);
        self::assertSame('fake', $transports['fake']->kind);
    }

    public function testACountAwareTransportIsReportedThroughItsTransportService(): void
    {
        $fake = $this->transportsOf($this->build())['fake'];

        self::assertSame(CountingTransportFactory::MESSAGE_COUNT, $fake->count);
        self::assertSame([], $fake->queues);
        self::assertNull($fake->error);
    }

    public function testOnlyTheConfiguredFailureTransportIsFlagged(): void
    {
        $transports = $this->transportsOf($this->build());

        self::assertTrue($transports['failed']->isFailureTransport);
        self::assertFalse($transports['fake']->isFailureTransport);
        self::assertFalse($transports['async']->isFailureTransport);
    }

    public function testAnUnreachableConnectionOnlyMakesItsOwnTransportUnavailable(): void
    {
        $transports = $this->transportsOf($this->build());
        $broken = $transports['broken'];

        self::assertSame(DetailLevel::Unavailable, $broken->detailLevel);
        self::assertNull($broken->count);
        self::assertIsString($broken->error);
        self::assertStringStartsWith('Doctrine\\', $broken->error);
        self::assertSame(DetailLevel::Full, $transports['async']->detailLevel);
        self::assertSame(DetailLevel::Count, $transports['fake']->detailLevel);
    }

    public function testAnUnavailableTransportWithoutThresholdsIsAWarning(): void
    {
        $report = $this->build();

        self::assertSame(HealthStatus::Warning, $report->status);
        self::assertSame('up', $this->problemOf($report, 'broken')->metric);
        self::assertSame(ProblemLevel::Warning, $this->problemOf($report, 'broken')->level);
    }

    public function testAThresholdOnACountOnlyTransportTurnsTheReportCritical(): void
    {
        $report = $this->build(['thresholds' => ['fake' => ['count' => ['critical' => 1]]]]);
        $problem = $this->problemOf($report, 'fake');

        self::assertSame(HealthStatus::Critical, $report->status);
        self::assertSame('count', $problem->metric);
        self::assertSame(CountingTransportFactory::MESSAGE_COUNT, $problem->value);
        self::assertSame(1, $problem->threshold);
        self::assertSame(ProblemLevel::Critical, $problem->level);
    }

    public function testTheReportIdentifiesTheApplicationAndTheBundle(): void
    {
        $report = $this->build();

        self::assertSame(basename(\dirname(__DIR__, 2)), $report->app);
        self::assertSame('test', $report->env);
        self::assertSame((new BundleVersion())->version(), $report->bundleVersion);
        self::assertNotSame('', $report->bundleVersion);
    }

    public function testTheConfiguredApplicationNameWins(): void
    {
        self::assertSame('shop', $this->build(['app_name' => 'shop'])->app);
    }

    /**
     * @param array<string, mixed> $statsConfig
     */
    private function build(array $statsConfig = []): StatsReport
    {
        $this->kernel ??= new TestKernel($statsConfig);
        $this->kernel->boot();

        $builder = $this->kernel->getContainer()->get(StatsReportBuilder::class);
        self::assertInstanceOf(StatsReportBuilder::class, $builder);

        return $builder->build();
    }

    /**
     * @return array<string, TransportStats>
     */
    private function transportsOf(StatsReport $report): array
    {
        $transports = [];
        foreach ($report->transports as $stats) {
            $transports[$stats->name] = $stats;
        }

        return $transports;
    }

    private function problemOf(StatsReport $report, string $transport): Problem
    {
        foreach ($report->problems as $problem) {
            if ($problem->transport === $transport) {
                return $problem;
            }
        }

        self::fail(\sprintf('No problem reported for transport "%s".', $transport));
    }
}
