<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportFixture;
use Skukunin\MessengerStatsBundle\View\PrometheusReportView;

final class PrometheusReportViewTest extends TestCase
{
    public function testTheWholeExpositionIsRenderedAsTheSpecificationDescribesIt(): void
    {
        $expected = <<<'TXT'
            # HELP messenger_transport_up 1 when the transport could be read.
            # TYPE messenger_transport_up gauge
            messenger_transport_up{app="shop",env="prod",transport="async_payments"} 1
            messenger_transport_up{app="shop",env="prod",transport="failed"} 1
            messenger_transport_up{app="shop",env="prod",transport="events"} 1
            messenger_transport_up{app="shop",env="prod",transport="reporting"} 0
            # HELP messenger_transport_messages Total messages in the transport.
            # TYPE messenger_transport_messages gauge
            messenger_transport_messages{app="shop",env="prod",transport="async_payments"} 46
            messenger_transport_messages{app="shop",env="prod",transport="failed"} 7
            messenger_transport_messages{app="shop",env="prod",transport="events"} 12
            # HELP messenger_queue_messages Messages per queue and state.
            # TYPE messenger_queue_messages gauge
            messenger_queue_messages{app="shop",env="prod",transport="async_payments",queue="payments",state="pending"} 42
            messenger_queue_messages{app="shop",env="prod",transport="async_payments",queue="payments",state="delayed"} 3
            messenger_queue_messages{app="shop",env="prod",transport="async_payments",queue="payments",state="in_progress"} 1
            messenger_queue_messages{app="shop",env="prod",transport="async_payments",queue="payments",state="stuck"} 0
            messenger_queue_messages{app="shop",env="prod",transport="failed",queue="failed",state="pending"} 7
            messenger_queue_messages{app="shop",env="prod",transport="failed",queue="failed",state="delayed"} 0
            messenger_queue_messages{app="shop",env="prod",transport="failed",queue="failed",state="in_progress"} 0
            messenger_queue_messages{app="shop",env="prod",transport="failed",queue="failed",state="stuck"} 0
            # HELP messenger_queue_oldest_pending_age_seconds Age of the oldest pending message.
            # TYPE messenger_queue_oldest_pending_age_seconds gauge
            messenger_queue_oldest_pending_age_seconds{app="shop",env="prod",transport="async_payments",queue="payments"} 900
            messenger_queue_oldest_pending_age_seconds{app="shop",env="prod",transport="failed",queue="failed"} 86400
            # HELP messenger_queue_class_messages Messages per class (sampled).
            # TYPE messenger_queue_class_messages gauge
            messenger_queue_class_messages{app="shop",env="prod",transport="async_payments",queue="payments",class="App\\Message\\RecurringPaymentMessage"} 46
            messenger_queue_class_messages{app="shop",env="prod",transport="failed",queue="failed",class="App\\Message\\SendEmail"} 7
            # HELP messenger_failed_messages Messages in the failure transport.
            # TYPE messenger_failed_messages gauge
            messenger_failed_messages{app="shop",env="prod",transport="failed"} 7
            # HELP messenger_health_status 0 ok, 1 warning, 2 critical.
            # TYPE messenger_health_status gauge
            messenger_health_status{app="shop",env="prod"} 2

            TXT;

        self::assertSame($expected, (new PrometheusReportView())->render(StatsReportFixture::specExample()));
    }

    public function testTheExpositionEndsWithANewline(): void
    {
        self::assertStringEndsWith("} 2\n", (new PrometheusReportView())->render(StatsReportFixture::specExample()));
    }

    public function testATransportWithoutACountExportsNoMessagesSample(): void
    {
        $exposition = $this->render([TransportStats::countOnly('events', 'amqp', false, null)]);

        self::assertStringContainsString('messenger_transport_up{app="shop",env="prod",transport="events"} 1'."\n", $exposition);
        self::assertStringNotContainsString('messenger_transport_messages', $exposition);
    }

    /**
     * @param list<TransportStats> $transports
     */
    private function render(array $transports, HealthStatus $status = HealthStatus::Ok): string
    {
        return (new PrometheusReportView())->render(StatsReportFixture::of($status, $transports));
    }

    public function testQueueFamiliesAreOmittedEntirelyWhenNoTransportReportsQueues(): void
    {
        $exposition = $this->render([TransportStats::countOnly('events', 'amqp', false, 12)]);

        self::assertStringNotContainsString('messenger_queue_messages', $exposition);
        self::assertStringNotContainsString('messenger_queue_oldest_pending_age_seconds', $exposition);
        self::assertStringNotContainsString('messenger_queue_class_messages', $exposition);
        self::assertStringNotContainsString('messenger_failed_messages', $exposition);
    }

    public function testAQueueWithoutPendingMessagesExportsNoAgeSample(): void
    {
        $exposition = $this->render([TransportStats::full('async', 'doctrine', false, [new QueueStats('default', 0, 0, 0, 0, null, [], false)], [])]);

        self::assertStringContainsString('messenger_queue_messages{app="shop",env="prod",transport="async",queue="default",state="pending"} 0', $exposition);
        self::assertStringNotContainsString('messenger_queue_oldest_pending_age_seconds', $exposition);
        self::assertStringNotContainsString('messenger_queue_class_messages', $exposition);
    }

    public function testLabelValuesAreEscaped(): void
    {
        $queue = new QueueStats('say "hi"', 1, 0, 0, 0, null, ['App\Message\Send'."\n".'Mail' => 1], false);
        $exposition = $this->render([TransportStats::full('async', 'doctrine', false, [$queue], [])]);

        self::assertStringContainsString('queue="say \"hi\"",state="pending"} 1', $exposition);
        self::assertStringContainsString('class="App\\\\Message\\\\Send\nMail"} 1', $exposition);
    }

    public function testTheHealthStatusIsExportedAsANumber(): void
    {
        self::assertStringContainsString('messenger_health_status{app="shop",env="prod"} 0', $this->render([], HealthStatus::Ok));
        self::assertStringContainsString('messenger_health_status{app="shop",env="prod"} 1', $this->render([], HealthStatus::Warning));
        self::assertStringContainsString('messenger_health_status{app="shop",env="prod"} 2', $this->render([], HealthStatus::Critical));
    }
}
