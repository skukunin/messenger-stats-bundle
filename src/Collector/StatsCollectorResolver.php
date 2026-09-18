<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use Skukunin\MessengerStatsBundle\Exception\NoCollectorForTransportException;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

final class StatsCollectorResolver
{
    /**
     * @param iterable<StatsCollector> $collectors
     */
    public function __construct(
        private readonly iterable $collectors,
    ) {
    }

    public function resolve(TransportDefinition $definition): StatsCollector
    {
        foreach ($this->collectors as $collector) {
            if ($collector->supports($definition)) {
                return $collector;
            }
        }

        throw new NoCollectorForTransportException($definition->name, $definition->kind);
    }
}
