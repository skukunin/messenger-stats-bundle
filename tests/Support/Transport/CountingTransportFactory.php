<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support\Transport;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class CountingTransportFactory
{
    public const DSN = 'fake://';
    public const MESSAGE_COUNT = 5;

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): CountingTransport
    {
        return new CountingTransport(self::MESSAGE_COUNT);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::DSN);
    }
}
