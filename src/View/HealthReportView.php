<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\View;

use Skukunin\MessengerStatsBundle\Report\StatsReport;

final class HealthReportView
{
    public function __construct(
        private readonly ProblemView $problems,
    ) {
    }

    /**
     * @return array{status: string, problems: list<array{transport: string, metric: string, value: int, threshold: int, level: string}>}
     */
    public function render(StatsReport $report): array
    {
        return [
            'status' => $report->status->value,
            'problems' => $this->problems->render($report->problems),
        ];
    }
}
