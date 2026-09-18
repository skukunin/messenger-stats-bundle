<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
