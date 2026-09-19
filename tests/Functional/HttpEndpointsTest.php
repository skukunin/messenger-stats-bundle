<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Http\MetricsController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpEndpointsTest extends TestCase
{
    private const TOKEN = 'a-very-secret-token';
    private const ENV_TRANSPORT_DSN_VALUE = 'doctrine://default?queue_name=envq';
    private const APP = 'shop';
    private const PATHS = ['/_messenger/stats', '/_messenger/health', '/_messenger/metrics'];

    /**
     * @var array<string, TestKernel>
     */
    private array $kernels = [];

    protected function setUp(): void
    {
        $_SERVER[TestKernel::ENV_TRANSPORT_DSN] = self::ENV_TRANSPORT_DSN_VALUE;
        putenv(TestKernel::ENV_TRANSPORT_DSN.'='.self::ENV_TRANSPORT_DSN_VALUE);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->kernels as $kernel) {
            $cacheDir = $kernel->getCacheDir();
            $kernel->shutdown();
            $filesystem->remove($cacheDir);
        }

        $this->kernels = [];
        unset($_SERVER[TestKernel::ENV_TRANSPORT_DSN]);
        putenv(TestKernel::ENV_TRANSPORT_DSN);
    }

    public function testAnUnconfiguredTokenHidesEveryRoute(): void
    {
        foreach (self::PATHS as $path) {
            $response = $this->get($path, []);

            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $path);
            self::assertSame('', $response->getContent(), $path);
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $path);
        }
    }

    /**
     * @param array<string, mixed>  $statsConfig
     * @param array<string, string> $server
     */
    private function get(string $path, array $statsConfig, array $server = []): Response
    {
        $kernel = $this->kernelFor($statsConfig);

        return $kernel->handle(Request::create($path, 'GET', [], [], [], $server), HttpKernelInterface::MAIN_REQUEST, false);
    }

    /**
     * @param array<string, mixed> $statsConfig
     */
    private function kernelFor(array $statsConfig): TestKernel
    {
        $key = serialize($statsConfig);
        if (!isset($this->kernels[$key])) {
            $this->kernels[$key] = new TestKernel($statsConfig);
            $this->kernels[$key]->boot();
        }

        return $this->kernels[$key];
    }

    public function testATokenReadFromAnUnsetEnvVariableWithDefaultHidesTheRoutes(): void
    {
        putenv('MESSENGER_STATS_TOKEN');
        unset($_SERVER['MESSENGER_STATS_TOKEN'], $_ENV['MESSENGER_STATS_TOKEN']);

        $response = $this->get('/_messenger/stats', ['token' => '%env(default::MESSENGER_STATS_TOKEN)%']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testATokenReadFromAnEnvVariableWithDefaultAuthenticates(): void
    {
        putenv('MESSENGER_STATS_TOKEN='.self::TOKEN);
        $_SERVER['MESSENGER_STATS_TOKEN'] = self::TOKEN;

        try {
            $response = $this->get('/_messenger/stats', ['token' => '%env(default::MESSENGER_STATS_TOKEN)%', 'app_name' => self::APP], $this->authorization());
        } finally {
            putenv('MESSENGER_STATS_TOKEN');
            unset($_SERVER['MESSENGER_STATS_TOKEN']);
        }

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAMissingAuthorizationHeaderIsRejectedOnEveryRoute(): void
    {
        foreach (self::PATHS as $path) {
            $response = $this->get($path, $this->config());

            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $path);
            self::assertSame('{"error":"unauthorized"}', $response->getContent(), $path);
            self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'), $path);
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $path);
        }
    }

    /**
     * @param array<string, mixed> $statsConfig
     *
     * @return array<string, mixed>
     */
    private function config(array $statsConfig = []): array
    {
        return ['token' => self::TOKEN, 'app_name' => self::APP] + $statsConfig;
    }

    public function testAWrongTokenIsRejected(): void
    {
        $response = $this->get('/_messenger/stats', $this->config(), $this->authorization('wrong-token'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function authorization(string $token = self::TOKEN, string $clientIp = '127.0.0.1'): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'REMOTE_ADDR' => $clientIp];
    }

    public function testTheStatsRouteReportsEveryTransportAtItsOwnDetailLevel(): void
    {
        $response = $this->get('/_messenger/stats', $this->config(), $this->authorization());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $document = $this->documentOf($response);
        self::assertSame('1', $document['schema_version']);
        self::assertSame(self::APP, $document['app']);
        self::assertSame('test', $document['env']);

        $transports = $this->arrayOf($document['transports']);
        self::assertSame(['async', 'payments', 'failed', 'retry', 'env_dsn', 'fake', 'broken'], array_keys($transports));
        self::assertSame('full', $this->arrayOf($transports['async'])['detail_level']);
        self::assertSame('full', $this->arrayOf($transports['failed'])['detail_level']);
        self::assertSame(['kind', 'detail_level', 'is_failure_transport', 'count', 'class_breakdown', 'class_breakdown_sampled', 'failures'], array_keys($this->arrayOf($transports['failed'])));
        self::assertSame('count', $this->arrayOf($transports['fake'])['detail_level']);
        self::assertSame('unavailable', $this->arrayOf($transports['broken'])['detail_level']);
        self::assertNull($this->arrayOf($transports['broken'])['count']);
        self::assertArrayHasKey('error', $this->arrayOf($transports['broken']));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function documentOf(Response $response): array
    {
        return $this->arrayOf(json_decode((string) $response->getContent(), true));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    public function testTheHealthRouteAnswersOkWhileOnlyATransportIsUnavailable(): void
    {
        $response = $this->get('/_messenger/health', $this->config(), $this->authorization());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('warning', $this->documentOf($response)['status']);
    }

    public function testTheHealthRouteAnswersServiceUnavailableWhenAThresholdTurnsTheReportCritical(): void
    {
        $config = $this->config(['thresholds' => ['broken' => ['count' => ['critical' => 1]]]]);
        $response = $this->get('/_messenger/health', $config, $this->authorization());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('critical', $this->documentOf($response)['status']);
    }

    public function testTheMetricsRouteExposesThePrometheusFamilies(): void
    {
        $response = $this->get('/_messenger/metrics', $this->config(), $this->authorization());
        $body = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(MetricsController::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('messenger_transport_up{app="shop",env="test",transport="broken"} 0', $body);
        self::assertStringContainsString('messenger_transport_up{app="shop",env="test",transport="async"} 1', $body);
        self::assertStringContainsString('messenger_health_status{app="shop",env="test"} 1', $body);
    }

    public function testAClientOutsideTheAllowedIpsIsForbidden(): void
    {
        $config = $this->config(['allowed_ips' => ['10.0.0.0/8']]);
        $response = $this->get('/_messenger/stats', $config, $this->authorization());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAClientInsideTheAllowedIpsIsServed(): void
    {
        $config = $this->config(['allowed_ips' => ['10.0.0.0/8']]);
        $response = $this->get('/_messenger/stats', $config, $this->authorization(self::TOKEN, '10.1.2.3'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
