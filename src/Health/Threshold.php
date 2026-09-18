<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Health;

use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;

final class Threshold
{
    public function __construct(
        public readonly MetricName $metric,
        public readonly ?int $warning,
        public readonly ?int $critical,
    ) {
    }

    public function problemFor(string $transport, int $value): ?Problem
    {
        if (null !== $this->critical && $value >= $this->critical) {
            return new Problem($transport, $this->metric->value, $value, $this->critical, ProblemLevel::Critical);
        }

        if (null !== $this->warning && $value >= $this->warning) {
            return new Problem($transport, $this->metric->value, $value, $this->warning, ProblemLevel::Warning);
        }

        return null;
    }
}
