<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use Psr\Container\ContainerInterface;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportKind;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class CountOnlyStatsCollector implements StatsCollector
{
    public function __construct(
        private readonly ContainerInterface $transports,
    ) {
    }

    public function supports(TransportDefinition $definition): bool
    {
        return TransportKind::DOCTRINE !== $definition->kind;
    }

    public function collect(TransportDefinition $definition): TransportStats
    {
        return TransportStats::countOnly($definition->name, $definition->kind, $definition->isFailureTransport, $this->messageCountOf($definition->name));
    }

    private function messageCountOf(string $name): ?int
    {
        if (!$this->transports->has($name)) {
            return null;
        }

        $transport = $this->transports->get($name);

        return $transport instanceof MessageCountAwareInterface ? $transport->getMessageCount() : null;
    }
}
