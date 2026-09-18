<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class QueueStats
{
    /**
     * @param array<string, int> $classBreakdown
     */
    public function __construct(
        public readonly string $name,
        public readonly int $pending,
        public readonly int $delayed,
        public readonly int $inProgress,
        public readonly int $stuck,
        public readonly ?int $oldestPendingAgeSeconds,
        public readonly array $classBreakdown,
        public readonly bool $classBreakdownSampled,
    ) {
    }

    public function total(): int
    {
        return $this->pending + $this->delayed + $this->inProgress + $this->stuck;
    }
}
