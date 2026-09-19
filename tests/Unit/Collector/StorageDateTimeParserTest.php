<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\StorageDateTimeParser;

final class StorageDateTimeParserTest extends TestCase
{
    public function testATimeWithoutAZoneIsReadInTheStorageTimezoneWhateverTheProcessTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $parsed = (new StorageDateTimeParser('UTC'))->parse('2026-09-18 08:00:00');
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($parsed);
        self::assertSame('2026-09-18T08:00:00+00:00', $parsed->format(\DATE_RFC3339));
    }

    public function testASummerTimeStoredInBerlinIsReturnedAsUtc(): void
    {
        $parsed = (new StorageDateTimeParser('Europe/Berlin'))->parse('2026-07-01 12:00:00');

        self::assertNotNull($parsed);
        self::assertSame('2026-07-01T10:00:00+00:00', $parsed->format(\DATE_RFC3339));
    }

    public function testAWinterTimeStoredInBerlinIsReturnedAsUtc(): void
    {
        $parsed = (new StorageDateTimeParser('Europe/Berlin'))->parse('2026-01-15 12:00:00');

        self::assertNotNull($parsed);
        self::assertSame('2026-01-15T11:00:00+00:00', $parsed->format(\DATE_RFC3339));
    }

    public function testAZoneCarriedByTheValueWinsOverTheStorageTimezone(): void
    {
        $parsed = (new StorageDateTimeParser('Europe/Berlin'))->parse('2026-01-02T06:22:33-05:00');

        self::assertNotNull($parsed);
        self::assertSame('2026-01-02T11:22:33+00:00', $parsed->format(\DATE_RFC3339));
    }

    public function testAValueThatIsNotATimeYieldsNull(): void
    {
        self::assertNull((new StorageDateTimeParser('UTC'))->parse('not a date'));
    }

    public function testAnInstantIsMovedToTheStorageTimezone(): void
    {
        $stored = (new StorageDateTimeParser('Europe/Berlin'))->toStorageTimezone(new DateTimeImmutable('2026-07-01T10:00:00', new DateTimeZone('UTC')));

        self::assertSame('2026-07-01 12:00:00', $stored->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Berlin', $stored->getTimezone()->getName());
    }
}
