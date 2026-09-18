<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\DependencyInjection\Compiler\TransportDiscoveryPass;
use Skukunin\MessengerStatsBundle\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class TransportDiscoveryPassTest extends TestCase
{
    public function testPositionalArguments(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'async', ['doctrine://default', ['transport_name' => 'async']]);

        $transports = $this->process($container);

        self::assertSame([
            'async' => [
                'dsn' => 'doctrine://default',
                'options' => ['transport_name' => 'async'],
                'kind' => 'doctrine',
                'is_failure_transport' => false,
            ],
        ], $transports);
    }

    public function testNamedArguments(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'async', ['$dsn' => 'redis://localhost', '$options' => ['transport_name' => 'async']]);

        $transports = $this->process($container);

        self::assertSame('redis://localhost', $transports['async']['dsn']);
        self::assertSame('redis', $transports['async']['kind']);
        self::assertSame(['transport_name' => 'async'], $transports['async']['options']);
    }

    public function testSyncAndInMemoryTransportsAreSkipped(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'async', ['doctrine://default', []]);
        $this->addTransport($container, 'sync', ['sync://', []]);
        $this->addTransport($container, 'memory', ['in-memory://', []]);

        self::assertSame(['async'], array_keys($this->process($container)));
    }

    public function testExcludedTransportsAreSkipped(): void
    {
        $container = $this->container(['retry']);
        $this->addTransport($container, 'async', ['doctrine://default', []]);
        $this->addTransport($container, 'retry', ['doctrine://default', []]);

        self::assertSame(['async'], array_keys($this->process($container)));
    }

    public function testEnvPlaceholderDsnIsStoredAsIsWithAnUnknownKind(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'env_dsn', ['env_7a3f_TRANSPORT_DSN_1', []]);

        $transports = $this->process($container);

        self::assertSame('env_7a3f_TRANSPORT_DSN_1', $transports['env_dsn']['dsn']);
        self::assertSame('unknown', $transports['env_dsn']['kind']);
    }

    public function testFailureTransportFromTheTagAttribute(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'async', ['doctrine://default', []]);
        $this->addTransport($container, 'failed', ['doctrine://default', []], true);

        $transports = $this->process($container);

        self::assertFalse($transports['async']['is_failure_transport']);
        self::assertTrue($transports['failed']['is_failure_transport']);
    }

    public function testFailureTransportFromTheLocatorWhenTheTagAttributeIsMissing(): void
    {
        $container = $this->container();
        $this->addTransport($container, 'async', ['doctrine://default', []], null);
        $this->addTransport($container, 'failed', ['doctrine://default', []], null);
        $container->setDefinition('messenger.failure_transports', new Definition(ServiceLocator::class, [
            ['failed' => new Reference('messenger.transport.failed')],
        ]));

        $transports = $this->process($container);

        self::assertFalse($transports['async']['is_failure_transport']);
        self::assertTrue($transports['failed']['is_failure_transport']);
    }

    public function testUnknownThresholdTransport(): void
    {
        $container = $this->container([], ['nonexistent' => ['pending' => ['warning' => null, 'critical' => 1]]]);
        $this->addTransport($container, 'async', ['doctrine://default', []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transport "nonexistent" configured in "messenger_stats.thresholds", known transports are: async.');

        $this->process($container);
    }

    public function testThresholdOnAnExcludedTransport(): void
    {
        $container = $this->container(['retry'], ['retry' => ['pending' => ['warning' => null, 'critical' => 1]]]);
        $this->addTransport($container, 'async', ['doctrine://default', []]);
        $this->addTransport($container, 'retry', ['doctrine://default', []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transport "retry" configured in "messenger_stats.thresholds", known transports are: async.');

        $this->process($container);
    }

    public function testThresholdsOnDiscoveredTransportsArePassing(): void
    {
        $container = $this->container([], ['async' => ['pending' => ['warning' => null, 'critical' => 1]]]);
        $this->addTransport($container, 'async', ['doctrine://default', []]);

        self::assertSame(['async'], array_keys($this->process($container)));
    }

    public function testNothingHappensWhenTheBundleIsNotConfigured(): void
    {
        $container = new ContainerBuilder();
        $this->addTransport($container, 'async', ['doctrine://default', []]);

        (new TransportDiscoveryPass())->process($container);

        self::assertFalse($container->hasParameter('messenger_stats.transports'));
    }

    /**
     * @param list<string>                                                       $exclude
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    private function container(array $exclude = [], array $thresholds = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('messenger_stats.exclude', $exclude);
        $container->setParameter('messenger_stats.thresholds', $thresholds);

        return $container;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function addTransport(ContainerBuilder $container, string $name, array $arguments, ?bool $isFailureTransport = false): void
    {
        $tag = ['alias' => $name];
        if (null !== $isFailureTransport) {
            $tag['is_failure_transport'] = $isFailureTransport;
        }

        $container->setDefinition('messenger.transport.'.$name, (new Definition(TransportInterface::class))
            ->setFactory([new Reference('messenger.transport_factory'), 'createTransport'])
            ->setArguments($arguments)
            ->addTag('messenger.receiver', $tag));
    }

    /**
     * @return array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool}>
     */
    private function process(ContainerBuilder $container): array
    {
        (new TransportDiscoveryPass())->process($container);

        /** @var array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool}> $transports */
        $transports = $container->getParameter('messenger_stats.transports');

        return $transports;
    }
}
