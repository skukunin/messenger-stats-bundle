<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Health;

use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class ThresholdEvaluator
{
    public function __construct(
        private readonly ThresholdSet $thresholds,
    ) {
    }

    /**
     * @param list<TransportStats> $transports
     */
    public function evaluate(array $transports): EvaluationResult
    {
        $problems = [];
        foreach ($transports as $transport) {
            foreach ($this->problemsOf($transport) as $problem) {
                $problems[] = $problem;
            }
        }

        return new EvaluationResult($this->worstStatus($problems), $problems);
    }

    /**
     * @return list<Problem>
     */
    private function problemsOf(TransportStats $transport): array
    {
        if (DetailLevel::Unavailable === $transport->detailLevel) {
            return [$this->unavailableProblem($transport)];
        }

        $problems = [];
        foreach ($this->thresholds->thresholdsFor($transport->name) as $threshold) {
            $value = $this->valueOf($transport, $threshold->metric);
            $problem = null === $value ? null : $threshold->problemFor($transport->name, $value);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private function unavailableProblem(TransportStats $transport): Problem
    {
        $level = $this->thresholds->hasThresholdsFor($transport->name) ? ProblemLevel::Critical : ProblemLevel::Warning;

        return new Problem($transport->name, MetricName::Up->value, 0, 1, $level);
    }

    private function valueOf(TransportStats $transport, MetricName $metric): ?int
    {
        if (DetailLevel::Count === $transport->detailLevel) {
            return MetricName::Count === $metric ? $transport->count : null;
        }

        if ($transport->isFailureTransport && !$metric->isReportedForFailureTransport()) {
            return null;
        }

        return match ($metric) {
            MetricName::Pending => $transport->totalPending(),
            MetricName::Delayed => $transport->totalDelayed(),
            MetricName::InProgress => $transport->totalInProgress(),
            MetricName::Stuck => $transport->totalStuck(),
            MetricName::OldestPendingAgeSeconds => $transport->maxOldestPendingAgeSeconds(),
            MetricName::Failed => $transport->failedCount(),
            MetricName::Count => $transport->count,
            MetricName::Up => null,
        };
    }

    /**
     * @param list<Problem> $problems
     */
    private function worstStatus(array $problems): HealthStatus
    {
        $status = HealthStatus::Ok;
        foreach ($problems as $problem) {
            $status = $status->worst($problem->level->toHealthStatus());
        }

        return $status;
    }
}
