<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class Problem
{
    public function __construct(
        public readonly string $transport,
        public readonly string $metric,
        public readonly int $value,
        public readonly int $threshold,
        public readonly ProblemLevel $level,
    ) {
    }
}
