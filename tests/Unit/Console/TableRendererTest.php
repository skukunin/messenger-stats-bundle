<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Console\TableRenderer;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportFixture;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class TableRendererTest extends TestCase
{
    public function testTheHeaderLineCarriesTheApplicationTheGenerationTimeAndTheStatus(): void
    {
        self::assertStringContainsString(
            'App: shop  Env: prod  Generated: 2026-09-18T10:00:00+00:00  Status: critical',
            $this->render(StatsReportFixture::specExample()),
        );
    }

    private function render(StatsReport $report, bool $decorated = false): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        (new TableRenderer())->render($report, $output);

        return $output->fetch();
    }

    public function testACriticalStatusIsColoredRedOnADecoratedOutput(): void
    {
        self::assertStringContainsString("\033[31mcritical\033[39m", $this->render(StatsReportFixture::specExample(), true));
    }

    public function testAWarningStatusIsColoredYellowOnADecoratedOutput(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Warning, [TransportStats::countOnly('events', 'amqp', false, 12)]);

        self::assertStringContainsString("\033[33mwarning\033[39m", $this->render($report, true));
    }

    public function testAnOkStatusIsColoredGreenOnADecoratedOutput(): void
    {
        self::assertStringContainsString("\033[32mok\033[39m", $this->render(StatsReportFixture::healthy(), true));
    }

    public function testTheTransportTableCarriesOneRowPerQueueAndOneRowPerTransportWithout(): void
    {
        $rows = $this->rowsOf($this->render(StatsReportFixture::specExample()));

        self::assertContains(['Transport', 'Kind', 'Stats', 'Queue', 'Pending', 'Delayed', 'In progress', 'Stuck', 'Oldest pending (s)', 'Count'], $rows);
        self::assertContains(['async_payments', 'doctrine', 'full', 'payments', '42', '3', '1', '0', '900', '46'], $rows);
        self::assertContains(['failed', 'doctrine', 'full', '-', '-', '-', '-', '-', '-', '7'], $rows);
        self::assertContains(['events', 'amqp', 'count only', '-', '-', '-', '-', '-', '-', '12'], $rows);
        self::assertContains(['reporting', 'doctrine', 'unavailable', 'unavailable: Doctrine\DBAL\Exception\ConnectionException', '-', '-', '-', '-', '-', ''], $rows);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with(trim($line), '|')) {
                $rows[] = $this->cellsOf($line);
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function cellsOf(string $line): array
    {
        $cells = explode('|', trim($line));
        array_shift($cells);
        array_pop($cells);

        return array_values(array_map('trim', $cells));
    }

    public function testTheTransportNameIsWrittenOnlyOnTheFirstQueueRow(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Ok, [
            TransportStats::full('async', 'doctrine', [
                new QueueStats('default', 1, 0, 0, 0, 5, [], false),
                new QueueStats('slow', 2, 0, 0, 0, 7, [], false),
            ]),
        ]);

        $rows = $this->rowsOf($this->render($report));

        self::assertContains(['async', 'doctrine', 'full', 'default', '1', '0', '0', '0', '5', '3'], $rows);
        self::assertContains(['', 'doctrine', 'full', 'slow', '2', '0', '0', '0', '7', '3'], $rows);
    }

    public function testAQueueWithoutPendingMessagesAndATransportWithoutCountAreRenderedAsDashes(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Ok, [
            TransportStats::full('async', 'doctrine', [new QueueStats('default', 0, 0, 0, 0, null, [], false)]),
            TransportStats::countOnly('events', 'amqp', false, null),
        ]);

        $rows = $this->rowsOf($this->render($report));

        self::assertContains(['async', 'doctrine', 'full', 'default', '0', '0', '0', '0', '-', '0'], $rows);
        self::assertContains(['events', 'amqp', 'count only', '-', '-', '-', '-', '-', '-', '-'], $rows);
    }

    public function testAFullTransportWithoutQueuesIsStillRenderedAsOneRow(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Ok, [TransportStats::full('async', 'doctrine', [])]);

        self::assertContains(['async', 'doctrine', 'full', '-', '-', '-', '-', '-', '-', '0'], $this->rowsOf($this->render($report)));
    }

    public function testTheFailuresOfAFailureTransportAreRenderedInTheirOwnTable(): void
    {
        $rendered = $this->render(StatsReportFixture::specExample());
        $rows = $this->rowsOf($rendered);

        self::assertStringContainsString('Failures on failed', $rendered);
        self::assertContains(['Message class', 'Exception', 'Message', 'Failed at', 'Retries', 'Original transport'], $rows);
        self::assertContains([
            'App\Message\SendEmail',
            'Symfony\Component\Mailer\Exception\TransportException',
            'Connection refused',
            '2026-09-17T10:00:00+00:00',
            '3',
            'async',
        ], $rows);
    }

    public function testTheUnknownDetailsOfAFailedMessageAreRenderedAsDashes(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Ok, [
            TransportStats::failure('failed', 'doctrine', 1, new ClassBreakdown([], false), [new FailedMessage('App\Message\SendEmail', null, null, null, 0, null)]),
        ]);

        self::assertContains(['App\Message\SendEmail', '-', '-', '-', '0', '-'], $this->rowsOf($this->render($report)));
    }

    public function testAFailureTransportWithoutFailuresHasNoFailuresTable(): void
    {
        $report = StatsReportFixture::of(HealthStatus::Ok, [TransportStats::failure('failed', 'doctrine', 0, new ClassBreakdown([], false), [])]);

        self::assertStringNotContainsString('Failures on', $this->render($report));
    }

    public function testTheProblemsAreRenderedInTheirOwnTable(): void
    {
        $rendered = $this->render(StatsReportFixture::specExample());
        $rows = $this->rowsOf($rendered);

        self::assertStringContainsString('Problems', $rendered);
        self::assertContains(['Level', 'Transport', 'Metric', 'Value', 'Threshold'], $rows);
        self::assertContains(['critical', 'async_payments', 'oldest_pending_age_seconds', '900', '600'], $rows);
    }

    public function testAReportWithoutProblemsSaysSo(): void
    {
        $rendered = $this->render(StatsReportFixture::healthy());

        self::assertStringContainsString('No problems.', $rendered);
        self::assertStringNotContainsString('Threshold', $rendered);
    }
}
