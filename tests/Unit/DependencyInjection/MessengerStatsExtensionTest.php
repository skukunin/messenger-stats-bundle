<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Clock\Clock;
use Skukunin\MessengerStatsBundle\Clock\SystemClock;
use Skukunin\MessengerStatsBundle\DependencyInjection\MessengerStatsExtension;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessengerStatsExtensionTest extends TestCase
{
    public function testAlias(): void
    {
        self::assertSame('messenger_stats', (new MessengerStatsExtension())->getAlias());
    }

    public function testDefaultParameters(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getParameter('messenger_stats.token'));
        self::assertSame([], $container->getParameter('messenger_stats.allowed_ips'));
        self::assertNull($container->getParameter('messenger_stats.app_name'));
        self::assertSame([], $container->getParameter('messenger_stats.exclude'));
        self::assertNull($container->getParameter('messenger_stats.stuck_after_seconds'));
        self::assertSame(1000, $container->getParameter('messenger_stats.class_breakdown_sample_size'));
        self::assertSame(10, $container->getParameter('messenger_stats.failures.limit'));
        self::assertTrue($container->getParameter('messenger_stats.failures.expose_message'));
        self::assertSame([], $container->getParameter('messenger_stats.thresholds'));
    }

    public function testConfiguredParameters(): void
    {
        $container = $this->load([
            'token' => 'secret',
            'allowed_ips' => ['10.0.0.1'],
            'app_name' => 'billing',
            'exclude' => ['sync'],
            'stuck_after_seconds' => 3600,
            'class_breakdown_sample_size' => 25,
            'failures' => ['limit' => 5, 'expose_message' => false],
            'thresholds' => ['async' => ['pending' => ['warning' => 100, 'critical' => 500]]],
        ]);

        self::assertSame('secret', $container->getParameter('messenger_stats.token'));
        self::assertSame(['10.0.0.1'], $container->getParameter('messenger_stats.allowed_ips'));
        self::assertSame('billing', $container->getParameter('messenger_stats.app_name'));
        self::assertSame(['sync'], $container->getParameter('messenger_stats.exclude'));
        self::assertSame(3600, $container->getParameter('messenger_stats.stuck_after_seconds'));
        self::assertSame(25, $container->getParameter('messenger_stats.class_breakdown_sample_size'));
        self::assertSame(5, $container->getParameter('messenger_stats.failures.limit'));
        self::assertFalse($container->getParameter('messenger_stats.failures.expose_message'));
        self::assertEquals(['async' => ['pending' => ['warning' => 100, 'critical' => 500]]], $container->getParameter('messenger_stats.thresholds'));
    }

    public function testRegisteredServices(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasDefinition(SystemClock::class));
        self::assertSame(SystemClock::class, (string) $container->getAlias(Clock::class));
        self::assertTrue($container->hasDefinition(ThresholdEvaluator::class));
        self::assertSame('%messenger_stats.thresholds%', $container->getDefinition(ThresholdSet::class)->getArgument(0));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new MessengerStatsExtension())->load([$config], $container);

        return $container;
    }
}
