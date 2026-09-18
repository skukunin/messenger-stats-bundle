<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function worst(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }
}
