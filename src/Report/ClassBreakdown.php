<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class ClassBreakdown
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public readonly array $counts,
        public readonly bool $sampled,
    ) {
    }
}
