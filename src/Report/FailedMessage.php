<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

use DateTimeImmutable;

final class FailedMessage
{
    public function __construct(
        public readonly string $messageClass,
        public readonly ?string $exceptionClass,
        public readonly ?string $exceptionMessage,
        public readonly ?DateTimeImmutable $failedAt,
        public readonly int $retryCount,
        public readonly ?string $originalTransport,
    ) {
    }
}
