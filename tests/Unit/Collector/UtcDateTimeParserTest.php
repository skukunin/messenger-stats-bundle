<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\UtcDateTimeParser;

final class UtcDateTimeParserTest extends TestCase
{
    public function testATimeWithoutAZoneIsReadAsUtc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $parsed = (new UtcDateTimeParser())->parse('2026-09-18 08:00:00');
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($parsed);
        self::assertSame('2026-09-18T08:00:00+00:00', $parsed->format(\DATE_RFC3339));
    }

    public function testAZoneCarriedByTheValueIsKept(): void
    {
        $parsed = (new UtcDateTimeParser())->parse('2026-01-02T06:22:33-05:00');

        self::assertNotNull($parsed);
        self::assertSame('2026-01-02T06:22:33-05:00', $parsed->format(\DATE_RFC3339));
    }

    public function testAValueThatIsNotATimeYieldsNull(): void
    {
        self::assertNull((new UtcDateTimeParser())->parse('not a date'));
    }
}
