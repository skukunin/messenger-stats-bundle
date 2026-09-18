<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

use DateTimeImmutable;

final class StatsReport
{
    public const SCHEMA_VERSION = '1';

    /**
     * @param list<Problem>        $problems
     * @param list<TransportStats> $transports
     */
    public function __construct(
        public readonly DateTimeImmutable $generatedAt,
        public readonly string $app,
        public readonly string $env,
        public readonly string $bundleVersion,
        public readonly HealthStatus $status,
        public readonly array $problems,
        public readonly array $transports,
    ) {
    }
}
