<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

enum ProblemLevel: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function toHealthStatus(): HealthStatus
    {
        return match ($this) {
            self::Warning => HealthStatus::Warning,
            self::Critical => HealthStatus::Critical,
        };
    }
}
