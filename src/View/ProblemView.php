<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\View;

use Skukunin\MessengerStatsBundle\Report\Problem;

final class ProblemView
{
    /**
     * @param list<Problem> $problems
     *
     * @return list<array{transport: string, metric: string, value: int, threshold: int, level: string}>
     */
    public function render(array $problems): array
    {
        return array_map($this->problemOf(...), $problems);
    }

    /**
     * @return array{transport: string, metric: string, value: int, threshold: int, level: string}
     */
    private function problemOf(Problem $problem): array
    {
        return [
            'transport' => $problem->transport,
            'metric' => $problem->metric,
            'value' => $problem->value,
            'threshold' => $problem->threshold,
            'level' => $problem->level->value,
        ];
    }
}
