<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Exception\UnknownTransportException;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;

final class TransportDefinitionRegistryTest extends TestCase
{
    public function testAllKeepsTheConfiguredOrder(): void
    {
        $registry = $this->registry();

        self::assertSame(['async', 'failed', 'events'], array_map(static fn ($transport): string => $transport->name, $registry->all()));
    }

    public function testDefinitionIsBuiltFromTheParameter(): void
    {
        $async = $this->registry()->get('async');

        self::assertSame('async', $async->name);
        self::assertSame('doctrine://default', $async->dsn);
        self::assertSame('doctrine', $async->kind);
        self::assertSame(['transport_name' => 'async'], $async->options);
        self::assertFalse($async->isFailureTransport);
        self::assertSame('messenger.default_serializer', $async->serializerServiceId);
    }

    public function testFailureTransports(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->get('failed')->isFailureTransport);
        self::assertSame('acme.json_serializer', $registry->get('failed')->serializerServiceId);
        self::assertSame(['failed'], $registry->failureTransportNames());
    }

    public function testKindIsDerivedFromTheResolvedDsn(): void
    {
        $registry = new TransportDefinitionRegistry([
            'env_dsn' => ['dsn' => 'doctrine://default?queue_name=envq', 'options' => [], 'kind' => 'unknown', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
        ]);

        self::assertSame('doctrine', $registry->get('env_dsn')->kind);
    }

    public function testKindStaysUnknownWhenTheResolvedDsnHasNoScheme(): void
    {
        $registry = new TransportDefinitionRegistry([
            'env_dsn' => ['dsn' => 'not-a-dsn', 'options' => [], 'kind' => 'unknown', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
        ]);

        self::assertSame('unknown', $registry->get('env_dsn')->kind);
    }

    public function testUnknownTransport(): void
    {
        $this->expectException(UnknownTransportException::class);
        $this->expectExceptionMessage('Unknown transport "payments", known transports are: async, failed, events.');

        $this->registry()->get('payments');
    }

    public function testEmptyRegistry(): void
    {
        $registry = new TransportDefinitionRegistry([]);

        self::assertSame([], $registry->all());
        self::assertSame([], $registry->failureTransportNames());

        $this->expectException(UnknownTransportException::class);
        $this->expectExceptionMessage('known transports are: none.');

        $registry->get('async');
    }

    private function registry(): TransportDefinitionRegistry
    {
        return new TransportDefinitionRegistry([
            'async' => ['dsn' => 'doctrine://default', 'options' => ['transport_name' => 'async'], 'kind' => 'doctrine', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
            'failed' => ['dsn' => 'doctrine://default?queue_name=failed', 'options' => [], 'kind' => 'doctrine', 'is_failure_transport' => true, 'serializer' => 'acme.json_serializer'],
            'events' => ['dsn' => 'amqp://guest@localhost', 'options' => [], 'kind' => 'amqp', 'is_failure_transport' => false, 'serializer' => 'messenger.default_serializer'],
        ]);
    }
}
