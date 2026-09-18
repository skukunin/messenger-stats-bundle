<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

final class DoctrineTransportSettings
{
    public function __construct(
        public readonly string $connectionName,
        public readonly string $tableName,
        public readonly string $queueName,
        public readonly int $redeliverTimeout,
        public readonly bool $autoSetup,
    ) {
    }
}
