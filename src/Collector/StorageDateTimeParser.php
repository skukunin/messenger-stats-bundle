<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class StorageDateTimeParser
{
    public function __construct(
        private readonly string $storageTimezone,
    ) {
    }

    public function parse(string $value): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($this->storageTimezone)))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    public function toStorageTimezone(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone($this->storageTimezone));
    }
}
