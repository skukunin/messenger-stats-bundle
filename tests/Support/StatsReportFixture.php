<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use DateTimeImmutable;
use Skukunin\MessengerStatsBundle\Report\ClassBreakdown;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\ProblemLevel;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class StatsReportFixture
{
    public const GENERATED_AT = '2026-09-18T12:00:00+02:00';
    public const GENERATED_AT_UTC = '2026-09-18T10:00:00+00:00';
    public const FAILED_AT_UTC = '2026-09-17T10:00:00+00:00';
    public const BUNDLE_VERSION = '0.1.0';
    public const APP = 'shop';
    public const ENV = 'prod';

    public static function specExample(): StatsReport
    {
        return new StatsReport(
            new DateTimeImmutable(self::GENERATED_AT),
            self::APP,
            self::ENV,
            self::BUNDLE_VERSION,
            HealthStatus::Critical,
            [new Problem('async_payments', 'oldest_pending_age_seconds', 900, 600, ProblemLevel::Critical)],
            [
                TransportStats::full('async_payments', 'doctrine', [
                    new QueueStats('payments', 42, 3, 1, 0, 900, ['App\Message\RecurringPaymentMessage' => 46], false),
                ]),
                TransportStats::failure('failed', 'doctrine', 7, new ClassBreakdown(['App\Message\SendEmail' => 7], false), [
                    new FailedMessage(
                        'App\Message\SendEmail',
                        'Symfony\Component\Mailer\Exception\TransportException',
                        'Connection refused',
                        new DateTimeImmutable(self::FAILED_AT_UTC),
                        3,
                        'async',
                    ),
                ]),
                TransportStats::countOnly('events', 'amqp', false, 12),
                TransportStats::unavailable('reporting', 'doctrine', false, 'Doctrine\DBAL\Exception\ConnectionException'),
            ],
        );
    }

    public static function healthy(): StatsReport
    {
        return new StatsReport(
            new DateTimeImmutable(self::GENERATED_AT),
            self::APP,
            self::ENV,
            self::BUNDLE_VERSION,
            HealthStatus::Ok,
            [],
            [TransportStats::countOnly('events', 'amqp', false, 12)],
        );
    }

    /**
     * @param list<TransportStats> $transports
     */
    public static function of(HealthStatus $status, array $transports): StatsReport
    {
        return new StatsReport(
            new DateTimeImmutable(self::GENERATED_AT),
            self::APP,
            self::ENV,
            self::BUNDLE_VERSION,
            $status,
            [],
            $transports,
        );
    }
}
