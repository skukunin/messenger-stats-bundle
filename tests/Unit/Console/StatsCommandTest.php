<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Console\StatsCommand;
use Skukunin\MessengerStatsBundle\Console\TableRenderer;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportBuilderFixture;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportFixture;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StatsCommandTest extends TestCase
{
    public function testTheJsonFormatWritesTheRenderedDocument(): void
    {
        $builder = $this->criticalBuilder();
        $tester = $this->tester($builder);

        $tester->execute(['--format' => 'json']);

        $expected = json_encode((new JsonReportView(new ProblemView()))->render($builder->build()), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        self::assertSame($expected."\n", $tester->getDisplay());
    }

    private function criticalBuilder(): StatsReportBuilder
    {
        return StatsReportBuilderFixture::returningAll([
            TransportStats::full('async_payments', 'doctrine', [
                new QueueStats('payments', 42, 3, 1, 0, 900, ['App\Message\RecurringPaymentMessage' => 46], false),
            ]),
            TransportStats::failure('failed', 'doctrine', 7, new ClassBreakdown(['App\Message\SendEmail' => 7], false), [
                new FailedMessage(
                    'App\Message\SendEmail',
                    'Symfony\Component\Mailer\Exception\TransportException',
                    'Connection refused',
                    new DateTimeImmutable(StatsReportFixture::FAILED_AT_UTC),
                    3,
                    'async',
                ),
            ]),
            TransportStats::countOnly('events', 'amqp', false, 12),
        ], ['async_payments' => ['oldest_pending_age_seconds' => ['warning' => null, 'critical' => 600]]]);
    }

    private function tester(StatsReportBuilder $builder): CommandTester
    {
        return new CommandTester(new StatsCommand($builder, new JsonReportView(new ProblemView()), new TableRenderer()));
    }

    public function testTheJsonFormatDoesNotEscapeSlashes(): void
    {
        $tester = $this->tester($this->criticalBuilder());

        $tester->execute(['--format' => 'json']);

        self::assertStringContainsString('"App\\\\Message\\\\SendEmail"', $tester->getDisplay());
        self::assertStringNotContainsString('\/', $tester->getDisplay());
    }

    public function testTheTableFormatIsTheDefaultAndRendersEverySection(): void
    {
        $tester = $this->tester($this->criticalBuilder());

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('App: shop  Env: prod  Generated: 2026-09-18T10:00:00+00:00  Status: critical', $display);
        self::assertStringContainsString('async_payments', $display);
        self::assertStringContainsString('events', $display);
        self::assertStringContainsString('Failures on failed', $display);
        self::assertStringContainsString('Problems', $display);
        self::assertStringContainsString('oldest_pending_age_seconds', $display);
    }

    public function testACriticalReportFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester($this->criticalBuilder())->execute([]));
    }

    public function testAWarningReportSucceeds(): void
    {
        $builder = StatsReportBuilderFixture::returning(TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException'));

        self::assertSame(Command::SUCCESS, $this->tester($builder)->execute([]));
    }

    public function testAnOkReportSucceeds(): void
    {
        $builder = StatsReportBuilderFixture::returning(TransportStats::countOnly('events', 'amqp', false, 12));
        $tester = $this->tester($builder);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No problems.', $tester->getDisplay());
    }

    public function testAnUnknownFormatIsRejected(): void
    {
        $tester = $this->tester($this->criticalBuilder());

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('Unknown format "xml"', $tester->getDisplay());
    }
}
