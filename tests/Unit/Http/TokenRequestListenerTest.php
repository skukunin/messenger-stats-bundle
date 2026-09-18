<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Skukunin\MessengerStatsBundle\Http\NoStoreResponseFactory;
use Skukunin\MessengerStatsBundle\Http\TokenRequestListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TokenRequestListenerTest extends TestCase
{
    private const TOKEN = 'a-very-secret-token';

    public function testARouteOfAnotherBundleIsNeverTouched(): void
    {
        self::assertNull($this->handle($this->request('app_homepage')));
    }

    /**
     * @param list<string> $allowedIps
     */
    private function handle(Request $request, ?string $token = self::TOKEN, array $allowedIps = [], ?LoggerInterface $logger = null): ?Response
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener = new TokenRequestListener($token, $allowedIps, new NoStoreResponseFactory(), $logger);
        $listener($event);

        return $event->getResponse();
    }

    private function request(?string $route = 'messenger_stats_stats', ?string $authorization = null, string $clientIp = '127.0.0.1'): Request
    {
        $request = Request::create('/_messenger/stats', 'GET', [], [], [], ['REMOTE_ADDR' => $clientIp]);
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        if (null !== $authorization) {
            $request->headers->set('Authorization', $authorization);
        }

        return $request;
    }

    public function testARequestWithoutAMatchedRouteIsNeverTouched(): void
    {
        self::assertNull($this->handle($this->request(null)));
    }

    public function testAnUnconfiguredTokenAnswersNotFoundWithAnEmptyBody(): void
    {
        $response = $this->handle($this->request(), null);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAnEmptyTokenAnswersNotFoundEvenWithACorrectHeader(): void
    {
        $response = $this->handle($this->request('messenger_stats_metrics', 'Bearer '), '');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAMissingAuthorizationHeaderIsUnauthorized(): void
    {
        $response = $this->handle($this->request());

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('{"error":"unauthorized"}', $response->getContent());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAnotherAuthenticationSchemeIsUnauthorized(): void
    {
        $response = $this->handle($this->request('messenger_stats_stats', 'Basic '.base64_encode('user:'.self::TOKEN)));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAWrongTokenIsUnauthorizedAndLoggedWithTheClientIp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::isType('string'), self::callback(static fn (array $context): bool => '10.1.2.3' === $context['ip']));

        $response = $this->handle($this->request('messenger_stats_stats', 'Bearer wrong', '10.1.2.3'), self::TOKEN, [], $logger);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testTheCorrectTokenPassesThrough(): void
    {
        self::assertNull($this->handle($this->request('messenger_stats_health', 'Bearer '.self::TOKEN)));
    }

    public function testTheAuthenticationSchemeIsCaseInsensitive(): void
    {
        self::assertNull($this->handle($this->request('messenger_stats_stats', 'bearer '.self::TOKEN)));
    }

    public function testAClientOutsideTheAllowedIpsIsForbiddenAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::isType('string'), self::callback(static fn (array $context): bool => '127.0.0.1' === $context['ip']));

        $response = $this->handle($this->request('messenger_stats_stats', 'Bearer '.self::TOKEN), self::TOKEN, ['10.0.0.0/8'], $logger);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAClientInsideAnAllowedCidrPassesThrough(): void
    {
        self::assertNull($this->handle($this->request('messenger_stats_stats', 'Bearer '.self::TOKEN, '10.1.2.3'), self::TOKEN, ['10.0.0.0/8']));
    }

    public function testTheTokenIsCheckedBeforeTheAllowedIps(): void
    {
        $response = $this->handle($this->request('messenger_stats_stats', 'Bearer wrong'), self::TOKEN, ['10.0.0.0/8']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
