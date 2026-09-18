<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use Skukunin\MessengerStatsBundle\Collector\StatsCollector;
use Skukunin\MessengerStatsBundle\Exception\NoCollectorForTransportException;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

final class FixedStatsCollector implements StatsCollector
{
    /**
     * @param list<TransportStats> $stats
     */
    public function __construct(
        private readonly array $stats,
    ) {
    }

    public function supports(TransportDefinition $definition): bool
    {
        return true;
    }

    public function collect(TransportDefinition $definition): TransportStats
    {
        foreach ($this->stats as $stats) {
            if ($stats->name === $definition->name) {
                return $stats;
            }
        }

        throw new NoCollectorForTransportException($definition->name, $definition->kind);
    }
}
