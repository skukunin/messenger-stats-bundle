<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;

interface StatsCollector
{
    public function supports(TransportDefinition $definition): bool;

    public function collect(TransportDefinition $definition): TransportStats;
}
