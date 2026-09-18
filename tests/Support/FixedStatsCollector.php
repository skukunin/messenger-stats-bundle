<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use Skukunin\MessengerStatsBundle\Collector\StatsCollector;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

final class FixedStatsCollector implements StatsCollector
{
    public function __construct(
        private readonly TransportStats $stats,
    ) {
    }

    public function supports(TransportDefinition $definition): bool
    {
        return true;
    }

    public function collect(TransportDefinition $definition): TransportStats
    {
        return $this->stats;
    }
}
