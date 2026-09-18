<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Tests\Support\StatsReportFixture;
use Skukunin\MessengerStatsBundle\View\HealthReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;

final class HealthReportViewTest extends TestCase
{
    public function testTheStatusIsRenderedWithEveryProblem(): void
    {
        self::assertSame([
            'status' => 'critical',
            'problems' => [
                [
                    'transport' => 'async_payments',
                    'metric' => 'oldest_pending_age_seconds',
                    'value' => 900,
                    'threshold' => 600,
                    'level' => 'critical',
                ],
            ],
        ], $this->view()->render(StatsReportFixture::specExample()));
    }

    private function view(): HealthReportView
    {
        return new HealthReportView(new ProblemView());
    }

    public function testAHealthyReportRendersAnEmptyProblemList(): void
    {
        self::assertSame(['status' => 'ok', 'problems' => []], $this->view()->render(StatsReportFixture::healthy()));
    }
}
