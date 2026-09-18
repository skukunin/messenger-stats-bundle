<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Http\MetricsController;
use Skukunin\MessengerStatsBundle\Http\NoStoreResponseFactory;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportBuilderFixture;
use Skukunin\MessengerStatsBundle\View\PrometheusReportView;
use Symfony\Component\HttpFoundation\Response;

final class MetricsControllerTest extends TestCase
{
    public function testTheExpositionIsAnsweredAsPlainTextThatIsNeverStored(): void
    {
        $response = $this->response();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function response(): Response
    {
        $controller = new MetricsController(
            StatsReportBuilderFixture::returning(TransportStats::countOnly('events', 'amqp', false, 12)),
            new PrometheusReportView(),
            new NoStoreResponseFactory(),
        );

        return $controller();
    }

    public function testTheBodyIsTheRenderedExposition(): void
    {
        $body = (string) $this->response()->getContent();

        self::assertStringStartsWith('# HELP messenger_transport_up', $body);
        self::assertStringContainsString('messenger_transport_messages{app="shop",env="prod",transport="events"} 12', $body);
        self::assertStringEndsWith("messenger_health_status{app=\"shop\",env=\"prod\"} 0\n", $body);
    }
}
