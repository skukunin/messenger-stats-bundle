<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Http\NoStoreResponseFactory;
use Skukunin\MessengerStatsBundle\Http\StatsController;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportBuilderFixture;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;
use Symfony\Component\HttpFoundation\Response;

final class StatsControllerTest extends TestCase
{
    public function testTheReportIsAnsweredAsJsonThatIsNeverStored(): void
    {
        $response = $this->response();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function response(): Response
    {
        $controller = new StatsController(
            StatsReportBuilderFixture::returning($this->failedTransport()),
            new JsonReportView(new ProblemView()),
            new NoStoreResponseFactory(),
        );

        return $controller();
    }

    private function failedTransport(): TransportStats
    {
        return TransportStats::failure('failed', 'doctrine', 1, new ClassBreakdown(['App\Message\SendEmail' => 1], false), [
            new FailedMessage('App\Message\SendEmail', 'RuntimeException', 'Connection to tcp://db:5432/app refused', null, 0, null),
        ]);
    }

    public function testTheCacheControlHeaderCarriesTheDirectiveSymfonyComputesAroundNoStore(): void
    {
        self::assertSame('no-store, private', $this->response()->headers->get('Cache-Control'));
    }

    public function testSlashesAreNotEscapedInTheBody(): void
    {
        $body = (string) $this->response()->getContent();

        self::assertStringContainsString('"exception_message":"Connection to tcp://db:5432/app refused"', $body);
    }

    public function testTheBodyIsTheRenderedDocument(): void
    {
        $document = json_decode((string) $this->response()->getContent(), true);

        self::assertIsArray($document);
        self::assertSame('1', $document['schema_version']);
        self::assertIsArray($document['transports']);
        self::assertArrayHasKey('failed', $document['transports']);
    }
}
