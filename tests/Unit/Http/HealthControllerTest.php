<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Http\HealthController;
use Skukunin\MessengerStatsBundle\Http\NoStoreResponseFactory;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportBuilderFixture;
use Skukunin\MessengerStatsBundle\View\HealthReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends TestCase
{
    public function testAHealthyReportIsAnsweredWithOk(): void
    {
        $response = $this->response(TransportStats::countOnly('events', 'amqp', false, 12));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('{"status":"ok","problems":[]}', $response->getContent());
    }

    /**
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    private function response(TransportStats $stats, array $thresholds = []): Response
    {
        $controller = new HealthController(
            StatsReportBuilderFixture::returning($stats, $thresholds),
            new HealthReportView(new ProblemView()),
            new NoStoreResponseFactory(),
        );

        return $controller();
    }

    public function testAWarningIsStillAnsweredWithOk(): void
    {
        $response = $this->response(TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('"status":"warning"', (string) $response->getContent());
    }

    public function testACriticalReportIsAnsweredWithServiceUnavailable(): void
    {
        $response = $this->response(
            TransportStats::unavailable('reporting', 'doctrine', false, 'RuntimeException'),
            ['reporting' => ['count' => ['warning' => null, 'critical' => 1]]],
        );

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('"status":"critical"', (string) $response->getContent());
        self::assertStringContainsString('"metric":"up"', (string) $response->getContent());
    }
}
