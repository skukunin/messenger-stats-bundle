<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

use Psr\Log\LoggerInterface;
use Skukunin\MessengerStatsBundle\BundleVersion;
use Skukunin\MessengerStatsBundle\Clock\Clock;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Throwable;

final class StatsReportBuilder
{
    public function __construct(
        private readonly TransportDefinitionRegistry $transports,
        private readonly StatsCollectorResolver $collectors,
        private readonly ThresholdEvaluator $thresholds,
        private readonly Clock $clock,
        private readonly ApplicationIdentity $application,
        private readonly BundleVersion $bundleVersion,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function build(): StatsReport
    {
        $transports = array_map($this->statsOf(...), $this->transports->all());
        $evaluation = $this->thresholds->evaluate($transports);

        return new StatsReport(
            $this->clock->now(),
            $this->application->name,
            $this->application->env,
            $this->bundleVersion->version(),
            $evaluation->status,
            $evaluation->problems,
            $transports,
        );
    }

    private function statsOf(TransportDefinition $definition): TransportStats
    {
        try {
            return $this->collectors->resolve($definition)->collect($definition);
        } catch (Throwable $failure) {
            return $this->unavailableStatsOf($definition, $failure);
        }
    }

    private function unavailableStatsOf(TransportDefinition $definition, Throwable $failure): TransportStats
    {
        $this->logger?->error('Messenger stats collection failed for transport "{transport}": {message}', [
            'transport' => $definition->name,
            'message' => $failure->getMessage(),
            'exception' => $failure,
        ]);

        return TransportStats::unavailable($definition->name, $definition->kind, $definition->isFailureTransport, $failure::class);
    }
}
