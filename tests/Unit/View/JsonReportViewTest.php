<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportFixture;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;

final class JsonReportViewTest extends TestCase
{
    public function testTheWholeDocumentIsRenderedAsTheSpecificationDescribesIt(): void
    {
        self::assertSame([
            'schema_version' => '1',
            'bundle_version' => '0.1.0',
            'generated_at' => '2026-09-18T10:00:00+00:00',
            'app' => 'shop',
            'env' => 'prod',
            'status' => 'critical',
            'problems' => [
                [
                    'transport' => 'async_payments',
                    'metric' => 'oldest_pending_age_seconds',
                    'value' => 900,
                    'threshold' => 600,
                    'level' => 'critical',
                ],
            ],
            'transports' => [
                'async_payments' => [
                    'kind' => 'doctrine',
                    'detail_level' => 'full',
                    'is_failure_transport' => false,
                    'count' => 46,
                    'queues' => [
                        'payments' => [
                            'pending' => 42,
                            'delayed' => 3,
                            'in_progress' => 1,
                            'stuck' => 0,
                            'oldest_pending_age_seconds' => 900,
                            'class_breakdown' => ['App\Message\RecurringPaymentMessage' => 46],
                            'class_breakdown_sampled' => false,
                        ],
                    ],
                ],
                'failed' => [
                    'kind' => 'doctrine',
                    'detail_level' => 'full',
                    'is_failure_transport' => true,
                    'count' => 7,
                    'class_breakdown' => ['App\Message\SendEmail' => 7],
                    'class_breakdown_sampled' => false,
                    'failures' => [
                        [
                            'message_class' => 'App\Message\SendEmail',
                            'exception_class' => 'Symfony\Component\Mailer\Exception\TransportException',
                            'exception_message' => 'Connection refused',
                            'failed_at' => '2026-09-17T10:00:00+00:00',
                            'retry_count' => 3,
                            'original_transport' => 'async',
                        ],
                    ],
                ],
                'events' => [
                    'kind' => 'amqp',
                    'detail_level' => 'count',
                    'is_failure_transport' => false,
                    'count' => 12,
                ],
                'reporting' => [
                    'kind' => 'doctrine',
                    'detail_level' => 'unavailable',
                    'is_failure_transport' => false,
                    'count' => null,
                    'error' => 'Doctrine\DBAL\Exception\ConnectionException',
                ],
            ],
        ], $this->view()->render(StatsReportFixture::specExample()));
    }

    private function view(): JsonReportView
    {
        return new JsonReportView(new ProblemView());
    }

    public function testACountOnlyTransportCarriesNeitherQueuesNorFailuresNorError(): void
    {
        $transport = $this->renderedTransportOf(TransportStats::countOnly('events', 'amqp', false, 12));

        self::assertSame(['kind', 'detail_level', 'is_failure_transport', 'count'], array_keys($transport));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function renderedTransportOf(TransportStats $stats): array
    {
        $document = $this->view()->render(StatsReportFixture::of(HealthStatus::Ok, [$stats]));
        self::assertIsArray($document['transports']);
        $transport = $document['transports'][$stats->name];
        self::assertIsArray($transport);

        return $transport;
    }

    public function testACountOnlyTransportWithoutACountKeepsTheCountKey(): void
    {
        self::assertNull($this->renderedTransportOf(TransportStats::countOnly('events', 'amqp', false, null))['count']);
    }

    public function testAFullNonFailureTransportCarriesQueuesButNoFailures(): void
    {
        $transport = $this->renderedTransportOf(TransportStats::full('async', 'doctrine', [$this->queue()]));

        self::assertSame(['kind', 'detail_level', 'is_failure_transport', 'count', 'queues'], array_keys($transport));
    }

    private function queue(): QueueStats
    {
        return new QueueStats('default', 1, 0, 0, 0, null, [], false);
    }

    public function testAFullFailureTransportCarriesAnEmptyFailureListRatherThanNoKey(): void
    {
        $transport = $this->renderedTransportOf(TransportStats::failure('failed', 'doctrine', 0, new ClassBreakdown([], false), []));

        self::assertSame(['kind', 'detail_level', 'is_failure_transport', 'count', 'class_breakdown', 'class_breakdown_sampled', 'failures'], array_keys($transport));
        self::assertSame([], $transport['failures']);
    }

    public function testAnUnavailableTransportCarriesANullCountAndTheErrorClass(): void
    {
        $transport = $this->renderedTransportOf(TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException'));

        self::assertSame(['kind', 'detail_level', 'is_failure_transport', 'count', 'error'], array_keys($transport));
        self::assertNull($transport['count']);
        self::assertSame('RuntimeException', $transport['error']);
    }

    public function testAnEmptyQueueKeepsTheNullAgeAndAnEmptyClassBreakdown(): void
    {
        $transport = $this->renderedTransportOf(TransportStats::full('async', 'doctrine', [$this->queue()]));
        self::assertIsArray($transport['queues']);
        $queue = $transport['queues']['default'];

        self::assertIsArray($queue);
        self::assertNull($queue['oldest_pending_age_seconds']);
        self::assertSame([], $queue['class_breakdown']);
        self::assertSame('{"default":[]}', json_encode(['default' => $queue['class_breakdown']]));
    }

    public function testDatesAreRenderedInUtcEvenWhenTheReportCarriesAnotherTimeZone(): void
    {
        $document = $this->view()->render(StatsReportFixture::specExample());

        self::assertSame('2026-09-18T10:00:00+00:00', $document['generated_at']);
    }

    public function testAReportWithoutProblemsRendersAnEmptyProblemList(): void
    {
        self::assertSame([], $this->view()->render(StatsReportFixture::healthy())['problems']);
    }
}
