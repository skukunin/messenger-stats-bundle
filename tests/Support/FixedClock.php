<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use DateTimeImmutable;
use Skukunin\MessengerStatsBundle\Clock\Clock;

final class FixedClock implements Clock
{
    public function __construct(
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
