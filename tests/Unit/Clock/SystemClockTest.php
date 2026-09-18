<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Clock\SystemClock;
use Skukunin\MessengerStatsBundle\Tests\Support\FixedClock;

final class SystemClockTest extends TestCase
{
    public function testNowIsUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testNowIsTheCurrentTime(): void
    {
        self::assertEqualsWithDelta(time(), (new SystemClock())->now()->getTimestamp(), 5);
    }

    public function testFixedClockAlwaysReturnsTheSameInstant(): void
    {
        $instant = new DateTimeImmutable('2026-09-18T10:00:00+00:00');
        $clock = new FixedClock($instant);

        self::assertSame($instant, $clock->now());
    }
}
