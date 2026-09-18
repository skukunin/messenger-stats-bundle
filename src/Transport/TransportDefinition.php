<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

final class TransportDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dsn,
        public readonly string $kind,
        public readonly array $options,
        public readonly bool $isFailureTransport,
        public readonly string $serializerServiceId,
    ) {
    }
}
