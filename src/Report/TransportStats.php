<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class TransportStats
{
    /**
     * @param list<QueueStats>    $queues
     * @param list<FailedMessage> $failures
     */
    private function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly DetailLevel $detailLevel,
        public readonly bool $isFailureTransport,
        public readonly ?int $count,
        public readonly array $queues,
        public readonly array $failures,
        public readonly ?ClassBreakdown $classBreakdown,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param list<QueueStats> $queues
     */
    public static function full(string $name, string $kind, array $queues): self
    {
        return new self($name, $kind, DetailLevel::Full, false, self::totalOf($queues), $queues, [], null, null);
    }

    /**
     * @param list<QueueStats> $queues
     */
    private static function totalOf(array $queues): int
    {
        return array_sum(array_map(static fn (QueueStats $queue): int => $queue->total(), $queues));
    }

    /**
     * @param list<FailedMessage> $failures
     */
    public static function failure(string $name, string $kind, int $count, ClassBreakdown $classBreakdown, array $failures): self
    {
        return new self($name, $kind, DetailLevel::Full, true, $count, [], $failures, $classBreakdown, null);
    }

    public static function countOnly(string $name, string $kind, bool $isFailureTransport, ?int $count): self
    {
        return new self($name, $kind, DetailLevel::Count, $isFailureTransport, $count, [], [], null, null);
    }

    public static function unavailable(string $name, string $kind, bool $isFailureTransport, string $error): self
    {
        return new self($name, $kind, DetailLevel::Unavailable, $isFailureTransport, null, [], [], null, $error);
    }

    public function totalPending(): int
    {
        return $this->sumOf(static fn (QueueStats $queue): int => $queue->pending);
    }

    /**
     * @param callable(QueueStats): int $value
     */
    private function sumOf(callable $value): int
    {
        return array_sum(array_map($value, $this->queues));
    }

    public function totalDelayed(): int
    {
        return $this->sumOf(static fn (QueueStats $queue): int => $queue->delayed);
    }

    public function totalInProgress(): int
    {
        return $this->sumOf(static fn (QueueStats $queue): int => $queue->inProgress);
    }

    public function totalStuck(): int
    {
        return $this->sumOf(static fn (QueueStats $queue): int => $queue->stuck);
    }

    public function maxOldestPendingAgeSeconds(): ?int
    {
        $ages = [];
        foreach ($this->queues as $queue) {
            if (null !== $queue->oldestPendingAgeSeconds) {
                $ages[] = $queue->oldestPendingAgeSeconds;
            }
        }

        return [] === $ages ? null : max($ages);
    }

    public function failedCount(): ?int
    {
        return $this->isFailureTransport ? $this->count : null;
    }
}
