<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Exception\InvalidArgumentException;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\DoctrineTransportSettings;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class TransportDiscoveryTest extends TestCase
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

    public function testItDiscoversEveryTransportButTheSynchronousOnes(): void
    {
        $names = array_map(static fn (TransportDefinition $transport): string => $transport->name, $this->registry()->all());

        self::assertSame(['async', 'payments', 'failed', 'retry', 'env_dsn', 'fake', 'broken'], $names);
    }

    public function testOnlyTheConfiguredFailureTransportIsMarked(): void
    {
        $registry = $this->registry();

        self::assertSame(['failed'], $registry->failureTransportNames());
        self::assertFalse($registry->get('async')->isFailureTransport);
    }

    public function testDoctrineSettingsOfEveryTransport(): void
    {
        $expected = [
            'async' => ['default', 3600],
            'payments' => ['payments', 3600],
            'failed' => ['failed', 3600],
            'retry' => ['retry', 60],
        ];

        foreach ($expected as $name => [$queueName, $redeliverTimeout]) {
            $settings = $this->settingsOf($name);

            self::assertSame('default', $settings->connectionName, $name);
            self::assertSame('messenger_messages', $settings->tableName, $name);
            self::assertSame($queueName, $settings->queueName, $name);
            self::assertSame($redeliverTimeout, $settings->redeliverTimeout, $name);
        }
    }

    public function testEnvPlaceholderDsnIsResolvedAtRuntime(): void
    {
        $definition = $this->registry()->get('env_dsn');

        self::assertSame(self::ENV_TRANSPORT_DSN_VALUE, $definition->dsn);
        self::assertSame('doctrine', $definition->kind);
        self::assertSame('envq', $this->settingsOf('env_dsn')->queueName);
    }

    public function testExcludedTransportsAreNotDiscovered(): void
    {
        $names = array_map(static fn (TransportDefinition $transport): string => $transport->name, $this->registry(['exclude' => ['retry']])->all());

        self::assertSame(['async', 'payments', 'failed', 'env_dsn', 'fake', 'broken'], $names);
    }

    public function testThresholdOnAnUnknownTransportFailsTheBoot(): void
    {
        $kernel = $this->kernel = new TestKernel(['thresholds' => ['nonexistent' => ['pending' => ['critical' => 1]]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transport "nonexistent" configured in "messenger_stats.thresholds"');

        $kernel->boot();
    }

    public function testAQueueStateThresholdOnTheFailureTransportFailsTheBoot(): void
    {
        $kernel = $this->kernel = new TestKernel(['thresholds' => ['failed' => ['stuck' => ['critical' => 1]]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold "stuck" configured in "messenger_stats.thresholds" for the failure transport "failed" is not reported for a failure transport, allowed metrics are: failed, count.');

        $kernel->boot();
    }

    public function testThresholdOnAnExcludedTransportFailsTheBoot(): void
    {
        $kernel = $this->kernel = new TestKernel(['exclude' => ['retry'], 'thresholds' => ['retry' => ['pending' => ['critical' => 1]]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transport "retry" configured in "messenger_stats.thresholds"');

        $kernel->boot();
    }

    /**
     * @param array<string, mixed> $statsConfig
     */
    private function registry(array $statsConfig = []): TransportDefinitionRegistry
    {
        $registry = $this->boot($statsConfig)->get(TransportDefinitionRegistry::class);
        self::assertInstanceOf(TransportDefinitionRegistry::class, $registry);

        return $registry;
    }

    /**
     * @param array<string, mixed> $statsConfig
     */
    private function boot(array $statsConfig = []): ContainerInterface
    {
        $this->kernel ??= new TestKernel($statsConfig);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }

    private function settingsOf(string $name): DoctrineTransportSettings
    {
        $parser = $this->boot()->get(DoctrineDsnParser::class);
        self::assertInstanceOf(DoctrineDsnParser::class, $parser);

        return $parser->parse($this->registry()->get($name));
    }
}
