<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Health;

final class ThresholdSet
{
    /**
     * @param array<string, array<string, array{warning: ?int, critical: ?int}>> $thresholds
     */
    public function __construct(
        private readonly array $thresholds,
    ) {
    }

    public function hasThresholdsFor(string $transport): bool
    {
        return [] !== $this->thresholdsFor($transport);
    }

    /**
     * @return list<Threshold>
     */
    public function thresholdsFor(string $transport): array
    {
        $thresholds = [];
        foreach ($this->thresholds[$transport] ?? [] as $metric => $levels) {
            $thresholds[] = new Threshold(MetricName::from($metric), $levels['warning'], $levels['critical']);
        }

        return $thresholds;
    }
}
