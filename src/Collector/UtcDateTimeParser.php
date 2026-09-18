<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class UtcDateTimeParser
{
    public function parse(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
