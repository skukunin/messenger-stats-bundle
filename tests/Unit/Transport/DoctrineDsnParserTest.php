<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Exception\MessengerStatsException;
use Skukunin\MessengerStatsBundle\Exception\UnsupportedTransportDsnException;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\DoctrineTransportSettings;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

final class DoctrineDsnParserTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = $this->parse('doctrine://default');

        self::assertSame('default', $settings->connectionName);
        self::assertSame('messenger_messages', $settings->tableName);
        self::assertSame('default', $settings->queueName);
        self::assertSame(3600, $settings->redeliverTimeout);
        self::assertTrue($settings->autoSetup);
    }

    public function testConnectionNameComesFromTheHost(): void
    {
        self::assertSame('reporting', $this->parse('doctrine://reporting')->connectionName);
    }

    public function testDsnWithoutAHostFallsBackToTheDefaultConnection(): void
    {
        self::assertSame('default', $this->parse('doctrine://')->connectionName);
        self::assertSame('default', $this->parse('doctrine:')->connectionName);
    }

    public function testQueryOptions(): void
    {
        $settings = $this->parse('doctrine://default?table_name=jobs&queue_name=payments&redeliver_timeout=60&auto_setup=false');

        self::assertSame('jobs', $settings->tableName);
        self::assertSame('payments', $settings->queueName);
        self::assertSame(60, $settings->redeliverTimeout);
        self::assertFalse($settings->autoSetup);
    }

    public function testOptionsFillTheGapsLeftByTheQuery(): void
    {
        $settings = $this->parse('doctrine://default?queue_name=payments', ['table_name' => 'jobs', 'redeliver_timeout' => 60]);

        self::assertSame('jobs', $settings->tableName);
        self::assertSame('payments', $settings->queueName);
        self::assertSame(60, $settings->redeliverTimeout);
    }

    public function testQueryWinsOverOptions(): void
    {
        $settings = $this->parse('doctrine://default?queue_name=fromquery', ['queue_name' => 'fromoptions']);

        self::assertSame('fromquery', $settings->queueName);
    }

    public function testUnknownOptionsAreIgnored(): void
    {
        $settings = $this->parse('doctrine://default?use_notify=false', ['transport_name' => 'async', 'unknown' => 'value']);

        self::assertSame('default', $settings->queueName);
        self::assertSame('messenger_messages', $settings->tableName);
    }

    public function testAutoSetupIsReadAsABoolean(): void
    {
        self::assertFalse($this->parse('doctrine://default', ['auto_setup' => '0'])->autoSetup);
        self::assertTrue($this->parse('doctrine://default', ['auto_setup' => true])->autoSetup);
        self::assertFalse($this->parse('doctrine://default?auto_setup=0')->autoSetup);
    }

    public function testRedeliverTimeoutIsReadAsAnInteger(): void
    {
        self::assertSame(90, $this->parse('doctrine://default', ['redeliver_timeout' => '90'])->redeliverTimeout);
    }

    public function testNonDoctrineDsn(): void
    {
        $this->expectException(UnsupportedTransportDsnException::class);
        $this->expectExceptionMessage('amqp://guest@localhost');

        $this->parse('amqp://guest@localhost');
    }

    public function testTheDsnExceptionIsABundleException(): void
    {
        $this->expectException(MessengerStatsException::class);

        $this->parse('redis://localhost');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function parse(string $dsn, array $options = []): DoctrineTransportSettings
    {
        return (new DoctrineDsnParser())->parse(new TransportDefinition('async', $dsn, 'doctrine', $options, false));
    }
}
