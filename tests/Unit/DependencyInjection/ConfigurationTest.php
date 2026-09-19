<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertNull($config['token']);
        self::assertSame([], $config['allowed_ips']);
        self::assertNull($config['app_name']);
        self::assertSame([], $config['exclude']);
        self::assertNull($config['stuck_after_seconds']);
        self::assertSame(1000, $config['class_breakdown_sample_size']);
        self::assertSame(['limit' => 10, 'expose_message' => true], $config['failures']);
        self::assertSame([], $config['thresholds']);
    }

    public function testEmptyTokenIsNormalisedToNull(): void
    {
        self::assertNull($this->process(['token' => ''])['token']);
    }

    public function testTokenAcceptsAnEnvPlaceholderThatMayResolveToAnyType(): void
    {
        BaseNode::setPlaceholder('env_token_placeholder', ['bool' => false, 'int' => 0, 'float' => 0.0, 'string' => '', 'array' => []]);

        try {
            $config = $this->process(['token' => 'env_token_placeholder']);
        } finally {
            BaseNode::resetPlaceholders();
        }

        self::assertSame('env_token_placeholder', $config['token']);
    }

    public function testExplicitNullsAreAccepted(): void
    {
        $config = $this->process([
            'token' => null,
            'app_name' => null,
            'stuck_after_seconds' => null,
            'thresholds' => ['async' => ['pending' => ['warning' => null, 'critical' => 500]]],
        ]);

        self::assertNull($config['stuck_after_seconds']);
        self::assertEquals(['async' => ['pending' => ['warning' => null, 'critical' => 500]]], $config['thresholds']);
    }

    public function testScalarValuesAreKept(): void
    {
        $config = $this->process([
            'token' => 'secret',
            'allowed_ips' => ['10.0.0.1', '192.168.0.0/24'],
            'app_name' => 'billing',
            'exclude' => ['sync'],
            'stuck_after_seconds' => 3600,
            'class_breakdown_sample_size' => 50,
            'failures' => ['limit' => 3, 'expose_message' => false],
        ]);

        self::assertSame('secret', $config['token']);
        self::assertSame(['10.0.0.1', '192.168.0.0/24'], $config['allowed_ips']);
        self::assertSame('billing', $config['app_name']);
        self::assertSame(['sync'], $config['exclude']);
        self::assertSame(3600, $config['stuck_after_seconds']);
        self::assertSame(50, $config['class_breakdown_sample_size']);
        self::assertSame(['limit' => 3, 'expose_message' => false], $config['failures']);
    }

    /**
     * @dataProvider validMetrics
     */
    public function testThresholdsAreAcceptedForEveryValidMetric(string $metric): void
    {
        $config = $this->process([
            'thresholds' => ['async' => [$metric => ['warning' => 10, 'critical' => 20]]],
        ]);

        self::assertEquals(['async' => [$metric => ['warning' => 10, 'critical' => 20]]], $config['thresholds']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validMetrics(): iterable
    {
        foreach (['pending', 'delayed', 'in_progress', 'stuck', 'oldest_pending_age_seconds', 'failed', 'count'] as $metric) {
            yield $metric => [$metric];
        }
    }

    public function testAnyTransportNameIsAccepted(): void
    {
        $config = $this->process([
            'thresholds' => ['not_a_configured_transport' => ['pending' => ['critical' => 1]]],
        ]);

        self::assertEquals(['not_a_configured_transport' => ['pending' => ['warning' => null, 'critical' => 1]]], $config['thresholds']);
    }

    public function testTransportNamesAreNotNormalised(): void
    {
        $config = $this->process(['thresholds' => ['async-high' => ['pending' => ['critical' => 1]]]]);

        self::assertEquals(['async-high' => ['pending' => ['warning' => null, 'critical' => 1]]], $config['thresholds']);
    }

    public function testUnknownMetricIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['thresholds' => ['async' => ['throughput' => ['warning' => 1]]]]);
    }

    public function testWarningAboveCriticalIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['thresholds' => ['async' => ['pending' => ['warning' => 20, 'critical' => 10]]]]);
    }

    public function testWarningEqualToCriticalIsAccepted(): void
    {
        $config = $this->process(['thresholds' => ['async' => ['pending' => ['warning' => 10, 'critical' => 10]]]]);

        self::assertEquals(['async' => ['pending' => ['warning' => 10, 'critical' => 10]]], $config['thresholds']);
    }

    public function testZeroSampleSizeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['class_breakdown_sample_size' => 0]);
    }

    public function testZeroFailureLimitIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['failures' => ['limit' => 0]]);
    }

    public function testNegativeThresholdIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['thresholds' => ['async' => ['pending' => ['warning' => -1]]]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
