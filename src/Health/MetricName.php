<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Health;

enum MetricName: string
{
    case Pending = 'pending';
    case Delayed = 'delayed';
    case InProgress = 'in_progress';
    case Stuck = 'stuck';
    case OldestPendingAgeSeconds = 'oldest_pending_age_seconds';
    case Failed = 'failed';
    case Count = 'count';
    case Up = 'up';

    /**
     * @return list<self>
     */
    public static function reportedForFailureTransport(): array
    {
        return [self::Failed, self::Count];
    }

    public function isReportedForFailureTransport(): bool
    {
        return \in_array($this, self::reportedForFailureTransport(), true);
    }
}
