<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Health;

use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;

final class EvaluationResult
{
    /**
     * @param list<Problem> $problems
     */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly array $problems,
    ) {
    }
}
